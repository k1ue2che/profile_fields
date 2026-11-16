<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Controller;

use InvalidArgumentException;
use OCA\ProfileFields\AppInfo\Application;
use OCA\ProfileFields\Service\MemberDirectoryConfig;
use OCA\ProfileFields\Service\MemberDirectorySetupService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * No NoAdminRequired: Nextcloud restricts these OCS routes to admins.
 * Additionally check isAdmin() as defense in depth.
 */
class MemberDirectorySettingsApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private MemberDirectoryConfig $config,
		private MemberDirectorySetupService $setup,
		private IGroupManager $groups,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[ApiRoute(verb: 'GET', url: '/api/v1/member-directory/settings')]
	public function index(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Administrator permission required.'], 403);
		}
		return new DataResponse($this->config->getSettings());
	}

	#[ApiRoute(verb: 'GET', url: '/api/v1/member-directory/setup')]
	public function setupPreview(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Administrator permission required.'], 403);
		}
		return new DataResponse($this->setup->preview());
	}

	#[ApiRoute(verb: 'POST', url: '/api/v1/member-directory/setup')]
	public function setupApply(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Administrator permission required.'], 403);
		}
		try {
			return new DataResponse($this->setup->apply());
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], 409);
		}
	}

	/** @param list<string> $readGroups
	 * @param list<string> $writeGroups
	 * @param list<string> $departments
	 * @param list<string> $fieldKeys
	 */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/member-directory/settings')]
	public function update(
		bool $enabled,
		string $baseGroup,
		array $readGroups = [],
		array $writeGroups = [],
		array $departments = [],
		array $fieldKeys = [],
		array $fieldPermissions = [],
		array $createGroups = [],
	): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Administrator permission required.'], 403);
		}
		try {
			if ($enabled) {
				$this->setup->apply();
				$fieldKeys = array_values(array_unique(array_merge($fieldKeys, MemberDirectorySetupService::requiredKeys())));
			}
			return new DataResponse($this->config->save(
				$enabled,
				$baseGroup,
				$readGroups,
				$writeGroups,
				$departments,
				$fieldKeys,
				$fieldPermissions,
				$createGroups,
			));
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], 400);
		}
	}

	private function isAdmin(): bool {
		return $this->userId !== null && $this->groups->isAdmin($this->userId);
	}
}
