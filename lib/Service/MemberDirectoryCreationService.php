<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Service;

use InvalidArgumentException;
use LogicException;
use OCA\ProfileFields\Db\FieldDefinition;
use OCA\Settings\Mailer\NewUserMailHelper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Defaults;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\Util;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Creates new member accounts without allowing privileged group assignment.
 * Uses Nextcloud 35's welcome mail with a password setup link; passwords are
 * neither supplied by nor returned to the member directory frontend.
 */
class MemberDirectoryCreationService {
	public function __construct(
		private MemberDirectoryConfig $config,
		private MemberDirectoryAccessService $access,
		private IUserManager $users,
		private IGroupManager $groups,
		private FieldDefinitionService $definitions,
		private FieldValueService $values,
		private MemberNumberService $memberNumbers,
		private ILockingProvider $locks,
		private ISecureRandom $random,
		private Defaults $defaults,
		private IURLGenerator $urls,
		private IFactory $l10nFactory,
		private IMailer $mailer,
		private ITimeFactory $timeFactory,
		private IConfig $nextcloudConfig,
		private ICrypto $crypto,
		private LoggerInterface $logger,
	) {
	}

	/** @return list<string> */
	public function availableDepartments(?string $actorUid): array {
		if (!$this->access->canCreate($actorUid)) {
			return [];
		}
		$protected = array_merge(
			['admin', $this->config->baseGroup()],
			$this->config->readGroups(),
			$this->config->writeGroups(),
			$this->config->createGroups(),
		);
		return array_values(array_filter($this->config->departments(), function (string $gid) use ($protected): bool {
			if (in_array($gid, $protected, true)) {
				return false;
			}
			$group = $this->groups->get($gid);
			return $group !== null && $group->getGID() === $gid && $group->canAddUser();
		}));
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array{user_uid: string, invitation_sent: bool}
	 */
	public function create(
		string $actorUid,
		string $userUid,
		string $firstName,
		string $lastName,
		string $displayName,
		string $email,
		string $department,
		array $fields,
	): array {
		if (!$this->access->canCreate($actorUid)) {
			throw new LogicException('Keine Berechtigung zum Anlegen neuer Mitglieder.');
		}
		$userUid = trim($userUid);
		$firstName = $this->name($firstName, 'Vorname');
		$lastName = $this->name($lastName, 'Nachname');
		$displayName = $this->name($displayName, 'Anzeigename');
		$email = mb_strtolower(trim($email));
		if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new InvalidArgumentException('Bitte eine gültige E-Mail-Adresse für die Einladung angeben.');
		}
		if (!self::isSupportedUserId($userUid)) {
			throw new InvalidArgumentException('Kontoname: 1 bis 64 Zeichen. Buchstaben, Ziffern, Leerzeichen und _.@\'- sind erlaubt.');
		}
		$this->users->validateUserId($userUid, true);
		$baseId = $this->config->baseGroup();
		if ($baseId === null || !in_array($department, $this->availableDepartments($actorUid), true)) {
			throw new InvalidArgumentException('Die Basisgruppe oder ausgewählte Abteilung ist nicht zur Neuanlage freigegeben.');
		}
		$base = $this->checkedGroup($baseId);
		$destination = $this->checkedGroup($department);
		if ($baseId === $department) {
			throw new InvalidArgumentException('Die Abteilung darf nicht die Basisgruppe sein.');
		}
		if (array_is_list($fields) && $fields !== []) {
			throw new InvalidArgumentException('Profilfelder müssen als benannte Felder übergeben werden.');
		}
		// One source of truth for given and family name at account creation.
		$fields['vereins_vorname'] = $firstName;
		$fields['vereins_nachname'] = $lastName;
		$numberInput = $fields[MemberNumberService::FIELD_KEY] ?? null;
		$autoNumber = $numberInput === null || $numberInput === '';
		if ($autoNumber) {
			unset($fields[MemberNumberService::FIELD_KEY]);
		}
		if (count($fields) > 100) {
			throw new InvalidArgumentException('Zu viele Profilfelder.');
		}

		/** @var list<array{0: FieldDefinition, 1: mixed}> $prepared */
		$prepared = [];
		foreach ($fields as $key => $raw) {
			if (!is_string($key)) {
				throw new InvalidArgumentException('Ungültiger Profilfeldschlüssel.');
			}
			$definition = $this->definitions->findByFieldKey($key);
			if ($definition === null || !$this->access->canWriteField($actorUid, $definition)) {
				throw new LogicException('Keine Schreibberechtigung für das Profilfeld: ' . $key);
			}
			if ($raw === '' || $raw === null || $raw === []) {
				continue;
			}
			if (!is_array($raw) && !is_string($raw) && !is_int($raw) && !is_float($raw) && !is_bool($raw)) {
				throw new InvalidArgumentException('Ungültiger Feldwert: ' . $key);
			}
			$this->values->normalizeValue($definition, $raw);
			$prepared[] = [$definition, $raw];
		}

		// One exclusive lock protects concurrent account and member-number creation.
		$lockKey = 'profile_fields:member_directory:create';
		$this->locks->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			if (!$this->access->canCreate($actorUid)
				|| !in_array($department, $this->availableDepartments($actorUid), true)) {
				throw new LogicException('Die Berechtigung zur Neuanlage wurde inzwischen geändert.');
			}
			if ($this->users->userExists($userUid)) {
				throw new MemberDirectoryConflictException('Der Kontoname ist bereits vergeben.');
			}
			$this->users->validateUserId($userUid, true);
			if ($autoNumber) {
				$numberDefinition = $this->memberNumbers->writableDefinition($actorUid);
				$next = $this->memberNumbers->suggest($actorUid);
				if ($numberDefinition === null || $next === null) {
					throw new LogicException('Die Mitgliedsnummer kann nicht automatisch vergeben werden.');
				}
				$this->values->normalizeValue($numberDefinition, $next);
				$prepared[] = [$numberDefinition, $next];
			}
			foreach ($prepared as [$definition, $raw]) {
				if (!$this->access->canWriteField($actorUid, $definition)) {
					throw new LogicException('Die Feldberechtigungen wurden inzwischen geändert.');
				}
				if ($definition->getFieldKey() === MemberNumberService::FIELD_KEY) {
					$this->memberNumbers->assertAvailable($definition, $raw);
				}
			}
			$password = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC)
				. $this->random->generate(1, ISecureRandom::CHAR_UPPER)
				. $this->random->generate(1, ISecureRandom::CHAR_LOWER)
				. $this->random->generate(1, ISecureRandom::CHAR_DIGITS)
				. $this->random->generate(1, ISecureRandom::CHAR_SYMBOLS);

