<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Service;

use OCA\ProfileFields\Db\FieldDefinition;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Dedicated permissions for member management; does not alter profile
 * visibility or permissions elsewhere in Nextcloud.
 */
class MemberDirectoryAccessService {
	public function __construct(
		private MemberDirectoryConfig $config,
		private IGroupManager $groups,
		private IUserManager $users,
	) {
	}

	/**
	 * Administrators may open the directory's management/disabled page
	 * even when its emergency disable switch is off. This does NOT
	 * authorize reading or changing member values.
	 */
	public function isAdministrator(?string $actorUid): bool {
		return $actorUid !== null
			&& $this->users->get($actorUid) !== null
			&& $this->groups->isAdmin($actorUid);
	}

	public function canRead(?string $actorUid): bool {
		if ($actorUid === null || !$this->config->enabled() || $this->users->get($actorUid) === null) {
			return false;
		}
		return $this->groups->isAdmin($actorUid)
			|| $this->belongsToAny($actorUid, $this->config->readGroups())
			|| $this->belongsToAny($actorUid, $this->config->writeGroups());
	}

	public function canWrite(?string $actorUid): bool {
		if ($actorUid === null || !$this->config->enabled() || $this->users->get($actorUid) === null) {
			return false;
		}
		return $this->groups->isAdmin($actorUid)
			|| $this->belongsToAny($actorUid, $this->config->writeGroups());
	}

	/**
	 * Account creation is a separate, explicit permission. Administrators can
	 * create accounts while configured writers require an additional grant.
	 */
	public function canCreate(?string $actorUid): bool {
		return $this->canWrite($actorUid)
			&& $actorUid !== null
			&& ($this->groups->isAdmin($actorUid)
				|| $this->belongsToAny($actorUid, $this->config->createGroups()));
	}

	public function isMember(string $targetUid): bool {
		$baseGroup = $this->config->baseGroup();
		return $this->config->enabled()
			&& $baseGroup !== null
			&& $this->users->get($targetUid) !== null
			&& $this->groups->isInGroup($targetUid, $baseGroup);
	}

	public function matchesDepartment(string $targetUid, string $department): bool {
		if ($department === '') {
			return true;
		}
		return in_array($department, $this->config->departments(), true)
			&& $this->groups->groupExists($department)
			&& $this->groups->isInGroup($targetUid, $department);
	}

	public function isAllowedField(FieldDefinition $definition): bool {
		return $definition->getActive()
			&& in_array($definition->getFieldKey(), $this->config->fieldKeys(), true);
	}

	/**
	 * Return a field only when the actor has an explicit matching grant.
	 * Nextcloud administrators can configure grants, but do not implicitly
	 * receive access to private member-directory field values.
	 */
	public function canReadField(?string $actorUid, FieldDefinition $definition): bool {
		if (!$this->canRead($actorUid) || !$this->isAllowedField($definition) || $actorUid === null) {
			return false;
		}
		$permissions = $this->config->fieldPermissions();
		$rule = $permissions[$definition->getFieldKey()] ?? null;
		return $rule !== null && ($this->belongsToAny($actorUid, $rule['readGroups'])
			|| $this->belongsToAny($actorUid, $rule['writeGroups']));
	}

	public function canWriteField(?string $actorUid, FieldDefinition $definition): bool {
		if (!$this->canWrite($actorUid) || !$this->isAllowedField($definition) || $actorUid === null) {
			return false;
		}
		$permissions = $this->config->fieldPermissions();
		$rule = $permissions[$definition->getFieldKey()] ?? null;
		return $rule !== null && $this->belongsToAny($actorUid, $rule['writeGroups']);
	}

	/** @param list<string> $groupIds */
	private function belongsToAny(string $uid, array $groupIds): bool {
		foreach ($groupIds as $groupId) {
			if ($this->groups->groupExists($groupId) && $this->groups->isInGroup($uid, $groupId)) {
				return true;
			}
		}
		return false;
	}
}
