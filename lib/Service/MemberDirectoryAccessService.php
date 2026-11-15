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
