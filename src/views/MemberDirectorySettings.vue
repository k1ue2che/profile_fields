<!-- SPDX-FileCopyrightText: 2026 profile_fields contributors
SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<section class="member-settings">
		<header class="member-settings-header">
			<div>
				<h2>Vereinsmitgliederverwaltung</h2>
				<p>Mitgliederübersicht und Zugriffsrechte für zusätzliche Profilfelder verwalten.</p>
			</div>
			<a :href="memberDirectoryUrl" class="member-directory-link">Mitgliederliste öffnen</a>
		</header>
		<p v-if="error" role="alert" class="member-settings-error">{{ error }}</p>
		<p v-if="notice" role="status" class="member-settings-success">{{ notice }}</p>
		<div v-if="loading">Einstellungen werden geladen …</div>
		<form v-else-if="settings" @submit.prevent="save">
			<section class="member-settings-panel">
				<h3>1. Allgemeine Einstellungen</h3>
				<label class="member-toggle"><input v-model="settings.enabled" type="checkbox"> Mitgliederverwaltung aktivieren</label>
				<p class="member-settings-hint">Bis zur Aktivierung ist die Mitgliederliste für alle Benutzer gesperrt. Änderungen werden erst mit „Einstellungen speichern“ übernommen.</p>
				<div class="member-settings-two-col">
					<label class="member-settings-control">Basisgruppe (Vereinsmitglieder)
						<input v-model.trim="settings.baseGroup" list="member-groups" placeholder="z. B. LHReV">
					</label>
					<label class="member-settings-control">Abteilungen für Filter
						<select v-model="settings.departments" multiple size="4">
							<option v-for="group in availableGroups" :key="group" :value="group">{{ group }}</option>
						</select>
					</label>
				</div>
			</section>

			<section class="member-settings-panel">
				<h3>2. Zugriff auf die Mitgliederliste</h3>
				<p class="member-settings-hint">Diese Gruppen dürfen die Liste öffnen. Welche einzelnen Profilfelder sichtbar oder bearbeitbar sind, wird zusätzlich in Schritt 4 festgelegt.</p>
				<div class="member-settings-two-col">
					<label class="member-settings-control">Gruppen mit Leserechten
						<select v-model="settings.readGroups" multiple size="5">
							<option v-for="group in availableGroups" :key="group" :value="group">{{ group }}</option>
						</select>
					</label>
					<label class="member-settings-control">Gruppen mit Schreibrechten
						<select v-model="settings.writeGroups" multiple size="5">
							<option v-for="group in availableGroups" :key="group" :value="group">{{ group }}</option>
						</select>
					</label>
				</div>
				<p class="member-settings-hint">Mehrfachauswahl mit Strg/Ctrl oder Umschalt. Die Gruppe „Buchhaltung“ erhält keine Rechte, sofern sie nicht ausdrücklich ausgewählt wird.</p>
			</section>

			<section class="member-settings-panel">
				<h3>3. Profilfelder in der Mitgliederliste</h3>
				<div class="member-settings-field-grid">
					<label v-for="field in availableFields.filter(f => f.active)" :key="field.id" class="member-settings-check">
						<input v-model="settings.fieldKeys" type="checkbox" :value="field.field_key">
						<span>{{ field.label }}</span>
					</label>
				</div>
				<p class="member-settings-hint">Nur die markierten Profilfelder können in der Mitgliederliste erscheinen.</p>
			</section>

			<section v-if="settings.fieldKeys.length" class="member-settings-panel">
				<h3>4. Feldberechtigungen je Gruppe</h3>
				<p class="member-settings-hint">Ein Feld wird nur angezeigt, wenn mindestens eine entsprechende Gruppe ausdrücklich berechtigt ist. Eine Schreibberechtigung schließt das Lesen ein.</p>
				<div class="member-settings-table-wrap">
					<table class="member-settings-table">
						<thead>
							<tr><th scope="col">Profilfeld</th><th scope="col">Lesen</th><th scope="col">Bearbeiten</th></tr>
						</thead>
						<tbody>
							<tr v-for="key in settings.fieldKeys" :key="key">
								<th scope="row">{{ availableFields.find(field => field.field_key === key)?.label || key }}</th>
								<td>
									<div v-if="readOrWriteGroups.length" class="member-settings-grants">
										<label v-for="group in readOrWriteGroups" :key="group" class="member-settings-check">
											<input v-model="grantFor(key).readGroups" type="checkbox" :value="group"> {{ group }}
										</label>
									</div>
									<span v-else class="member-settings-hint">Keine Lesegruppen ausgewählt</span>
								</td>
								<td>
									<div v-if="settings.writeGroups.length" class="member-settings-grants">
										<label v-for="group in settings.writeGroups" :key="group" class="member-settings-check">
											<input v-model="grantFor(key).writeGroups" type="checkbox" :value="group"> {{ group }}
										</label>
									</div>
									<span v-else class="member-settings-hint">Keine Schreibgruppen ausgewählt</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
			<datalist id="member-groups"><option v-for="group in availableGroups" :key="group" :value="group" /></datalist>
			<div class="member-settings-actions">
				<button :disabled="saving" type="submit">{{ saving ? 'Speichere …' : 'Einstellungen speichern' }}</button>
				<span class="member-settings-hint">Berechtigungen gelten unmittelbar nach dem Speichern.</span>
			</div>
		</form>
	</section>
</template>
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { apiErrorMessage, fetchAvailableFields, fetchMemberSettings, saveMemberSettings, type AvailableField, type MemberDirectorySettingsPayload } from '../member-directory-api'

const memberDirectoryUrl = generateUrl('/apps/profile_fields/members')
const settings = ref<MemberDirectorySettingsPayload | null>(null)
const availableFields = ref<AvailableField[]>([])
const availableGroups = ref<string[]>([])
const error = ref('')
const notice = ref('')
const saving = ref(false)
const loading = ref(true)
const readOrWriteGroups = computed(() => [...new Set([...(settings.value?.readGroups || []), ...(settings.value?.writeGroups || [])])])
function grantFor(key: string) {
	if (!settings.value) throw new Error('Einstellungen nicht geladen')
	return settings.value.fieldPermissions[key] ??= { readGroups: [], writeGroups: [] }
}
async function load() {
	try {
		const [current, fields, groups] = await Promise.all([
			fetchMemberSettings(),
			fetchAvailableFields(),
			axios.get<{ ocs: { data: { groups: string[] } } }>(generateOcsUrl('/cloud/groups'), { headers: { 'OCS-APIRequest': 'true' } }),
		])
		settings.value = {
			enabled: current.enabled, baseGroup: current.baseGroup || '',
			readGroups: current.readGroups, writeGroups: current.writeGroups,
			departments: current.departments, fieldKeys: current.fieldKeys,
			fieldPermissions: current.fieldPermissions || {},
		}
		availableFields.value = fields
		availableGroups.value = groups.data.ocs.data.groups
	} catch (e) { error.value = apiErrorMessage(e) } finally { loading.value = false }
}
async function save() {
	if (!settings.value) return
	saving.value = true
	error.value = ''
	notice.value = ''
	try {
		const permissions = Object.fromEntries(Object.entries(settings.value.fieldPermissions).filter(([key]) => settings.value?.fieldKeys.includes(key)))
		const current = await saveMemberSettings({ ...settings.value, fieldPermissions: permissions })
		settings.value.enabled = current.enabled
		notice.value = 'Einstellungen gespeichert.'
	} catch (e) { error.value = apiErrorMessage(e) } finally { saving.value = false }
}
onMounted(() => void load())
</script>
