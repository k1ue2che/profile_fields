<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Tests\Unit\Service;

use OCA\ProfileFields\Service\MemberDirectoryAccessService;
use OCA\ProfileFields\Service\MemberDirectoryConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class MemberDirectoryAccessServiceTest extends TestCase {
	public function testAdministratorCanNavigateEvenWhenDirectoryIsDisabledWithoutReadingData(): void {
		$config = $this->createMock(MemberDirectoryConfig::class);
		$config->method('enabled')->willReturn(false);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('testadmin')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('testadmin')->willReturn($this->createMock(IUser::class));
		$service = new MemberDirectoryAccessService($config, $groups, $users);
		self::assertTrue($service->isAdministrator('testadmin'));
		self::assertFalse($service->canRead('testadmin'));
		self::assertFalse($service->canWrite('testadmin'));
	}

	public function testOrdinaryMemberDoesNotGainAdministratorNavigation(): void {
		$config = $this->createMock(MemberDirectoryConfig::class);
		$config->method('enabled')->willReturn(false);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('member')->willReturn(false);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('member')->willReturn($this->createMock(IUser::class));
		$service = new MemberDirectoryAccessService($config, $groups, $users);
		self::assertFalse($service->isAdministrator('member'));
		self::assertFalse($service->isAdministrator(null));
	}

	public function testDirectoryIsClosedWhenNotConfigured(): void {
		$config = $this->createMock(MemberDirectoryConfig::class);
		$config->method('enabled')->willReturn(false);
		$service = new MemberDirectoryAccessService($config, $this->createMock(IGroupManager::class), $this->createMock(IUserManager::class));

		self::assertFalse($service->canRead('member'));
		self::assertFalse($service->canWrite('member'));
		self::assertFalse($service->isMember('member'));
	}

	public function testConfiguredWriterCanReadAndWrite(): void {
		$config = $this->createMock(MemberDirectoryConfig::class);
		$config->method('enabled')->willReturn(true);
		$config->method('writeGroups')->willReturn(['Vorstand']);
		$config->method('readGroups')->willReturn([]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('groupExists')->with('Vorstand')->willReturn(true);
		$groups->method('isInGroup')->with('editor', 'Vorstand')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('editor')->willReturn($this->createMock(IUser::class));

		$service = new MemberDirectoryAccessService($config, $groups, $users);
		self::assertTrue($service->canRead('editor'));
		self::assertTrue($service->canWrite('editor'));
	}

	public function testCreationRequiresSeparateExplicitPermission(): void {
		$config = $this->createMock(MemberDirectoryConfig::class);
		$config->method('enabled')->willReturn(true);
		$config->method('writeGroups')->willReturn(['Vorstand']);
		$config->method('createGroups')->willReturn([]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('groupExists')->willReturn(true);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $uid === 'editor' && $group === 'Vorstand',
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$service = new MemberDirectoryAccessService($config, $groups, $users);
		self::assertTrue($service->canWrite('editor'));
		self::assertFalse($service->canCreate('editor'));
		self::assertFalse($service->canCreate(null));
	}

	public function testExplicitCreationGroupCanCreate(): void {
		$config = $this->createMock(MemberDirectoryConfig::class);
		$config->method('enabled')->willReturn(true);
		$config->method('writeGroups')->willReturn(['Vorstand']);
		$config->method('createGroups')->willReturn(['Vorstand']);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('groupExists')->willReturn(true);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => $uid === 'editor' && $gid === 'Vorstand',
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$service = new MemberDirectoryAccessService($config, $groups, $users);
		self::assertTrue($service->canCreate('editor'));
	}

	public function testNonMembersCannotBeEdited(): void {
		$config = $this->createMock(MemberDirectoryConfig::class);
		$config->method('enabled')->willReturn(true);
		$config->method('baseGroup')->willReturn('LHReV');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->with('outside', 'LHReV')->willReturn(false);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('outside')->willReturn($this->createMock(IUser::class));

		$service = new MemberDirectoryAccessService($config, $groups, $users);
		self::assertFalse($service->isMember('outside'));
	}

	public function testUnknownDepartmentsCannotBeUsedAsFilter(): void {
		$config = $this->createMock(MemberDirectoryConfig::class);
		$config->method('departments')->willReturn(['20NY']);
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->never())->method('isInGroup');

		$service = new MemberDirectoryAccessService($config, $groups, $this->createMock(IUserManager::class));
		self::assertFalse($service->matchesDepartment('member', 'unsupported'));
	}

	public function testFieldReadGrantDoesNotImplyWriteGrant(): void {
		$config = $this->createMock(MemberDirectoryConfig::class);
		$config->method('enabled')->willReturn(true);
		$config->method('readGroups')->willReturn(['Kassenpruefer']);
		$config->method('writeGroups')->willReturn(['Kassenwart']);
		$config->method('fieldKeys')->willReturn(['anschrift']);
		$config->method('fieldPermissions')->willReturn([
			'anschrift' => ['readGroups' => ['Kassenpruefer'], 'writeGroups' => ['Kassenwart']],
		]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('groupExists')->willReturn(true);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $uid === 'auditor' && $group === 'Kassenpruefer',
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$field = new \OCA\ProfileFields\Db\FieldDefinition();
		$field->setFieldKey('anschrift');
		$field->setActive(true);

		$service = new MemberDirectoryAccessService($config, $groups, $users);
		self::assertTrue($service->canReadField('auditor', $field));
		self::assertFalse($service->canWriteField('auditor', $field));
	}

	public function testMissingFieldGrantDeniesValuesEvenToAdmin(): void {
		$config = $this->createMock(MemberDirectoryConfig::class);
		$config->method('enabled')->willReturn(true);
		$config->method('fieldKeys')->willReturn(['anschrift']);
		$config->method('fieldPermissions')->willReturn([]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$field = new \OCA\ProfileFields\Db\FieldDefinition();
		$field->setFieldKey('anschrift');
		$field->setActive(true);
		$service = new MemberDirectoryAccessService($config, $groups, $users);
		self::assertFalse($service->canReadField('administrator', $field));
		self::assertFalse($service->canWriteField('administrator', $field));
	}
}
