<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Tests\Unit\Service;

use OCA\ProfileFields\Db\FieldDefinition;
use OCA\ProfileFields\Service\FieldDefinitionService;
use OCA\ProfileFields\Service\MemberDirectorySetupService;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

class MemberDirectorySetupServiceTest extends TestCase {
	private function field(array $spec, ?string $overrideKey = null): FieldDefinition {
		$field = new FieldDefinition();
		$field->setFieldKey($overrideKey ?? $spec['field_key']);
		$field->setLabel($spec['label']);
		$field->setType($spec['type']);
		$field->setEditPolicy($spec['edit_policy']);
		$field->setExposurePolicy($spec['exposure_policy']);
		$field->setActive(true);
		$field->setOptions(isset($spec['options']) ? json_encode($spec['options'], JSON_THROW_ON_ERROR) : null);
		return $field;
	}

	public function testFreshInstallationSuggestsFourteenMissingFields(): void {
		$definitions = $this->createMock(FieldDefinitionService::class);
		$definitions->method('findAllOrdered')->willReturn([]);
		$setup = new MemberDirectorySetupService($definitions, $this->createMock(ILockingProvider::class));

		$preview = $setup->preview();
		self::assertSame(14, $preview['missing_count']);
		self::assertSame(0, $preview['conflicts']);
		self::assertTrue($preview['can_apply']);
		self::assertFalse($preview['ready']);
		self::assertCount(14, MemberDirectorySetupService::requiredKeys());
	}

	public function testMatchingExistingDefinitionsAreNeverModified(): void {
		$existing = array_map($this->field(...), MemberDirectorySetupService::defaults());
		$definitions = $this->createMock(FieldDefinitionService::class);
		$definitions->method('findAllOrdered')->willReturn($existing);
		$definitions->method('findByFieldKey')->willReturnCallback(
			static function (string $key) use ($existing): ?FieldDefinition {
				foreach ($existing as $field) {
					if ($field->getFieldKey() === $key) {
						return $field;
					}
				}
				return null;
			},
		);
		$definitions->expects(self::never())->method('create');
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects(self::once())->method('acquireLock');
		$locks->expects(self::once())->method('releaseLock');

		$setup = new MemberDirectorySetupService($definitions, $locks);
		self::assertTrue($setup->apply()['ready']);
	}

	public function testDifferentTechnicalKeyWithSameLabelBlocksSetup(): void {
		$spec = MemberDirectorySetupService::defaults()[0];
		$definitions = $this->createMock(FieldDefinitionService::class);
		$definitions->method('findAllOrdered')->willReturn([$this->field($spec, 'first_name')]);
		$definitions->expects(self::never())->method('create');
		$setup = new MemberDirectorySetupService($definitions, $this->createMock(ILockingProvider::class));

		$preview = $setup->preview();
		self::assertSame(1, $preview['conflicts']);
		self::assertContains('first_name', $preview['fields'][0]['similar_keys']);
		$this->expectException(\InvalidArgumentException::class);
		$setup->apply();
	}

	public function testWrongExistingTypeIsNotOverwritten(): void {
		$spec = MemberDirectorySetupService::defaults()[0];
		$wrong = $this->field($spec);
		$wrong->setType('date');
		$definitions = $this->createMock(FieldDefinitionService::class);
		$definitions->method('findAllOrdered')->willReturn([$wrong]);
		$definitions->expects(self::never())->method('create');
		$setup = new MemberDirectorySetupService($definitions, $this->createMock(ILockingProvider::class));
		self::assertSame('conflict', $setup->preview()['fields'][0]['status']);
	}

	public function testMissingFieldsAreCreatedOnceAndSecondRunIsNoOp(): void {
		$stored = [];
		$definitions = $this->createMock(FieldDefinitionService::class);
		$definitions->method('findAllOrdered')->willReturnCallback(static function () use (&$stored): array { return array_values($stored); });
		$definitions->method('findByFieldKey')->willReturnCallback(
			static function (string $key) use (&$stored): ?FieldDefinition { return $stored[$key] ?? null; },
		);
		$definitions->expects(self::exactly(14))->method('create')->willReturnCallback(
			function (array $spec) use (&$stored): FieldDefinition {
				$field = $this->field($spec);
				$stored[$spec['field_key']] = $field;
				return $field;
			},
		);
		$setup = new MemberDirectorySetupService($definitions, $this->createMock(ILockingProvider::class));
		self::assertTrue($setup->apply()['ready']);
		self::assertTrue($setup->apply()['ready']);
		self::assertCount(14, $stored);
	}
}
