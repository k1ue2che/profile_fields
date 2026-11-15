<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Service;

use InvalidArgumentException;
use OCA\ProfileFields\Db\FieldDefinition;
use OCA\ProfileFields\Db\FieldValue;
use OCP\IGroupManager;
use OCP\Lock\ILockingProvider;

/**
 * Renders a member directory from Nextcloud groups and existing profile
 * fields. This class never changes Nextcloud account information.
 */
class MemberDirectoryService {
	private const MAX_PAGE_SIZE = 100;

	public function __construct(
		private MemberDirectoryConfig $config,
		private MemberDirectoryAccessService $access,
		private IGroupManager $groups,
		private FieldDefinitionService $definitions,
		private FieldValueService $values,
		private ILockingProvider $lockingProvider,
	) {
	}

	/**
	 * @return array{fields: list<array<string, mixed>>, departments: list<string>, items: list<array<string, mixed>>, pagination: array{total: int, limit: int, offset: int}}
	 */
	public function listMembers(
		string $department = '',
		string $search = '',
		int $limit = 50,
		int $offset = 0,
		?string $actorUid = null,
	): array {
		if ($limit < 1 || $limit > self::MAX_PAGE_SIZE || $offset < 0) {
			throw new InvalidArgumentException('Invalid pagination parameters.');
		}
		$search = trim($search);
		if (mb_strlen($search) > 200) {
			throw new InvalidArgumentException('Search term must not exceed 200 characters.');
		}
		if ($department !== '' && !in_array($department, $this->config->departments(), true)) {
			throw new InvalidArgumentException('Invalid department filter.');
		}

		$baseGroupId = $this->config->baseGroup();
		$group = $baseGroupId === null ? null : $this->groups->get($baseGroupId);
		if (!$this->config->enabled() || $group === null) {
			throw new InvalidArgumentException('Member directory is not configured.');
		}

		$definitions = $this->allowedDefinitions($actorUid);
		$items = [];
		$normalizedSearch = mb_strtolower($search);
		foreach ($group->getUsers() as $user) {
			$uid = $user->getUID();
			// Revalidate membership in case accounts or groups changed.
			if (!$this->access->isMember($uid) || !$this->access->matchesDepartment($uid, $department)) {
				continue;
			}

			$fields = $this->valuesForMember($uid, $definitions);
			$displayName = $user->getDisplayName();
			$departments = array_values(array_filter(
				$this->config->departments(),
				fn (string $gid): bool => $this->groups->isInGroup($uid, $gid),
			));
			if ($normalizedSearch !== '' && !$this->matchesSearch($uid, $displayName, $fields, $normalizedSearch)) {
				continue;
			}

			$items[] = [
				'user_uid' => $uid,
				'display_name' => $displayName,
				'departments' => $departments,
				'fields' => $fields,
			];
		}

		usort($items, static fn (array $a, array $b): int => [
			mb_strtolower($a['display_name']),
			$a['user_uid'],
		] <=> [
			mb_strtolower($b['display_name']),
			$b['user_uid'],
		]);

		return [
			'fields' => array_values(array_map(fn (FieldDefinition $definition): array => [
				'id' => $definition->getId(),
				'field_key' => $definition->getFieldKey(),
				'label' => $definition->getLabel(),
				'type' => $definition->getType(),
				'options' => $definition->jsonSerialize()['options'],
				'can_write' => $this->access->canWriteField($actorUid, $definition),
			], $definitions)),
			'departments' => $this->config->departments(),
			'items' => array_slice($items, $offset, $limit),
			'pagination' => ['total' => count($items), 'limit' => $limit, 'offset' => $offset],
		];
	}

	/**
	 * @param list<FieldDefinition> $definitions
	 * @return array<string, array{value: mixed, etag: string, updated_at: ?string}>
	 */
	private function valuesForMember(string $uid, array $definitions): array {
		$byId = [];
		$result = [];
		foreach ($definitions as $definition) {
			$byId[$definition->getId()] = $definition->getFieldKey();
			$result[$definition->getFieldKey()] = [
				'value' => null,
				'etag' => '',
				'updated_at' => null,
			];
		}

		if ($byId === []) {
			return $result;
		}
		foreach ($this->values->findByUserUid($uid) as $value) {
			$key = $byId[$value->getFieldDefinitionId()] ?? null;
			if ($key === null) {
				continue;
			}
			$serialized = $this->values->serializeForResponse($value);
			$result[$key] = [
				'value' => $serialized['value']['value'] ?? null,
				'etag' => self::etag($value),
				'updated_at' => $serialized['updated_at'],
			];
		}
		return $result;
	}

	/** @return list<FieldDefinition> */
	public function allowedDefinitions(?string $actorUid = null): array {
		return array_values(array_filter(
			$this->definitions->findActiveOrdered(),
			fn (FieldDefinition $definition): bool => $this->access->canReadField($actorUid, $definition),
		));
	}

	/**
	 * Caller MUST verify canWrite, isMember and isAllowedField before calling.
	 * Passing an expected ETag prevents silently overwriting stale form values.
	 *
	 * @param array<string, mixed>|scalar|null $rawValue
	 * @return array{value: mixed, etag: string, updated_at: string}
	 */
	public function upsertValue(
		FieldDefinition $definition,
		string $targetUid,
		array|string|int|float|bool|null $rawValue,
		string $actorUid,
		string $expectedEtag,
	): array {
		if (!$this->access->canWrite($actorUid)
			|| !$this->access->isMember($targetUid)
			|| !$this->access->canWriteField($actorUid, $definition)) {
			throw new \LogicException('Member directory write is not permitted.');
		}

		// Serialize writes from this directory for the same member/field,
		// including the first insert when no value row exists yet.
		$lockKey = 'profile_fields:member_directory:' . hash('sha256', $targetUid . "\x00" . $definition->getId());
		$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			// Re-read *inside* the exclusive lock: ETag verification and the
			// subsequent upsert must not overlap another directory write.
			$previous = $this->values->findByFieldDefinitionIdAndUserUid($definition->getId(), $targetUid);
			if (!hash_equals(self::etag($previous), $expectedEtag)) {
				throw new MemberDirectoryConflictException('Value changed since it was loaded. Reload and try again.');
			}

			// Preserve visibility; the directory must never reset it to defaults.
			$visibility = $previous?->getCurrentVisibility();
			$stored = $this->values->upsert(
				$definition,
				$targetUid,
				$rawValue,
				$actorUid,
				$visibility,
			);
			$record = $this->values->serializeForResponse($stored);
			return [
				'value' => $record['value']['value'] ?? null,
				'etag' => self::etag($stored),
				'updated_at' => $record['updated_at'],
			];
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public static function etag(?FieldValue $value): string {
		if ($value === null) {
			return '';
		}
		return hash('sha256', implode("\x00", [
			$value->getValueJson(),
			$value->getCurrentVisibility(),
			$value->getUpdatedByUid(),
			$value->getUpdatedAt()->format('U.u'),
		]));
	}

	/**
	 * @param array<string, array{value: mixed, etag: string, updated_at: ?string}> $fields
	 */
	private function matchesSearch(string $uid, string $name, array $fields, string $needle): bool {
		if (str_contains(mb_strtolower($uid . ' ' . $name), $needle)) {
			return true;
		}
		foreach ($fields as $field) {
			$value = $field['value'];
			if ($value === null) {
				continue;
			}
			$searchable = is_array($value) ? implode(' ', array_map(strval(...), $value)) : (string)$value;
			if (str_contains(mb_strtolower($searchable), $needle)) {
				return true;
			}
		}
		return false;
	}
}