			$created = null;
			try {
				$created = $this->users->createUser($userUid, $password);
				if (!$created instanceof IUser || $created->getUID() !== $userUid) {
					throw new RuntimeException('Nextcloud konnte das Konto nicht erstellen.');
				}
				if (!$created->canChangeDisplayName() || !$created->setDisplayName($displayName)) {
					throw new RuntimeException('Der Anzeigename konnte nicht übernommen werden.');
				}
				$created->setSystemEMailAddress($email);
				foreach ([$base, $destination] as $group) {
					$group->addUser($created);
					if (!$this->groups->isInGroup($userUid, $group->getGID())) {
						throw new RuntimeException('Die Gruppenzuordnung wurde nicht übernommen.');
					}
				}
				foreach ($prepared as [$definition, $raw]) {
					$this->values->upsert($definition, $userUid, $raw, $actorUid);
				}
			} catch (Throwable $error) {
				if ($created instanceof IUser) {
					try {
						if (!$created->delete()) {
							throw new RuntimeException('Nextcloud konnte das unvollständige Konto nicht zurücknehmen.');
						}
					} catch (Throwable $rollbackError) {
						$this->logger->error('Member creation rollback failed', [
							'uid' => $userUid, 'exception' => $rollbackError,
						]);
					}
				}
				$this->logger->error('Member creation failed before invitation', ['uid' => $userUid, 'exception' => $error]);
				throw new RuntimeException('Das Konto konnte nicht vollständig angelegt werden. Bitte in der Benutzerverwaltung kontrollieren.', 0, $error);
			}
			$sent = false;
			try {
				// Reuse the same invitation implementation as Nextcloud's provisioning API.
				$helper = new NewUserMailHelper(
					$this->defaults, $this->urls, $this->l10nFactory,
					$this->mailer, $this->random, $this->timeFactory,
					$this->nextcloudConfig, $this->crypto,
					Util::getDefaultEmailAddress('no-reply'),
				);
				$helper->sendMail($created, $helper->generateTemplate($created, true));
				$sent = true;
			} catch (Throwable $error) {
				$this->logger->error('Member created, but invitation could not be sent', [
					'uid' => $userUid, 'exception' => $error,
				]);
			}
			$this->logger->info('Member created via member directory', [
				'actor' => $actorUid, 'uid' => $userUid, 'invitation_sent' => $sent,
			]);
			return ['user_uid' => $userUid, 'invitation_sent' => $sent];
		} finally {
			$this->locks->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Nextcloud supports spaces and @ in account IDs. Keep a conservative
	 * public-facing allow-list, then delegate the definitive validation to
	 * IUserManager::validateUserId() before creating the account.
	 */
	public static function isSupportedUserId(string $uid): bool {
		return $uid !== ''
			&& mb_strlen($uid) <= 64
			&& strlen($uid) <= IUser::MAX_USERID_LENGTH
			&& preg_match('/^[\\p{L}\\p{N} _.@\\x{27}-]+$/uD', $uid) === 1;
	}

	private function checkedGroup(string $gid): IGroup {
		$group = $this->groups->get($gid);
		if ($group === null || $group->getGID() !== $gid || !$group->canAddUser()) {
			throw new InvalidArgumentException('Die erforderliche Gruppe ist nicht verfügbar oder schreibgeschützt.');
		}
		return $group;
	}

	private function name(string $name, string $label): string {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 255 || !mb_check_encoding($name, 'UTF-8')
			|| preg_match('/\p{Cc}/u', $name) === 1) {
			throw new InvalidArgumentException($label . ': Bitte 1 bis 255 Zeichen ohne Steuerzeichen angeben.');
		}
		return $name;
	}
}
