<!-- SPDX-FileCopyrightText: 2026 profile_fields contributors
SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<section class="member-settings">
		<h2>Vereinsmitgliederverwaltung</h2>
		<p>Lege fest, wer welche zusätzlichen Profilfelder in der Mitgliederliste sehen und bearbeiten darf. Die Mitgliederverwaltung bleibt bis zur Aktivierung gesperrt.</p>
		<p v-if="error" role="alert" class="error">{{ error }}</p>
		<p v-if="notice" role="status" class="success">{{ notice }}</p>
		<div v-if="loading">Einstellungen werden geladen …</div>
		<form v-else-if="settings" @submit.prevent="save">
			<label><input v-model="settings.enabled" type="checkbox"> Mitgliederverwaltung aktivieren</label>
			<label>Basisgruppe (Mitglieder)
				<input v-model.trim="settings.baseGroup" list="member-groups" placeholder="z. B. LHReV">
			</label>
			<label>Gruppen mit Leserechten
				<select v-model="settings.readGroups" multiple size="5">
					<option v-for="group in availableGroups" :key="group" :value="group">{{ group }}</option>
				</select>
			</label>
			<label>Gruppen mit Schreibrechten
				<select v-model="settings.writeGroups" multiple size="5">
					<option v-for="group in availableGroups" :key="group" :value="group">{{ group }}</option>
				</select>
			</label>
			<label>Abteilungen für Filter
				<select v-model="settings.departments" multiple size="5">
					<option v-for="group in availableGroups" :key="group" :value="group">{{ group }}</option>
				</select>
			</label>
			<fieldset>
				<legend>Freigegebene Profilfelder</legend>
				<label v-for="field in availableFields.filter(f => f.active)" :key="field.id">
					<input v-model="settings.fieldKeys" type="checkbox" :value="field.field_key"> {{ field.label }}
				</label>
			</fieldset>
			<fieldset v-if="settings.fieldKeys.length">
				<legend>Feldberechtigungen je Gruppe</legend>
				<p class="hint">Ohne ausdrückliche Feldfreigabe werden keine Werte angezeigt oder bearbeitet. Schreibrechte erlauben auch das Lesen dieses Felds.</p>
				<div v-for="key in settings.fieldKeys" :key="key" class="field-grant">
					<strong>{{ availableFields.find(field => field.field_key === key)?.label || key }}</strong>
					<label>Lesen
						<select v-model="grantFor(key).readGroups" multiple size="4">
							<option v-for="group in readOrWriteGroups" :key="group" :value="group">{{ group }}</option>
						</select>
					</label>
					<label>Bearbeiten
						<select v-model="grantFor(key).writeGroups" multiple size="4">
							<option v-for="group in settings.writeGroups" :key="group" :value="group">{{ group }}</option>
						</select>
					</label>
				</div>
			</fieldset>
			<datalist id="member-groups"><option v-for="group in availableGroups" :key="group" :value="group" /></datalist>
			<p class="hint">Alle Änderungen an Gruppenrechten gelten sofort. Personen mit Schreibrechten dürfen ausschließlich die angekreuzten Felder bearbeiten. Die Einstellung ist nur für Nextcloud-Administratoren zugänglich.</p>
			<button :disabled="saving" type="submit">{{ saving ? 'Speichere …' : 'Einstellungen speichern' }}</button>
		</form>
	</section>
</template>
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { apiErrorMessage, fetchAvailableFields, fetchMemberSettings, saveMemberSettings, type AvailableField, type MemberDirectorySettingsPayload } from '../member-directory-api'

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
<style scoped>
.member-settings{padding:18px 0 26px;max-width:840px;display:grid;gap:12px}
form{display:grid;gap:18px}
form>label{display:grid;gap:7px;font-weight:600}
form>label:first-child{display:flex;align-items:center}
input:not([type="checkbox"]),select{max-width:540px;padding:8px;border:1px solid var(--color-border);background:var(--color-main-background);color:var(--color-main-text)}
select{min-height:120px}
fieldset{display:grid;gap:8px;border:1px solid var(--color-border);padding:14px;max-width:540px}
fieldset label{display:block}
.field-grant{display:grid;gap:8px;border-top:1px solid var(--color-border);padding:12px 0}
.field-grant label{display:grid;gap:4px}
.hint{font-size:13px;color:var(--color-text-maxcontrast)}
.error{color:var(--color-error-text)}.success{color:var(--color-success-text)}
button{justify-self:start;padding:10px 16px}
</style>
