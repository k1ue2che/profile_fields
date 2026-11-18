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
use OCA\ProfileFields\Service\MemberDirectoryAccountService;
use OCA\ProfileFields\Service\MemberDirectoryConflictException;
use OCA\ProfileFields\Service\MemberDirectoryCreationService;
use OCA\ProfileFields\Service\MemberNumberService;
use OCA\ProfileFields\Service\MemberDirectoryService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\Lock\LockedException;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Throwable;

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
		private MemberDirectoryAccountService $accounts,
		private MemberDirectoryCreationService $creator,
		private MemberNumberService $memberNumbers,
		private LoggerInterface $logger,
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
		string $sortBy = 'display_name',
		string $sortDirection = 'asc',
	): DataResponse {
		if (!$this->access->canRead($this->userId)) {
			return new DataResponse(['message' => 'Member directory access denied.'], 403);
		}

		try {
			$result = $this->directory->listMembers($department, $search, $limit, $offset, $this->userId, $sortBy, $sortDirection);
			$result['can_write'] = $this->access->canWrite($this->userId);
			$result['can_create'] = $this->access->canCreate($this->userId);
			$result['creatable_departments'] = $this->creator->availableDepartments($this->userId);
			$result['next_member_number'] = $result['can_create'] ? $this->memberNumbers->suggest($this->userId) : null;
			$result['editable_departments'] = $this->accounts->editableDepartments($this->userId);
			foreach ($result['items'] as &$member) {
				$member['can_edit_display_name'] = $this->accounts->canEditDisplayName($this->userId, $member['user_uid']);
				$member['can_change_department'] = $result['editable_departments'] !== []
					&& array_diff($member['departments'], $result['editable_departments']) === []
					&& $this->accounts->canChangeDepartment($this->userId, $member['user_uid'], $member['departments']);
			}
			unset($member);
			return new DataResponse($result, 200);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], 400);
		}
	}

	/**
	 * Create one member with a fixed base group, exactly one configured
	 * department, explicit profile field grants and a password setup invite.
	 *
	 * @param array<string, mixed> $fields
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[UserRateLimit(limit: 8, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/member-directory/members')]
	public function create(
		string $userUid,
		string $firstName,
		string $lastName,
		string $displayName,
		string $email,
		string $department,
		array $fields = [],
	): DataResponse {
		if ($this->userId === null || !$this->access->canCreate($this->userId)) {
			return new DataResponse(['message' => 'Keine Berechtigung, neue Mitglieder anzulegen.'], 403);
		}
		try {
			return new DataResponse($this->creator->create(
				$this->userId, $userUid, $firstName, $lastName, $displayName, $email, $department, $fields,
			), 201);
		} catch (MemberDirectoryConflictException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], 409);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], 400);
		} catch (LogicException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], 403);
		} catch (Throwable $exception) {
			$this->logger->error('Member directory account creation failed', ['exception' => $exception]);
			return new DataResponse(['message' => 'Konto konnte nicht vollständig angelegt werden. Bitte die Nextcloud-Benutzerverwaltung prüfen.'], 500);
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

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/member-directory/members/{userUid}/display-name')]
	public function updateDisplayName(string $userUid, string $displayName, string $expectedName): DataResponse {
		return $this->updateAccount(fn (string $actorUid) => $this->accounts->updateDisplayName(
			$actorUid, $userUid, $displayName, $expectedName,
		));
	}

	/** @param list<string> $expectedDepartments */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/member-directory/members/{userUid}/department')]
	public function changeDepartment(string $userUid, string $department, array $expectedDepartments): DataResponse {
		return $this->updateAccount(fn (string $actorUid) => $this->accounts->changeDepartment(
			$actorUid, $userUid, $department, $expectedDepartments,
		));
	}

	/** @param callable(string): void $update */
	private function updateAccount(callable $update): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'Bitte zuerst anmelden.'], 401);
		}

		try {
			$update($this->userId);
			return new DataResponse([], 200);
		} catch (MemberDirectoryConflictException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], 409);
		} catch (OutOfBoundsException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], 404);
		} catch (LockedException) {
			return new DataResponse(['message' => 'Das Mitglied wird gerade bearbeitet. Bitte aktualisieren.'], 409);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], 400);
		} catch (LogicException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], 403);
		} catch (Throwable $exception) {
			$this->logger->error('Member directory account update failed', ['exception' => $exception]);
			return new DataResponse(['message' => 'Die Änderung konnte nicht bestätigt werden. Bitte aktualisieren.'], 500);
		}
	}
}
