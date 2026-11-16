<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Tests\Unit\Service;

use OCA\ProfileFields\Service\MemberDirectoryCreationService;
use PHPUnit\Framework\TestCase;

class MemberDirectoryUsernameTest extends TestCase {
	public function testNextcloudCompatibleNamesWithSpacesAndUmlauts(): void {
		foreach (['Testosteron Tester', 'Mirko Hunger', 'Jörg Müller', 'user.name', 'mail@name', "O'Connor", 'Anna-Maria_27'] as $uid) {
			self::assertTrue(MemberDirectoryCreationService::isSupportedUserId($uid), $uid);
		}
	}

	public function testRejectsControlCharactersAndSpecialPathCharacters(): void {
		foreach (['', "A\nB", 'bad/name', 'bad\\name', 'a:b', 'a<b', str_repeat('a', 65)] as $uid) {
			self::assertFalse(MemberDirectoryCreationService::isSupportedUserId($uid), $uid);
		}
	}
}
