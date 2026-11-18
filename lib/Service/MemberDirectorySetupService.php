<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Service;

use InvalidArgumentException;
use OCA\ProfileFields\Db\FieldDefinition;
use OCP\Lock\ILockingProvider;

/**
 * Safe, repeatable initial setup for the optional member directory.
 *
 * Never changes an existing definition or a member value. A matching label
 * under a different technical key requires explicit administrator review:
 * silently creating a second definition would split existing member data.
 */
class MemberDirectorySetupService {
	private const LOCK_KEY = 'profile_fields:member_directory:setup';

	public function __construct(
		private FieldDefinitionService $definitions,
		private ILockingProvider $locks,
	) {
	}

	/** @return list<array<string, mixed>> */
	public static function defaults(): array {
		$common = ['edit_policy' => 'users', 'exposure_policy' => 'private', 'active' => true];
		$admin = ['edit_policy' => 'admins', 'exposure_policy' => 'private', 'active' => true];

		return [
			['field_key' => 'vereins_vorname', 'label' => 'Vorname', 'type' => 'text', 'sort_order' => 1000] + $common,
			['field_key' => 'vereins_nachname', 'label' => 'Nachname', 'type' => 'text', 'sort_order' => 1010] + $common,
			['field_key' => 'vereins_strasse', 'label' => 'Straße', 'type' => 'text', 'sort_order' => 1020] + $common,
			['field_key' => 'vereins_hausnummer', 'label' => 'Hausnummer', 'type' => 'text', 'sort_order' => 1030] + $common,
			['field_key' => 'vereins_plz', 'label' => 'PLZ', 'type' => 'text', 'sort_order' => 1040] + $common,
			['field_key' => 'vereins_ort', 'label' => 'Ort', 'type' => 'text', 'sort_order' => 1050] + $common,
			['field_key' => 'vereins_land', 'label' => 'Land', 'type' => 'select', 'options' => ['DE', 'AT', 'CH', 'PL', 'NL', 'CZ', 'FR', 'ES', 'IT', 'SI'], 'sort_order' => 1060] + $common,
			['field_key' => 'mitgliedsnummer', 'label' => 'Mitgliedsnummer', 'type' => 'text', 'sort_order' => 1070] + $admin,
			['field_key' => 'eintrittsdatum', 'label' => 'Eintrittsdatum', 'type' => 'date', 'sort_order' => 1080] + $admin,
			['field_key' => 'beitragsfrei', 'label' => 'Beitragsfrei', 'type' => 'boolean', 'sort_order' => 1090] + $admin,
			['field_key' => 'beitragsart', 'label' => 'Beitragsart', 'type' => 'select', 'options' => ['Vollmitglied', 'Ermäßigt', 'Jugend', 'Ehrenmitglied'], 'sort_order' => 1100] + $admin,
			['field_key' => 'vereins_telefon', 'label' => 'Telefonnummer', 'type' => 'text', 'sort_order' => 1110] + $common,
			['field_key' => 'datenschutz_hinweise_erhalten', 'label' => 'Datenschutzhinweise erhalten', 'type' => 'select', 'options' => ['Ja', 'Nein'], 'sort_order' => 1120] + $admin,
			['field_key' => 'social_media_kontakt_erlaubt', 'label' => 'Social-Media-Kontakt erlaubt', 'type' => 'select', 'options' => ['Ja', 'Nein'], 'sort_order' => 1130] + $admin,
			['field_key' => 'social_media_profil', 'label' => 'Social-Media-Profil / Kontakt', 'type' => 'text', 'sort_order' => 1140] + $common,
		];
	}

	/** @return list<string> */
	public static function requiredKeys(): array {
		return array_column(self::defaults(), 'field_key');
	}

	/**
	 * @return array{ready: bool, can_apply: bool, missing_count: int, conflicts: int, fields: list<array{field_key: string, label: string, status: string, message: string, similar_keys: list<string>}>}
	 */
	public function preview(): array {
		$all = $this->definitions->findAllOrdered();
		$byKey = [];
		foreach ($all as $field) {
			$byKey[$field->getFieldKey()] = $field;
		}
		$rows = [];
		$missing = 0;
		$conflicts = 0;
		foreach (self::defaults() as $spec) {
			$key = $spec['field_key'];
			$existing = $byKey[$key] ?? null;
			$status = 'existing';
			$message = 'Technischer Schlüssel und Felddefinition stimmen überein.';
			$similar = [];
			if ($existing !== null) {
				$issue = $this->compatibilityIssue($existing, $spec);
				if ($issue !== null) {
					$status = 'conflict';
					$message = $issue;
				}
			} else {
				foreach ($all as $candidate) {
					if ($this->normalizeLabel($candidate->getLabel()) === $this->normalizeLabel($spec['label'])
						&& $candidate->getFieldKey() !== $key) {
						$similar[] = $candidate->getFieldKey();
					}
				}
				if ($similar !== []) {
					$status = 'conflict';
					$message = 'Gleichnamiges Feld mit anderem technischen Schlüssel gefunden. Zuordnung vor dem Anlegen manuell prüfen.';
				} else {
					$status = 'missing';
					$message = 'Wird beim Einrichten automatisch angelegt.';
					$missing++;
				}
			}
			if ($status === 'conflict') {
				$conflicts++;
			}
			$rows[] = [
				'field_key' => $key,
				'label' => $spec['label'],
				'status' => $status,
				'message' => $message,
				'similar_keys' => $similar,
			];
		}

		return [
			'ready' => $missing === 0 && $conflicts === 0,
			'can_apply' => $conflicts === 0,
			'missing_count' => $missing,
			'conflicts' => $conflicts,
			'fields' => $rows,
		];
	}

	/**
	 * Create missing definitions only. Check all conflicts first, then
	 * re-evaluate while holding the lock so retries are idempotent.
	 *
	 * @return array{ready: bool, can_apply: bool, missing_count: int, conflicts: int, fields: list<array{field_key: string, label: string, status: string, message: string, similar_keys: list<string>}>}
	 */
	public function apply(): array {
		$this->locks->acquireLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$preview = $this->preview();
			if (!$preview['can_apply']) {
				throw new InvalidArgumentException('Die Standardfelder enthalten Konflikte. Bitte zuerst die Felddefinitionen prüfen.');
			}
			foreach (self::defaults() as $spec) {
				if ($this->definitions->findByFieldKey($spec['field_key']) === null) {
					$this->definitions->create($spec);
				}
			}
			return $this->preview();
		} finally {
			$this->locks->releaseLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @param array<string, mixed> $spec */
	private function compatibilityIssue(FieldDefinition $field, array $spec): ?string {
		if (!$field->getActive()) {
			return 'Das vorhandene Feld ist deaktiviert.';
		}
		if ($field->getType() !== $spec['type']) {
			return 'Abweichender Feldtyp: ' . $field->getType() . ' statt ' . $spec['type'] . '.';
		}
		if ($field->getExposurePolicy() !== 'private') {
			return 'Die vorhandene Sichtbarkeitsrichtlinie ist nicht privat.';
		}
		if ($field->getEditPolicy() !== $spec['edit_policy']) {
			return 'Abweichende Bearbeitungsrichtlinie: ' . $field->getEditPolicy() . '.';
		}
		if ($spec['type'] === 'select') {
			$options = json_decode($field->getOptions() ?? 'null', true);
			if (!is_array($options) || array_diff($spec['options'], $options) !== []) {
				return 'Auswahlliste enthält nicht alle Standardwerte.';
			}
		}
		return null;
	}

	private function normalizeLabel(string $label): string {
		return mb_strtolower(trim($label));
	}
}
