<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Tests\Unit\Controller;

use OCA\ProfileFields\Controller\MemberDirectoryApiController;
use OCA\ProfileFields\Db\FieldDefinition;
use OCA\ProfileFields\Service\FieldDefinitionService;
use OCA\ProfileFields\Service\MemberDirectoryAccessService;
use OCA\ProfileFields\Service\MemberDirectoryAccountService;
use OCA\ProfileFields\Service\MemberDirectoryConflictException;
use OCA\ProfileFields\Service\MemberDirectoryCreationService;
use OCA\ProfileFields\Service\MemberDirectoryService;
use OCA\ProfileFields\Service\MemberNumberService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MemberDirectoryApiControllerTest extends TestCase {
	public function testCannotListWithoutExplicitReadPermission(): void {
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canRead')->willReturn(false);
		$directory = $this->createMock(MemberDirectoryService::class);
		$directory->expects($this->never())->method('listMembers');

		$controller = $this->controller($directory, $access);
		$this->assertSame(403, $controller->index()->getStatus());
	}

	public function testForwardsSortingAndAllMembersLimit(): void {
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canRead')->willReturn(true);
		$directory = $this->createMock(MemberDirectoryService::class);
		$directory->expects(self::once())->method('listMembers')->with(
			'', '', 0, 0, 'admin', 'mitgliedsnummer', 'desc',
		)->willReturn([
			'items' => [],
			'fields' => [],
			'departments' => [],
			'pagination' => ['total' => 0, 'limit' => 0, 'offset' => 0],
		]);
		$controller = $this->controller($directory, $access);
		self::assertSame(200, $controller->index('', '', 0, 0, 'mitgliedsnummer', 'desc')->getStatus());
	}

	public function testCannotChangeNonMember(): void {
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canWrite')->willReturn(true);
		$access->method('isMember')->with('outsider')->willReturn(false);
		$directory = $this->createMock(MemberDirectoryService::class);
		$directory->expects($this->never())->method('upsertValue');

		$controller = $this->controller($directory, $access);
		$this->assertSame(404, $controller->updateField('outsider', 5, 'x')->getStatus());
	}

	public function testRejectsNonAllowedProfileField(): void {
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canWrite')->willReturn(true);
		$access->method('isMember')->willReturn(true);
		$access->method('canWriteField')->willReturn(false);

		$definition = new FieldDefinition();
		$definitions = $this->createMock(FieldDefinitionService::class);
		$definitions->method('findById')->willReturn($definition);
		$directory = $this->createMock(MemberDirectoryService::class);
		$directory->expects($this->never())->method('upsertValue');

		$controller = $this->controller($directory, $access, $definitions);
		$this->assertSame(404, $controller->updateField('member', 5, 'x')->getStatus());
	}

	public function testUpdateReturnsConflictForStaleEtag(): void {
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canWrite')->willReturn(true);
		$access->method('isMember')->willReturn(true);
		$access->method('canWriteField')->willReturn(true);

		$definition = new FieldDefinition();
		$definitions = $this->createMock(FieldDefinitionService::class);
		$definitions->method('findById')->willReturn($definition);
		$directory = $this->createMock(MemberDirectoryService::class);
		$directory->method('upsertValue')->willThrowException(
			new MemberDirectoryConflictException('Reload and try again.'),
		);

		$controller = $this->controller($directory, $access, $definitions);
		$this->assertSame(409, $controller->updateField('member', 5, 'x', 'old')->getStatus());
	}

	public function testDepartmentConflictReturns409(): void {
		$accounts = $this->createMock(MemberDirectoryAccountService::class);
		$accounts->expects(self::once())->method('changeDepartment')->with('admin', 'member', '50nY', ['20NY'])
			->willThrowException(new MemberDirectoryConflictException('Reload and try again.'));
		$controller = $this->controller(
			$this->createMock(MemberDirectoryService::class),
			$this->createMock(MemberDirectoryAccessService::class),
			accounts: $accounts,
		);
		self::assertSame(409, $controller->changeDepartment('member', '50nY', ['20NY'])->getStatus());
	}

	public function testCreationIsDeniedWithoutSeparatePermission(): void {
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canCreate')->with('admin')->willReturn(false);
		$creator = $this->createMock(MemberDirectoryCreationService::class);
		$creator->expects(self::never())->method('create');

		$controller = $this->controller(
			$this->createMock(MemberDirectoryService::class),
			$access,
			creator: $creator,
		);
		self::assertSame(403, $controller->create(
			'NewMember', 'New', 'Member', 'New Member', 'test@example.org', '20NY',
		)->getStatus());
	}

	public function testCreatedMemberReturns201(): void {
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canCreate')->with('admin')->willReturn(true);
		$creator = $this->createMock(MemberDirectoryCreationService::class);
		$creator->expects(self::once())->method('create')->with(
			'admin', 'NewMember', 'New', 'Member', 'New Member', 'test@example.org', '20NY',
			['member_number' => '782'], '',
		)->willReturn(['user_uid' => 'NewMember', 'invitation_sent' => true]);

		$controller = $this->controller(
			$this->createMock(MemberDirectoryService::class),
			$access,
			creator: $creator,
		);
		$response = $controller->create(
			'NewMember', 'New', 'Member', 'New Member', 'test@example.org', '20NY',
			['member_number' => '782'],
		);
		self::assertSame(201, $response->getStatus());
		self::assertSame(['user_uid' => 'NewMember', 'invitation_sent' => true], $response->getData());
	}

	public function testNewMemberBirthdateIsForwardedToAccountCreation(): void {
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canCreate')->with('admin')->willReturn(true);
		$creator = $this->createMock(MemberDirectoryCreationService::class);
		$creator->expects(self::once())->method('create')->with(
			'admin', 'NewMember', 'New', 'Member', 'New Member', 'test@example.org', '20NY', [],
			'2000-02-29',
		)->willReturn(['user_uid' => 'NewMember', 'invitation_sent' => true]);
		$controller = $this->controller(
			$this->createMock(MemberDirectoryService::class),
			$access,
			creator: $creator,
		);
		$response = $controller->create(
			'NewMember', 'New', 'Member', 'New Member', 'test@example.org', '20NY', [],
			'2000-02-29',
		);
		self::assertSame(201, $response->getStatus());
	}

	public function testCreatingDuplicateMemberReturnsConflict(): void {
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canCreate')->with('admin')->willReturn(true);
		$creator = $this->createMock(MemberDirectoryCreationService::class);
		$creator->method('create')->willThrowException(
			new MemberDirectoryConflictException('Der Kontoname ist bereits vergeben.'),
		);
		$controller = $this->controller(
			$this->createMock(MemberDirectoryService::class),
			$access,
			creator: $creator,
		);
		self::assertSame(409, $controller->create(
			'NewMember', 'New', 'Member', 'New Member', 'test@example.org', '20NY',
		)->getStatus());
	}

	private function controller(
		MemberDirectoryService $directory,
		MemberDirectoryAccessService $access,
		?FieldDefinitionService $definitions = null,
		?MemberDirectoryAccountService $accounts = null,
		?MemberDirectoryCreationService $creator = null,
	): MemberDirectoryApiController {
		return new MemberDirectoryApiController(
			$this->createMock(IRequest::class),
			$directory,
			$access,
			$definitions ?? $this->createMock(FieldDefinitionService::class),
			'admin',
			$accounts ?? $this->createMock(MemberDirectoryAccountService::class),
			$creator ?? $this->createMock(MemberDirectoryCreationService::class),
			$this->createMock(MemberNumberService::class),
			new NullLogger(),
		);
	}
}
