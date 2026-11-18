<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Service;

use InvalidArgumentException;
use LogicException;
use OCP\Accounts\IAccountManager;
use OCP\Group\ISubAdmin;
use OCP\IGroup;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IURLGenerator;
use OCP\Defaults;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Security\VerificationToken\IVerificationToken;
use OCP\Lock\ILockingProvider;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class MemberDirectoryAccountService {
	public function __construct(
		private MemberDirectoryConfig $config,
		private MemberDirectoryAccessService $access,
		private IGroupManager $groups,
		private ISubAdmin $subAdmins,
		private IUserManager $users,
		private ILockingProvider $locks,
		private LoggerInterface $logger,
		private IAccountManager $accountManager,
		private IConfig $systemConfig,
		private IVerificationToken $verificationToken,
		private IURLGenerator $urlGenerator,
		private IMailer $mailer,
		private Defaults $defaults,
		private IL10N $l10n,
	) {
	}

	/**
	 * Native Nextcloud account data, not duplicated profile_fields entries.
	 * Birth dates are deliberately withheld from directory-only readers.
	 *
	 * @return array{email: string, birthdate: string, can_edit_email: bool, can_edit_birthdate: bool, can_reset_password: bool}
	 */
	public function accountDetails(?string $actorUid, string $targetUid): array {
		$empty = ['email' => '', 'birthdate' => '', 'can_edit_email' => false,
			'can_edit_birthdate' => false, 'can_reset_password' => false];
		if (!$this->access->canRead($actorUid) || !$this->access->isMember($targetUid)) {
			return $empty;
		}
		$user = $this->users->get($targetUid);
		if ($user === null || $user->getUID() !== $targetUid) {
			return $empty;
		}
		$email = (string)($user->getEMailAddress() ?? '');
		$canManage = $actorUid !== null && $this->canEditAccount($actorUid, $targetUid);
		$canEditEmail = $canManage && $actorUid !== null
			&& $this->groups->isAdmin($actorUid)
			&& $user->canChangeEmail()
			&& ($user->getPrimaryEMailAddress() === null || $user->getPrimaryEMailAddress() === '');
		$canEditBirthdate = $canManage && $user->canEditProperty(IAccountManager::PROPERTY_BIRTHDATE);
		$birthdate = '';
		if ($canManage) {
			$account = $this->accountManager->getAccount($user);
			$birthdate = self::dateValue($account->getProperty(IAccountManager::PROPERTY_BIRTHDATE)->getValue());
		}
		// Requesting a reset always uses Nextcloud's existing lost-password flow.
		// Never allow a directory reader to change a user's email or password.
		$canReset = $actorUid !== null && $email !== '' && $user->isEnabled()
			&& $user->canChangePassword()
			&& !$this->groups->isAdmin($targetUid)
			&& !$this->groups->isDelegatedAdmin($targetUid)
			&& $this->systemConfig->getSystemValue('lost_password_link', '') === '';

		return [
			'email' => $email,
			'birthdate' => $birthdate,
			'can_edit_email' => $canEditEmail,
			'can_edit_birthdate' => $canEditBirthdate,
			'can_reset_password' => $canReset,
		];
	}

	/**
	 * Mail an existing, enabled, non-privileged local member their own
	 * Nextcloud password-reset link, never a password or reset token to
	 * the requesting reader. Target address is read freshly on server.
	 *
	 * The per-member throttle applies across all callers. It is not affected
	 * by changing the displayed email in the browser.
	 */
	public function requestPasswordReset(string $actorUid, string $targetUid): void {
		if (!$this->access->canRead($actorUid)) {
			throw new LogicException('Keine Leseberechtigung für die Mitgliederliste.');
		}
		$key = 'profile_fields:member_directory:reset:' . hash('sha256', $targetUid);
		$this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$info = $this->accountDetails($actorUid, $targetUid);
			if (!$info['can_reset_password']) {
				throw new LogicException('Passwort-Zurücksetzung für dieses Konto nicht verfügbar.');
			}
			$user = $this->users->get($targetUid);
			if ($user === null || $user->getUID() !== $targetUid) {
				throw new LogicException('Passwort-Zurücksetzung nicht verfügbar.');
			}
			$lastKey = 'member_directory.password_reset_last.' . hash('sha256', $targetUid);
			$last = (int)$this->systemConfig->getAppValue('profile_fields', $lastKey, '0');
			if (time() - $last < 1800) {
				throw new LogicException('Für dieses Mitglied wurde kürzlich bereits eine Reset-Mail angefordert. Bitte später erneut versuchen.');
			}
			$email = $user->getEMailAddress();
			if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
				throw new LogicException('Keine gültige E-Mail-Adresse für dieses Mitglied hinterlegt.');
			}

			// Matches Nextcloud 35's core lost-password token semantics:
			// subject=lostpassword, bound to the currently saved email.
			$token = $this->verificationToken->create($user, 'lostpassword', $email);
			try {
				$link = $this->urlGenerator->linkToRouteAbsolute('core.lost.resetform',
					['userId' => $user->getUID(), 'token' => $token]);
				$template = $this->mailer->createEMailTemplate('profile_fields.MemberPasswordReset',
					['link' => $link]);
				$template->setSubject($this->l10n->t('%s password reset', [$this->defaults->getName()]));
				$template->addHeader();
				$template->addHeading($this->l10n->t('Password reset'));
				$template->addBodyText(
					'Für dein Vereins-Nextcloud-Konto wurde eine Passwortzurücksetzung angefordert. Falls du das nicht möchtest, ignoriere diese E-Mail.',
					'Für dein Vereins-Nextcloud-Konto wurde eine Passwortzurücksetzung angefordert. Falls du das nicht möchtest, ignoriere diese E-Mail.'
				);
				$template->addBodyButton('Passwort selbst zurücksetzen', $link, false);
				$template->addFooter();
				$message = $this->mailer->createMessage();
				$message->setTo([$email => $user->getDisplayName()]);
				$message->setFrom([\OCP\Util::getDefaultEmailAddress('no-reply') => $this->defaults->getName()]);
				$message->useTemplate($template);
				$this->mailer->send($message);
				$this->systemConfig->setAppValue('profile_fields', $lastKey, (string)time());
				$this->logger->info('Member password reset mail requested', [
					'actor_uid' => $actorUid, 'target_uid' => $targetUid,
				]);
			} catch (Throwable $error) {
				$this->verificationToken->delete($token, $user, 'lostpassword');
				throw $error;
			}
		} finally {
			$this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** Only administrators may change an account recovery address. */
	public function updateEmail(string $actorUid, string $uid, string $email, string $expectedEmail): void {
		$this->withMemberLock($actorUid, $uid, function (IUser $user) use ($actorUid, $uid, $email, $expectedEmail): void {
			if (!$this->accountDetails($actorUid, $uid)['can_edit_email']) {
				throw new LogicException('Nur berechtigte Administratoren dürfen die Konto-E-Mail-Adresse ändern.');
			}
			$email = mb_strtolower(trim($email));
			if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
				throw new InvalidArgumentException('Bitte eine gültige E-Mail-Adresse angeben.');
			}
			if (($user->getEMailAddress() ?? '') !== $expectedEmail) {
				throw new MemberDirectoryConflictException('Die E-Mail-Adresse wurde inzwischen geändert. Bitte aktualisieren.');
			}
			if ($email !== $expectedEmail) {
				$user->setSystemEMailAddress($email);
			}
		});
	}

	/**
	 * Native birthdate property. Only directory writers with permission to
	 * manage this member may change it; readonly directory members never see it.
	 */
	public function updateBirthdate(string $actorUid, string $uid, string $birthdate, string $expectedBirthdate): void {
		$this->withMemberLock($actorUid, $uid, function (IUser $user) use ($actorUid, $uid, $birthdate, $expectedBirthdate): void {
			if (!$this->accountDetails($actorUid, $uid)['can_edit_birthdate']) {
				throw new LogicException('Keine Berechtigung zum Bearbeiten des Geburtsdatums.');
			}
			$birthdate = self::validateBirthdate($birthdate);
			$account = $this->accountManager->getAccount($user);
			$property = $account->getProperty(IAccountManager::PROPERTY_BIRTHDATE);
			if (self::dateValue($property->getValue()) !== $expectedBirthdate) {
				throw new MemberDirectoryConflictException('Das Geburtsdatum wurde inzwischen geändert. Bitte aktualisieren.');
			}
			$property->setValue($birthdate === '' ? '' : $birthdate . 'T00:00:00.000Z');
			$this->accountManager->updateAccount($account);
		});
	}

	public static function validateBirthdate(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $matches)
			|| !checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])
			|| $value > gmdate('Y-m-d')) {
			throw new InvalidArgumentException('Bitte ein gültiges Geburtsdatum bis heute angeben.');
		}
		return $value;
	}

	private static function dateValue(?string $value): string {
		$value ??= '';
		return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : '';
	}

	/** @return list<string> */
	public function editableDepartments(?string $actorUid): array {
		if (!$this->access->canWrite($actorUid) || $actorUid === null) {
			return [];
		}

		$isAdmin = $this->groups->isAdmin($actorUid);
		$actor = $this->users->get($actorUid);
		if (!$isAdmin && ($actor === null || !$this->subAdmins->isSubAdmin($actor))) {
			return [];
		}

		$protected = array_merge(['admin', $this->config->baseGroup()], $this->config->readGroups(), $this->config->writeGroups());
		return array_values(array_filter($this->config->departments(), function (string $gid) use ($protected, $isAdmin, $actor): bool {
			if (in_array($gid, $protected, true)) {
				return false;
			}
			$group = $this->groups->get($gid);
			return $group !== null && $group->getGID() === $gid && $group->canAddUser() && $group->canRemoveUser()
				&& ($isAdmin || ($actor !== null && $this->subAdmins->isSubAdminOfGroup($actor, $group)));
		}));
	}

	/**
	 * Department edits must respect Nextcloud's group-specific subadmin scope.
	 *
	 * @param list<string> $currentDepartments
	 */
	public function canChangeDepartment(?string $actorUid, string $targetUid, array $currentDepartments): bool {
		if (!$this->canEditAccount($actorUid, $targetUid) || $actorUid === null
			|| $this->editableDepartments($actorUid) === []) {
			return false;
		}
		if ($this->groups->isAdmin($actorUid)) {
			return true;
		}
		$actor = $this->users->get($actorUid);
		$target = $this->users->get($targetUid);
		if ($actor === null || $target === null || !$this->subAdmins->isUserAccessible($actor, $target)) {
			return false;
		}
		foreach ($currentDepartments as $gid) {
			$group = $this->groups->get($gid);
			if ($group === null || !$this->subAdmins->isSubAdminOfGroup($actor, $group)) {
				return false;
			}
		}
		return true;
	}

	public function canEditAccount(?string $actorUid, string $targetUid): bool {
		return $this->access->canWrite($actorUid)
			&& $actorUid !== null
			&& $this->actorCanManageTarget($actorUid, $targetUid);
	}

	private function actorCanManageTarget(string $actorUid, string $targetUid): bool {
		return $this->groups->isAdmin($actorUid)
			|| (!$this->groups->isAdmin($targetUid) && !$this->groups->isDelegatedAdmin($targetUid));
	}

	public function canEditDisplayName(?string $actorUid, string $targetUid): bool {
		return $this->canEditAccount($actorUid, $targetUid)
			&& $this->users->get($targetUid)?->canChangeDisplayName() === true;
	}

	public function updateDisplayName(string $actorUid, string $uid, string $name, string $expectedName): void {
		$this->withMemberLock($actorUid, $uid, function (IUser $user) use ($name, $expectedName): void {
			$name = trim($name);
			if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 255 || preg_match('/\p{Cc}/u', $name) === 1) {
				throw new InvalidArgumentException('Bitte einen Anzeigenamen mit 1 bis 255 Zeichen ohne Steuerzeichen eingeben.');
			}
			if (!$user->canChangeDisplayName()) {
				throw new LogicException('Das Benutzerverzeichnis erlaubt keine Änderung des Anzeigenamens.');
			}
			$current = $user->getDisplayName();
			if ($current !== $expectedName) {
				throw new MemberDirectoryConflictException('Der Anzeigename wurde inzwischen geändert. Bitte aktualisieren.');
			}
			if ($current !== $name && !$user->setDisplayName($name)) {
				throw new RuntimeException('Nextcloud rejected the display name change.');
			}
		});
	}

	/** @param list<string> $expectedDepartments */
	public function changeDepartment(string $actorUid, string $uid, string $destination, array $expectedDepartments): void {
		$this->withMemberLock($actorUid, $uid, function (IUser $user) use ($actorUid, $destination, $expectedDepartments): void {
			$editable = $this->editableDepartments($actorUid);
			if (!in_array($destination, $editable, true)) {
				throw new LogicException('Für diese Abteilungsgruppe fehlt die Berechtigung oder sie ist schreibgeschützt.');
			}

			$current = $this->memberDepartments($user);
			if (!$this->canChangeDepartment($actorUid, $user->getUID(), $current)) {
				throw new LogicException('Keine Berechtigung zum Verschieben dieses Mitglieds.');
			}
			if (!array_is_list($expectedDepartments) || count($expectedDepartments) > 100
				|| array_filter($expectedDepartments, static fn ($gid): bool => !is_string($gid)) !== []) {
				throw new InvalidArgumentException('Die bisherige Abteilungszuordnung ist ungültig.');
			}
			sort($expectedDepartments, SORT_STRING);
			if ($current !== $expectedDepartments) {
				throw new MemberDirectoryConflictException('Die Abteilungszuordnung wurde inzwischen geändert. Bitte aktualisieren.');
			}
			if (array_diff($current, $editable) !== []) {
				throw new LogicException('Mindestens eine bestehende Abteilungsgruppe ist schreibgeschützt.');
			}

			$changed = [];
			try {
				if (!in_array($destination, $current, true)) {
					$group = $this->departmentGroup($destination);
					$changed[] = [$group, false];
					$this->setMembership($group, $user, true);
				}
				foreach (array_diff($current, [$destination]) as $source) {
					$group = $this->departmentGroup($source);
					$changed[] = [$group, true];
					$this->setMembership($group, $user, false);
				}
				if ($this->memberDepartments($user) !== [$destination]) {
					throw new RuntimeException('Unexpected department memberships after update.');
				}
			} catch (Throwable $error) {
				$restored = true;
				foreach (array_reverse($changed) as [$group, $wasMember]) {
					try {
						$this->setMembership($group, $user, $wasMember);
					} catch (Throwable $rollbackError) {
						$restored = false;
						$this->logger->error('Could not restore member directory group membership', [
							'exception' => $rollbackError,
							'group' => $group->getGID(),
						]);
					}
				}
				$this->logger->error('Nextcloud department change failed', ['exception' => $error]);
				throw new MemberDirectoryConflictException($restored && $this->memberDepartments($user) === $current
					? 'Abteilungswechsel fehlgeschlagen. Die vorherige Zuordnung wurde wiederhergestellt.'
					: 'Abteilungswechsel fehlgeschlagen. Bitte die tatsächliche Zuordnung in der Nextcloud-Benutzerverwaltung prüfen.');
			}
		});
	}

	/** @return list<string> */
	private function memberDepartments(IUser $user): array {
		$departments = array_values(array_intersect($this->config->departments(), $this->groups->getUserGroupIds($user)));
		sort($departments, SORT_STRING);
		return $departments;
	}

	private function departmentGroup(string $gid): IGroup {
		$group = $this->groups->get($gid);
		if ($group === null || $group->getGID() !== $gid || !$group->canAddUser() || !$group->canRemoveUser()) {
			throw new RuntimeException('Department group is no longer writable.');
		}
		return $group;
	}

	private function setMembership(IGroup $group, IUser $user, bool $member): void {
		$gid = $group->getGID();
		if ($this->groups->isInGroup($user->getUID(), $gid) !== $member) {
			if ($member) {
				$group->addUser($user);
			} else {
				$group->removeUser($user);
			}
		}
		if ($this->groups->isInGroup($user->getUID(), $gid) !== $member) {
			throw new RuntimeException('The group backend did not apply the membership change.');
		}
	}

	/** @param callable(IUser): void $update */
	private function withMemberLock(string $actorUid, string $uid, callable $update): void {
		$key = 'profile_fields:member_directory:account:' . hash('sha256', $uid);
		$this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			if (!$this->access->canWrite($actorUid)) {
				throw new LogicException('Keine Schreibberechtigung für die Mitgliederverwaltung.');
			}
			if (!$this->access->isMember($uid)) {
				throw new OutOfBoundsException('Mitglied nicht gefunden.');
			}
			if (!$this->actorCanManageTarget($actorUid, $uid)) {
				throw new LogicException('Dieses Mitglied darf nicht bearbeitet werden.');
			}
			$user = $this->users->get($uid);
			if ($user === null || $user->getUID() !== $uid) {
				throw new LogicException('Dieses Mitglied darf nicht bearbeitet werden.');
			}
			$update($user);
		} finally {
			$this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
