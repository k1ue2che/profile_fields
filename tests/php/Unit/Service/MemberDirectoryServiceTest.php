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
	public function testRejectsUnauthorizedSortFieldBeforeReturningMemberData(): void {
		$config = $this->createMock(MemberDirectoryConfig::class);
		$config->method('enabled')->willReturn(true);
		$config->method('baseGroup')->willReturn('LHR');
		$group = $this->createMock(\OCP\IGroup::class);
		$group->expects(self::never())->method('getUsers');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('LHR')->willReturn($group);
		$definitions = $this->createMock(FieldDefinitionService::class);
		$definitions->method('findActiveOrdered')->willReturn([]);
		$service = new MemberDirectoryService($config,
			$this->createMock(MemberDirectoryAccessService::class), $groups,
			$definitions, $this->createMock(FieldValueService::class),
			$this->createMock(ILockingProvider::class));
		$this->expectException(\InvalidArgumentException::class);
		$service->listMembers(limit: 50, actorUid: 'reader', sortBy: 'mitgliedsnummer');
	}

	public function testSortsBeforePaginationAndSupportsAllMatchingMembers(): void {
		$config = $this->createMock(MemberDirectoryConfig::class);
		$config->method('enabled')->willReturn(true);
		$config->method('baseGroup')->willReturn('LHR');
		$config->method('departments')->willReturn([]);
		$definition = new FieldDefinition();
		$definition->setId(7);
		$definition->setFieldKey('mitgliedsnummer');
		$definition->setLabel('Mitgliedsnummer');
		$definition->setType('text');
		$definition->setActive(true);
		$definition->setOptions(null);
		$definitions = $this->createMock(FieldDefinitionService::class);
		$definitions->method('findActiveOrdered')->willReturn([$definition]);
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('isMember')->willReturn(true);
		$access->method('matchesDepartment')->willReturn(true);
		$access->method('canReadField')->willReturn(true);
		$memberNumbers = ['a' => '10', 'b' => '2', 'c' => '5'];
		$members = [];
		foreach (['a', 'b', 'c'] as $uid) {
			$user = $this->createMock(\OCP\IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn('Member ' . $uid);
			$members[] = $user;
		}
		$group = $this->createMock(\OCP\IGroup::class);
		$group->method('getUsers')->willReturn($members);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('LHR')->willReturn($group);
		$values = $this->createMock(FieldValueService::class);
		$values->method('findByUserUid')->willReturnCallback(static function (string $uid) use ($memberNumbers): array {
			$value = new FieldValue();
			$value->setFieldDefinitionId(7);
			$value->setValueJson(json_encode(['value' => $memberNumbers[$uid]], JSON_THROW_ON_ERROR));
			$value->setCurrentVisibility('private');
			$value->setUpdatedByUid('editor');
			$value->setUpdatedAt(new \DateTime('2026-11-18T12:00:00+00:00'));
			return [$value];
		});
		$values->method('serializeForResponse')->willReturnCallback(static fn (FieldValue $value): array => [
			'value' => json_decode($value->getValueJson(), true),
			'updated_at' => '2026-11-18T12:00:00+00:00',
		]);
		$service = new MemberDirectoryService($config, $access, $groups, $definitions,
			$values, $this->createMock(ILockingProvider::class));

		$first = $service->listMembers(limit: 1, actorUid: 'reader', sortBy: 'mitgliedsnummer', sortDirection: 'asc');
		self::assertSame(3, $first['pagination']['total']);
		self::assertSame(['b'], array_column($first['items'], 'user_uid'));
		$second = $service->listMembers(limit: 1, offset: 1, actorUid: 'reader', sortBy: 'mitgliedsnummer', sortDirection: 'asc');
		self::assertSame(['c'], array_column($second['items'], 'user_uid'));
		$all = $service->listMembers(limit: 0, actorUid: 'reader', sortBy: 'mitgliedsnummer', sortDirection: 'desc');
		self::assertSame(0, $all['pagination']['limit']);
		self::assertSame(['a', 'c', 'b'], array_column($all['items'], 'user_uid'));
	}

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
		$access->method('canWriteField')->willReturn(true);

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
		$access->method('canWriteField')->willReturn(true);

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
