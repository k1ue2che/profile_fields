<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Tests\Unit\Service;

use OCA\ProfileFields\Service\MemberDirectoryCreationService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MemberDirectoryDisplayNameTest extends TestCase {
	public function testInitialBirthdateIsPrivateNativeNextcloudProperty(): void {
		$user = $this->createMock(IUser::class);
		$user->method('canEditProperty')->with(IAccountManager::PROPERTY_BIRTHDATE)->willReturn(true);
		$property = $this->createMock(IAccountProperty::class);
		$property->expects(self::once())->method('setValue')->with('2000-02-29T00:00:00.000Z');
		$property->expects(self::once())->method('setScope')->with(IAccountManager::SCOPE_PRIVATE);
		$account = $this->createMock(IAccount::class);
		$account->method('getProperty')->with(IAccountManager::PROPERTY_BIRTHDATE)->willReturn($property);
		$accountManager = $this->createMock(IAccountManager::class);
		$accountManager->method('getAccount')->with($user)->willReturn($account);
		$accountManager->expects(self::once())->method('updateAccount')->with($account);
		MemberDirectoryCreationService::setInitialBirthdate($accountManager, $user, '2000-02-29');
	}

	public function testEmptyBirthdateDoesNotModifyAccount(): void {
		$manager = $this->createMock(IAccountManager::class);
		$manager->expects(self::never())->method('getAccount');
		$manager->expects(self::never())->method('updateAccount');
		MemberDirectoryCreationService::setInitialBirthdate($manager, $this->createMock(IUser::class), '');
	}

	public function testBirthdateCannotBeWrittenWhenBackendDoesNotAllowIt(): void {
		$manager = $this->createMock(IAccountManager::class);
		$manager->expects(self::never())->method('updateAccount');
		$user = $this->createMock(IUser::class);
		$user->method('canEditProperty')->willReturn(false);
		$this->expectException(\\LogicException::class);
		MemberDirectoryCreationService::setInitialBirthdate($manager, $user, '2000-01-01');
	}

	public function testInvalidBirthdateCannotBeWritten(): void {
		$manager = $this->createMock(IAccountManager::class);
		$manager->expects(self::never())->method('updateAccount');
		$this->expectException(\\InvalidArgumentException::class);
		MemberDirectoryCreationService::setInitialBirthdate($manager, $this->createMock(IUser::class), '2001-02-29');
	}

	public function testExistingDisplayNameIsAcceptedWithoutSetter(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Testosteron Tester');
		$user->expects(self::never())->method('canChangeDisplayName');
		$user->expects(self::never())->method('setDisplayName');

		MemberDirectoryCreationService::ensureInitialDisplayName($user, 'Testosteron Tester');
	}

	public function testWritableDisplayNameIsUpdatedAndVerified(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturnOnConsecutiveCalls('username', 'Test Member');
		$user->method('canChangeDisplayName')->willReturn(true);
		$user->expects(self::once())->method('setDisplayName')->with('Test Member')->willReturn(true);

		MemberDirectoryCreationService::ensureInitialDisplayName($user, 'Test Member');
	}

	public function testBackendRejectionIsNotIgnored(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('username');
		$user->method('canChangeDisplayName')->willReturn(true);
		$user->expects(self::once())->method('setDisplayName')->willReturn(false);

		$this->expectException(RuntimeException::class);
		MemberDirectoryCreationService::ensureInitialDisplayName($user, 'Test Member');
	}

	public function testImmutableDifferentDisplayNameIsRejected(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('username');
		$user->method('canChangeDisplayName')->willReturn(false);
		$user->expects(self::never())->method('setDisplayName');

		$this->expectException(RuntimeException::class);
		MemberDirectoryCreationService::ensureInitialDisplayName($user, 'Test Member');
	}
}
