<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Service;

use InvalidArgumentException;
use LogicException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
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
		private IUserManager $users,
		private ILockingProvider $locks,
		private LoggerInterface $logger,
	) {
	}

	/** @return list<string> */
	public function editableDepartments(?string $actorUid): array {
		if (!$this->access->canWrite($actorUid) || $actorUid === null
			|| (!$this->groups->isAdmin($actorUid) && !$this->groups->isDelegatedAdmin($actorUid))) {
			return [];
		}

		$protected = array_merge(['admin', $this->config->baseGroup()], $this->config->readGroups(), $this->config->writeGroups());
		return array_values(array_filter($this->config->departments(), function (string $gid) use ($protected): bool {
			if (in_array($gid, $protected, true)) {
				return false;
			}
			$group = $this->groups->get($gid);
			return $group !== null && $group->getGID() === $gid && $group->canAddUser() && $group->canRemoveUser();
		}));
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
