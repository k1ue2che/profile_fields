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
use OCA\ProfileFields\Service\MemberDirectoryConflictException;
use OCA\ProfileFields\Service\MemberNumberService;
use PHPUnit\Framework\TestCase;

class MemberNumberServiceTest extends TestCase {
	private function field(): FieldDefinition {
		$field = new FieldDefinition();
		$field->setFieldKey('mitgliedsnummer');
		$field->setActive(true);
		return $field;
	}

	private function stored(string $uid, mixed $value): FieldValue {
		$stored = new FieldValue();
		$stored->setUserUid($uid);
		$stored->setValueJson(json_encode(['value' => $value], JSON_THROW_ON_ERROR));
		return $stored;
	}

	/** @param list<FieldValue> $records */
	private function service(array $records, bool $canCreate = true, bool $canWrite = true): MemberNumberService {
		$field = $this->field();
		$definitions = $this->createMock(FieldDefinitionService::class);
		$definitions->method('findByFieldKey')->with('mitgliedsnummer')->willReturn($field);
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canCreate')->willReturn($canCreate);
		$access->method('canWriteField')->willReturn($canWrite);
		$values = $this->createMock(FieldValueService::class);
		$values->method('findByDefinition')->willReturn($records);
		return new MemberNumberService($definitions, $values, $access);
	}

	public function testUsesHighestNumericNumberAndDoesNotReuseGaps(): void {
		$service = $this->service([
			$this->stored('a', '1'),
			$this->stored('b', '2'),
			$this->stored('c', '004'),
			$this->stored('d', 8),
			$this->stored('e', 'legacy-x'),
			$this->stored('f', null),
		]);
		self::assertSame('9', $service->suggest('admin'));
	}

	public function testStartsWithOneWithoutNumberedMembers(): void {
		self::assertSame('1', $this->service([])->suggest('admin'));
	}

	public function testRequiresCreationAndFieldWritePermission(): void {
		self::assertNull($this->service([], false)->suggest('viewer'));
		self::assertNull($this->service([], true, false)->suggest('viewer'));
	}

	public function testRejectsDuplicateDespiteLeadingZeroes(): void {
		$service = $this->service([$this->stored('a', '004')]);
		$this->expectException(MemberDirectoryConflictException::class);
		$service->assertAvailable($this->field(), '4');
	}

	public function testRejectsZeroAndNonNumericMemberNumber(): void {
		$service = $this->service([]);
		foreach (['0', 'abc', '1.5', '-2'] as $candidate) {
			try {
				$service->assertAvailable($this->field(), $candidate);
				self::fail('Expected invalid member number: ' . $candidate);
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	public function testCanIgnoreExistingValueForSameUserWhenChecking(): void {
		$service = $this->service([$this->stored('same', 5)]);
		$service->assertAvailable($this->field(), '005', 'same');
		self::assertTrue(true);
	}
}
