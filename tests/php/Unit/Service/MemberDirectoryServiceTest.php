<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Tests\Unit\Service;

use OCA\ProfileFields\Db\FieldDefinition;
use OCA\ProfileFields\Db\FieldValue;
use OCA\ProfileFields\Service\FieldDefinitionService;
use OCA\ProfileFields\Service\FieldValueService;
use OCA\ProfileFields\Service\MemberDirectoryAccessService;
use OCA\ProfileFields\Service\MemberDirectoryConfig;
use OCA\ProfileFields\Service\MemberDirectoryConflictException;
use OCA\ProfileFields\Service\MemberDirectoryService;
use OCP\IGroupManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

class MemberDirectoryServiceTest extends TestCase {
	private function makeDefinition(): FieldDefinition {
		$definition = new FieldDefinition();
		$definition->setId(7);
		$definition->setFieldKey('member_number');
		return $definition;
	}

	public function testLocksAcrossCheckAndWriteAndPreservesVisibility(): void {
		$definition = $this->makeDefinition();
		$previous = new FieldValue();
		$previous->setValueJson('{"value":"old"}');
		$previous->setCurrentVisibility('private');
		$previous->setUpdatedByUid('member');
		$previous->setUpdatedAt(new \DateTime('2026-11-15T12:00:00+00:00'));

		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canWrite')->willReturn(true);
		$access->method('isMember')->willReturn(true);
		$access->method('isAllowedField')->willReturn(true);

		$lock = $this->createMock(ILockingProvider::class);
		$events = [];
		$lock->expects(self::once())->method('acquireLock')
			->with(self::callback(static function (string $key) use (&$events): bool {
				$events[] = 'lock';
				return str_starts_with($key, 'profile_fields:member_directory:');
			}), ILockingProvider::LOCK_EXCLUSIVE);
		$lock->expects(self::once())->method('releaseLock')
			->with(self::isType('string'), ILockingProvider::LOCK_EXCLUSIVE)
			->willReturnCallback(static function () use (&$events): void { $events[] = 'unlock'; });

		$values = $this->createMock(FieldValueService::class);
		$values->expects(self::once())->method('findByFieldDefinitionIdAndUserUid')
			->with(7, 'member')->willReturnCallback(static function () use (&$events, $previous): FieldValue {
				$events[] = 'read';
				return $previous;
			});
		$values->expects(self::once())->method('upsert')
			->with($definition, 'member', 'new', 'editor', 'private')
			->willReturnCallback(static function () use (&$events, $previous): FieldValue {
				$events[] = 'write';
				return $previous;
			});
		$values->method('serializeForResponse')->willReturn([
			'value' => ['value' => 'new'],
			'updated_at' => '2026-11-15T12:00:00+00:00',
		]);

		$service = new MemberDirectoryService(
			$this->createMock(MemberDirectoryConfig::class), $access,
			$this->createMock(IGroupManager::class),
			$this->createMock(FieldDefinitionService::class), $values, $lock,
		);
		$result = $service->upsertValue($definition, 'member', 'new', 'editor', MemberDirectoryService::etag($previous));
		self::assertSame('new', $result['value']);
		self::assertSame(['lock', 'read', 'write', 'unlock'], $events);
	}

	public function testReleasesLockOnStaleEtagWithoutWriting(): void {
		$definition = $this->makeDefinition();
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canWrite')->willReturn(true);
		$access->method('isMember')->willReturn(true);
		$access->method('isAllowedField')->willReturn(true);

		$lock = $this->createMock(ILockingProvider::class);
		$lock->expects(self::once())->method('acquireLock');
		$lock->expects(self::once())->method('releaseLock');
		$values = $this->createMock(FieldValueService::class);
		$values->method('findByFieldDefinitionIdAndUserUid')->willReturn(null);
		$values->expects(self::never())->method('upsert');

		$service = new MemberDirectoryService(
			$this->createMock(MemberDirectoryConfig::class), $access,
			$this->createMock(IGroupManager::class),
			$this->createMock(FieldDefinitionService::class), $values, $lock,
		);
		$this->expectException(MemberDirectoryConflictException::class);
		$service->upsertValue($definition, 'member', 'new', 'editor', 'stale');
	}
}
