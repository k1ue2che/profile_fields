<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Service;

use InvalidArgumentException;
use LogicException;
use OCA\ProfileFields\Db\FieldDefinition;
use RuntimeException;

/**
 * Global member number sequence. Numbers are never reused just because a gap
 * exists; legacy numeric values (including strings with leading zeroes) count.
 *
 * All writes through the member creation flow must check availability while
 * holding the member-directory creation lock.
 */
class MemberNumberService {
	public const FIELD_KEY = 'mitgliedsnummer';

	public function __construct(
		private FieldDefinitionService $definitions,
		private FieldValueService $values,
		private MemberDirectoryAccessService $access,
	) {
	}

	public function writableDefinition(?string $actorUid): ?FieldDefinition {
		if (!$this->access->canCreate($actorUid)) {
			return null;
		}
		$definition = $this->definitions->findByFieldKey(self::FIELD_KEY);
		return $definition !== null && $this->access->canWriteField($actorUid, $definition)
			? $definition
			: null;
	}

	public function suggest(?string $actorUid): ?string {
		$definition = $this->writableDefinition($actorUid);
		if ($definition === null) {
			return null;
		}

		$max = '0';
		foreach ($this->values->findByDefinition($definition) as $stored) {
			$number = self::storedNumber($stored->getValueJson());
			if ($number !== null && self::compare($number, $max) > 0) {
				$max = $number;
			}
		}

		if (self::compare($max, (string)PHP_INT_MAX) >= 0) {
			throw new RuntimeException('Die Mitgliedsnummer liegt außerhalb des unterstützten Zahlenbereichs.');
		}

		return (string)((int)$max + 1);
	}

	/**
	 * Enforce numeric uniqueness even when legacy records use leading zeroes.
	 * Call under an exclusive lock before inserting a new member number.
	 */
	public function assertAvailable(FieldDefinition $definition, mixed $rawNumber, ?string $excludeUid = null): void {
		if ($definition->getFieldKey() !== self::FIELD_KEY) {
			throw new LogicException('Ungültiges Feld für die Mitgliedsnummerprüfung.');
		}
		$number = self::canonical($rawNumber);
		if ($number === null) {
			throw new InvalidArgumentException('Die Mitgliedsnummer muss eine positive ganze Zahl sein.');
		}
		foreach ($this->values->findByDefinition($definition) as $stored) {
			if ($stored->getUserUid() === $excludeUid) {
				continue;
			}
			if (self::storedNumber($stored->getValueJson()) === $number) {
				throw new MemberDirectoryConflictException('Diese Mitgliedsnummer ist bereits vergeben.');
			}
		}
	}

	private static function storedNumber(string $json): ?string {
		$data = json_decode($json, true);
		return is_array($data) ? self::canonical($data['value'] ?? null) : null;
	}

	private static function canonical(mixed $value): ?string {
		if (!is_string($value) && !is_int($value)) {
			return null;
		}
		$number = trim((string)$value);
		if (preg_match('/^[0-9]+$/D', $number) !== 1) {
			return null;
		}
		$number = ltrim($number, '0');
		return $number !== '' ? $number : null;
	}

	private static function compare(string $left, string $right): int {
		return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
	}
}
