<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Service;

use InvalidArgumentException;
use OCA\ProfileFields\AppInfo\Application;
use OCP\IConfig;
use OCP\IGroupManager;

/**
 * Instance-specific member directory configuration.
 *
 * Fail closed: the directory is disabled and field allow-list is empty until
 * an administrator explicitly configures them.
 */
class MemberDirectoryConfig {
	private const ENABLED = 'member_directory.enabled';
	private const BASE_GROUP = 'member_directory.base_group';
	private const READ_GROUPS = 'member_directory.read_groups';
	private const WRITE_GROUPS = 'member_directory.write_groups';
	private const DEPARTMENTS = 'member_directory.departments';
	private const FIELD_KEYS = 'member_directory.field_keys';
	private const FIELD_PERMISSIONS = 'member_directory.field_permissions';
	private const CREATE_GROUPS = 'member_directory.create_groups';

	public function __construct(
		private IConfig $config,
		private IGroupManager $groupManager,
		private FieldDefinitionService $definitions,
	) {
	}

	public function enabled(): bool {
		return $this->config->getAppValue(Application::APP_ID, self::ENABLED, '0') === '1'
			&& $this->baseGroup() !== null;
	}

	public function baseGroup(): ?string {
		$group = $this->config->getAppValue(Application::APP_ID, self::BASE_GROUP, '');
		return $group !== '' && $this->groupManager->groupExists($group) ? $group : null;
	}

	/** @return list<string> */
	public function readGroups(): array {
		return $this->readList(self::READ_GROUPS);
	}

	/** @return list<string> */
	public function writeGroups(): array {
		return $this->readList(self::WRITE_GROUPS);
	}

	/** @return list<string> */
	public function createGroups(): array {
		return $this->readList(self::CREATE_GROUPS);
	}

	/** @return list<string> */
	public function departments(): array {
		return $this->readList(self::DEPARTMENTS);
	}

	/** @return list<string> */
	public function fieldKeys(): array {
		return $this->readList(self::FIELD_KEYS);
	}

