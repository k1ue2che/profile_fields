<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Tests\Unit\Service;

use LogicException;
use OCA\ProfileFields\Service\MemberDirectoryAccessService;
use OCA\ProfileFields\Service\MemberDirectoryAccountService;
use OCA\ProfileFields\Service\MemberDirectoryConfig;
use OCA\ProfileFields\Service\MemberDirectoryConflictException;
use OCP\Group\ISubAdmin;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MemberDirectoryAccountServiceTest extends TestCase {
	private MemberDirectoryAccountService $service;
	private IUser $member;
	private array $memberships;
	/** @var list<string> */
	private array $departments = ['20NY', '50nY'];
	private bool $groupAdmin = true;
	private bool $allowWrite = true;
	private bool $removeSourceSucceeds = true;

	protected function setUp(): void {
		$this->memberships = ['verein', '20NY', 'unrelated'];
		$config = $this->createMock(MemberDirectoryConfig::class);
		$config->method('baseGroup')->willReturn('verein');
		$config->method('readGroups')->willReturn(['reader']);
		$config->method('writeGroups')->willReturn(['editor']);
		$config->method('departments')->willReturnCallback(fn (): array => $this->departments);

		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canWrite')->willReturnCallback(fn (): bool => $this->allowWrite);
		$access->method('isMember')->with('member')->willReturn(true);

		$this->member = $this->createMock(IUser::class);
		$this->member->method('getUID')->willReturn('member');
		$this->member->method('getDisplayName')->willReturn('Old name');
		$this->member->method('canChangeDisplayName')->willReturn(true);
		$actor = $this->createMock(IUser::class);
		$actor->method('getUID')->willReturn('actor');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => match ($uid) {
				'member' => $this->member,
				'actor' => $actor,
				default => null,
			},
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'actor' && $this->groupAdmin);
		$groups->method('get')->willReturnMap([['20NY', $this->department('20NY')], ['50nY', $this->department('50nY')]]);
		$groups->method('getUserGroupIds')->willReturnCallback(fn (): array => $this->memberships);
		$groups->method('isInGroup')->willReturnCallback(fn ($uid, $gid): bool => in_array($gid, $this->memberships, true));

		$subAdmins = $this->createMock(ISubAdmin::class);
		$subAdmins->method('isSubAdmin')->willReturn(true);
		$subAdmins->method('isSubAdminOfGroup')->willReturnCallback(
			fn (IUser $actor, IGroup $group): bool => $group->getGID() === '20NY',
		);
		$subAdmins->method('isUserAccessible')->willReturn(true);

		$this->service = new MemberDirectoryAccountService(
			$config, $access, $groups, $subAdmins, $users, $this->createMock(ILockingProvider::class), new NullLogger(),
		);
	}

	public function testDepartmentMovePreservesOtherMemberships(): void {
		$this->service->changeDepartment('actor', 'member', '50nY', ['20NY']);
		self::assertSame(['verein', 'unrelated', '50nY'], $this->memberships);
	}

	public function testDepartmentMoveRequiresNextcloudAdministration(): void {
		$this->groupAdmin = false;
		$this->expectException(LogicException::class);
		$this->service->changeDepartment('actor', 'member', '50nY', ['20NY']);
	}

	public function testDelegatedManagerCannotMoveIntoUnassignedDepartment(): void {
		$this->groupAdmin = false;
		self::assertSame(['20NY'], $this->service->editableDepartments('actor'));
		self::assertFalse($this->service->canChangeDepartment('actor', 'member', ['50nY']));
		$this->expectException(LogicException::class);
		$this->service->changeDepartment('actor', 'member', '50nY', ['20NY']);
	}

	public function testRoleAndBaseGroupsAreExcludedEvenIfConfiguredAsDepartments(): void {
		$this->departments = ['20NY', '50nY', 'admin', 'verein', 'reader', 'editor'];
		self::assertSame(['20NY', '50nY'], $this->service->editableDepartments('actor'));
		$this->expectException(LogicException::class);
		$this->service->changeDepartment('actor', 'member', 'editor', ['20NY']);
	}

	public function testDepartmentMoveRejectsStaleMembership(): void {
		$this->expectException(MemberDirectoryConflictException::class);
		$this->service->changeDepartment('actor', 'member', '50nY', []);
	}

	public function testDestinationIsRolledBackIfSourceCannotBeRemoved(): void {
		$this->removeSourceSucceeds = false;
		try {
			$this->service->changeDepartment('actor', 'member', '50nY', ['20NY']);
			self::fail('The group backend rejected the move.');
		} catch (MemberDirectoryConflictException $exception) {
			self::assertStringContainsString('wiederhergestellt', $exception->getMessage());
			self::assertSame(['verein', '20NY', 'unrelated'], $this->memberships);
		}
	}

	public function testDirectoryEditorCanUpdateDisplayName(): void {
		$this->groupAdmin = false;
		$this->member->expects(self::once())->method('setDisplayName')->with('New name')->willReturn(true);
		$this->service->updateDisplayName('actor', 'member', ' New name ', 'Old name');
	}

	public function testDirectorySwitchBlocksAccountWritesEvenForAdmin(): void {
		$this->allowWrite = false;
		$this->member->expects(self::never())->method('setDisplayName');
		$this->expectException(LogicException::class);
		$this->service->updateDisplayName('actor', 'member', 'New name', 'Old name');
	}

	public function testStaleDisplayNameCannotOverwriteCurrentName(): void {
		$this->member->expects(self::never())->method('setDisplayName');
		$this->expectException(MemberDirectoryConflictException::class);
		$this->service->updateDisplayName('actor', 'member', 'New name', 'Stale name');
	}

	private function department(string $gid): IGroup {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('canAddUser')->willReturn(true);
		$group->method('canRemoveUser')->willReturn(true);
		$group->method('addUser')->with($this->member)->willReturnCallback(function () use ($gid): void {
			$this->memberships[] = $gid;
		});
		$group->method('removeUser')->with($this->member)->willReturnCallback(function () use ($gid): void {
			if ($gid !== '20NY' || $this->removeSourceSucceeds) {
				$this->memberships = array_values(array_diff($this->memberships, [$gid]));
			}
		});
		return $group;
	}
}
