<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Tests\Unit\Service;

use OCA\ProfileFields\Service\MemberDirectoryCreationService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MemberDirectoryDisplayNameTest extends TestCase {
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