	/**
	 * Field-specific grants are explicit; a global read/write group alone does
	 * not grant access to any field. Invalid stored data fails closed.
	 *
	 * @return array<string, array{readGroups: list<string>, writeGroups: list<string>}>
	 */
	public function fieldPermissions(): array {
		try {
			$raw = json_decode($this->config->getAppValue(Application::APP_ID, self::FIELD_PERMISSIONS, '{}'), true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($raw) || count($raw) > 100) {
			return [];
		}
		$allowedKeys = $this->fieldKeys();
		$readGroups = array_unique(array_merge($this->readGroups(), $this->writeGroups()));
		$writeGroups = $this->writeGroups();
		$result = [];
		foreach ($raw as $key => $rule) {
			if (!is_string($key) || !in_array($key, $allowedKeys, true) || !is_array($rule)) {
				continue;
			}
			$read = $rule['readGroups'] ?? null;
			$write = $rule['writeGroups'] ?? null;
			if (!is_array($read) || !array_is_list($read) || !is_array($write) || !array_is_list($write)) {
				continue;
			}
			if (count($read) > 100 || count($write) > 100) {
				continue;
			}
			if (array_filter($read, static fn (mixed $g): bool => !is_string($g) || !in_array($g, $readGroups, true)) !== []
				|| array_filter($write, static fn (mixed $g): bool => !is_string($g) || !in_array($g, $writeGroups, true)) !== []) {
				continue;
			}
			$result[$key] = ['readGroups' => array_values(array_unique($read)), 'writeGroups' => array_values(array_unique($write))];
		}
		return $result;
	}

	/** @return array{enabled: bool, baseGroup: ?string, readGroups: list<string>, writeGroups: list<string>, createGroups: list<string>, departments: list<string>, fieldKeys: list<string>, fieldPermissions: array<string, array{readGroups: list<string>, writeGroups: list<string>}>} */
	public function getSettings(): array {
		return [
			'enabled' => $this->enabled(),
			'baseGroup' => $this->baseGroup(),
			'readGroups' => $this->readGroups(),
			'writeGroups' => $this->writeGroups(),
			'createGroups' => $this->createGroups(),
			'departments' => $this->departments(),
			'fieldKeys' => $this->fieldKeys(),
			'fieldPermissions' => $this->fieldPermissions(),
		];
	}

	/**
	 * @param list<string> $readGroups
	 * @param list<string> $writeGroups
	 * @param list<string> $departments
	 * @param list<string> $fieldKeys
	 * @param array<string, array{readGroups: list<string>, writeGroups: list<string>}> $fieldPermissions
	 * @param list<string> $createGroups
	 * @return array<string, mixed>
	 */
	public function save(
		bool $enabled,
		string $baseGroup,
		array $readGroups,
		array $writeGroups,
		array $departments,
		array $fieldKeys,
		array $fieldPermissions = [],
		array $createGroups = [],
	): array {
		if ($enabled && $baseGroup === '') {
			throw new InvalidArgumentException('A base group is required to enable the member directory.');
		}
		if ($baseGroup !== '' && !$this->groupManager->groupExists($baseGroup)) {
			throw new InvalidArgumentException('The base group does not exist.');
		}

		$readGroups = $this->validGroupList($readGroups);
		$writeGroups = $this->validGroupList($writeGroups);
		$createGroups = $this->validGroupList($createGroups);
		if (array_diff($createGroups, $writeGroups) !== []) {
			throw new InvalidArgumentException('Creation groups must also have member-directory write access.');
		}
		$departments = $this->validGroupList($departments);
		$fieldKeys = $this->validFieldList($fieldKeys);
		$fieldPermissions = $this->validateFieldPermissions($fieldPermissions, $fieldKeys, $readGroups, $writeGroups);

		$this->config->setAppValue(Application::APP_ID, self::BASE_GROUP, $baseGroup);
		foreach ([
			self::READ_GROUPS => $readGroups,
			self::WRITE_GROUPS => $writeGroups,
			self::CREATE_GROUPS => $createGroups,
			self::DEPARTMENTS => $departments,
			self::FIELD_KEYS => $fieldKeys,
		] as $key => $values) {
			$this->config->setAppValue(Application::APP_ID, $key, json_encode($values, JSON_THROW_ON_ERROR));
		}
		$this->config->setAppValue(Application::APP_ID, self::FIELD_PERMISSIONS, json_encode((object)$fieldPermissions, JSON_THROW_ON_ERROR));
		$this->config->setAppValue(Application::APP_ID, self::ENABLED, $enabled ? '1' : '0');

		return $this->getSettings();
	}

	/**
	 * @param array<string, mixed> $permissions
	 * @param list<string> $fieldKeys
	 * @param list<string> $readGroups
	 * @param list<string> $writeGroups
	 * @return array<string, array{readGroups: list<string>, writeGroups: list<string>}>
	 */
	private function validateFieldPermissions(array $permissions, array $fieldKeys, array $readGroups, array $writeGroups): array {
		if (count($permissions) > 100) {
			throw new InvalidArgumentException('Too many field permission entries.');
		}
		$readable = array_unique(array_merge($readGroups, $writeGroups));
		$result = [];
		foreach ($permissions as $key => $rule) {
			if (!is_string($key) || !in_array($key, $fieldKeys, true) || !is_array($rule)) {
				throw new InvalidArgumentException('Field permission references an unauthorized field.');
			}
			$read = $rule['readGroups'] ?? null;
			$write = $rule['writeGroups'] ?? null;
			if (!is_array($read) || !array_is_list($read) || !is_array($write) || !array_is_list($write)
				|| count($read) > 100 || count($write) > 100) {
				throw new InvalidArgumentException('Field permissions require group lists.');
			}
			foreach ($read as $group) {
				if (!is_string($group) || !in_array($group, $readable, true)) {
					throw new InvalidArgumentException('Field read permission references a group without directory access.');
				}
			}
			foreach ($write as $group) {
				if (!is_string($group) || !in_array($group, $writeGroups, true)) {
					throw new InvalidArgumentException('Field write permission references a group without write access.');
				}
			}
			$result[$key] = ['readGroups' => array_values(array_unique($read)), 'writeGroups' => array_values(array_unique($write))];
		}
		return $result;
	}

	/** @return list<string> */
	private function readList(string $key): array {
		$raw = $this->config->getAppValue(Application::APP_ID, $key, '[]');
		try {
			$items = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($items) || !array_is_list($items) || count($items) > 100) {
			return [];
		}
		foreach ($items as $value) {
			if (!is_string($value) || $value === '') {
				return [];
			}
		}
		return array_values(array_unique($items));
	}

	/** @param list<string> $groups
	 * @return list<string>
	 */
	private function validGroupList(array $groups): array {
		if (!array_is_list($groups) || count($groups) > 100) {
			throw new InvalidArgumentException('Group lists must be arrays of at most 100 items.');
		}
		foreach ($groups as $group) {
			if (!is_string($group) || $group === '' || !$this->groupManager->groupExists($group)) {
				throw new InvalidArgumentException('One of the selected groups does not exist.');
			}
		}
		return array_values(array_unique($groups));
	}

	/** @param list<string> $fieldKeys
	 * @return list<string>
	 */
	private function validFieldList(array $fieldKeys): array {
		if (!array_is_list($fieldKeys) || count($fieldKeys) > 100) {
			throw new InvalidArgumentException('Field lists must be arrays of at most 100 items.');
		}
		$activeKeys = array_map(
			static fn ($definition): string => $definition->getFieldKey(),
			$this->definitions->findActiveOrdered(),
		);
		foreach ($fieldKeys as $key) {
			if (!is_string($key) || $key === '' || !in_array($key, $activeKeys, true)) {
				throw new InvalidArgumentException('One of the selected profile fields is not active or does not exist.');
			}
		}
		return array_values(array_unique($fieldKeys));
	}
}
