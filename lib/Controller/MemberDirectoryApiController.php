<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Controller;

use InvalidArgumentException;
use LogicException;
use OCA\ProfileFields\AppInfo\Application;
use OCA\ProfileFields\Service\FieldDefinitionService;
use OCA\ProfileFields\Service\MemberDirectoryAccessService;
use OCA\ProfileFields\Service\MemberDirectoryConflictException;
use OCA\ProfileFields\Service\MemberDirectoryService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Member-only OCS API. Access checks are server-side on every request.
 * Never reuse administrator endpoints for non-administrator users.
 */
class MemberDirectoryApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private MemberDirectoryService $directory,
		private MemberDirectoryAccessService $access,
		private FieldDefinitionService $definitions,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/member-directory/members')]
	public function index(
		string $department = '',
		string $search = '',
		int $limit = 50,
		int $offset = 0,
	): DataResponse {
		if (!$this->access->canRead($this->userId)) {
			return new DataResponse(['message' => 'Member directory access denied.'], 403);
		}

		try {
			$result = $this->directory->listMembers($department, $search, $limit, $offset, $this->userId);
			$result['can_write'] = $this->access->canWrite($this->userId);
			return new DataResponse($result, 200);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], 400);
		}
	}

	/**
	 * @param array<string, mixed>|scalar|null $value
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/member-directory/members/{userUid}/fields/{fieldDefinitionId}')]
	public function updateField(
		string $userUid,
		int $fieldDefinitionId,
		array|string|int|float|bool|null $value = null,
		string $expectedEtag = '',
	): DataResponse {
		if (!$this->access->canWrite($this->userId)) {
			return new DataResponse(['message' => 'Member directory write access denied.'], 403);
		}
		if (!$this->access->isMember($userUid)) {
			// Do not reveal whether non-member accounts exist.
			return new DataResponse(['message' => 'Member not found.'], 404);
		}
		$definition = $this->definitions->findById($fieldDefinitionId);
		if ($definition === null || !$this->access->canWriteField($this->userId, $definition)) {
			return new DataResponse(['message' => 'Field not found.'], 404);
		}
		if ($this->userId === null) {
			return new DataResponse(['message' => 'Sign-in required.'], 401);
		}

		try {
			return new DataResponse($this->directory->upsertValue(
				$definition,
				$userUid,
				$value,
				$this->userId,
				$expectedEtag,
			), 200);
		} catch (MemberDirectoryConflictException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], 409);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], 400);
		} catch (LogicException) {
			return new DataResponse(['message' => 'Member directory write access denied.'], 403);
		}
	}
}
