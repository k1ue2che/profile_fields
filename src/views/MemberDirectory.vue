<!-- SPDX-FileCopyrightText: 2026 profile_fields contributors
SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<main class="member-directory">
		<header>
			<h2>Mitgliederverwaltung</h2>
			<p>Mitglieder der konfigurierten Vereinsgruppe verwalten</p>
		</header>
		<p v-if="error" role="alert" class="error">{{ error }}</p>
		<p v-if="notice" role="status" class="notice">{{ notice }}</p>
		<div class="toolbar">
			<label>Suche <input v-model="search" type="search" placeholder="Name, Benutzername oder Profilfeld" @keydown.enter="reload"></label>
			<label>Abteilung
				<select v-model="department" @change="reload">
					<option value="">Alle Abteilungen</option>
					<option v-for="group in page?.departments || []" :key="group" :value="group">{{ group }}</option>
				</select>
			</label>
			<button type="button" :disabled="loading" @click="reload">Suchen / Aktualisieren</button>
			<details v-if="page?.fields.length" class="columns">
				<summary>Spalten auswählen</summary>
				<label v-for="field in page.fields" :key="field.id" class="column-choice">
					<input v-model="shownKeys" type="checkbox" :value="field.field_key"> {{ field.label }}
				</label>
			</details>
		</div>
		<p v-if="loading">Mitglieder werden geladen …</p>
		<div v-if="page" class="table-scroll">
			<table>
				<thead><tr>
					<th>Mitglied</th><th>Abteilung</th>
					<th v-for="field in visibleFields" :key="field.id">{{ field.label }}</th>
				</tr></thead>
				<tbody>
					<tr v-for="member in page.items" :key="member.user_uid">
						<th scope="row">{{ member.display_name }}<small>{{ member.user_uid }}</small></th>
						<td>{{ member.departments.join(', ') || '–' }}</td>
						<td v-for="field in visibleFields" :key="field.id">
							<div v-if="editingKey === cellKey(member, field)">
								<select v-if="field.type === 'select'" v-model="draft">
									<option value="">–</option>
									<option v-for="option in field.options || []" :key="option" :value="option">{{ option }}</option>
								</select>
								<select v-else-if="field.type === 'boolean'" v-model="draft">
									<option :value="null">–</option><option :value="true">Ja</option><option :value="false">Nein</option>
								</select>
								<select v-else-if="field.type === 'multiselect'" v-model="draftMulti" multiple>
									<option v-for="option in field.options || []" :key="option" :value="option">{{ option }}</option>
								</select>
								<input v-else v-model="draft" :type="inputType(field)" aria-label="Profilfeld bearbeiten">
								<div class="actions">
									<button type="button" :disabled="saving" @click="save(member, field)">Speichern</button>
									<button type="button" :disabled="saving" @click="editingKey = null">Abbrechen</button>
								</div>
							</div>
							<button v-else-if="field.can_write" type="button" class="cell-button" :aria-label="`${field.label} von ${member.display_name} bearbeiten`" @click="startEdit(member, field)">
								{{ display(member.fields[field.field_key]?.value) }} ✎
							</button>
							<span v-else>{{ display(member.fields[field.field_key]?.value) }}</span>
						</td>
					</tr>
					<tr v-if="!page.items.length"><td :colspan="visibleFields.length + 2">Keine Mitglieder gefunden</td></tr>
				</tbody>
			</table>
		</div>
		<footer v-if="page">
			<span>{{ page.pagination.total }} Mitglieder</span>
			<button type="button" :disabled="loading || offset === 0" @click="changePage(-1)">Zurück</button>
			<span>Seite {{ Math.floor(offset / limit) + 1 }}</span>
			<button type="button" :disabled="loading || offset + limit >= page.pagination.total" @click="changePage(1)">Weiter</button>
		</footer>
	</main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { apiErrorMessage, fetchMembers, saveMemberField, type MemberCell, type MemberFieldDefinition, type MemberPage, type MemberRecord } from '../member-directory-api'

const page = ref<MemberPage | null>(null)
const department = ref('')
const search = ref('')
const offset = ref(0)
const limit = 50
const shownKeys = ref<string[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const editingKey = ref<string | null>(null)
const draft = ref<unknown>('')
const draftMulti = ref<string[]>([])
const visibleFields = computed(() => page.value?.fields.filter(f => shownKeys.value.includes(f.field_key)) || [])
const cellKey = (member: MemberRecord, field: MemberFieldDefinition) => JSON.stringify([member.user_uid, field.field_key])
const display = (value: unknown): string => value == null || value === '' ? '–' : Array.isArray(value) ? value.join(', ') : typeof value === 'boolean' ? (value ? 'Ja' : 'Nein') : String(value)
const inputType = (field: MemberFieldDefinition): string => {
	const inputTypes: Record<string, string> = { number: 'number', date: 'date', email: 'email', url: 'url' }
	return inputTypes[field.type] || 'text'
}
async function load() {
	loading.value = true
	error.value = ''
	try {
		const previousKeys = shownKeys.value
		page.value = await fetchMembers({ department: department.value, search: search.value, offset: offset.value, limit })
		shownKeys.value = previousKeys.length ? previousKeys.filter(k => page.value!.fields.some(f => f.field_key === k)) : page.value.fields.map(f => f.field_key)
		editingKey.value = null
	} catch (e) {
		error.value = apiErrorMessage(e)
		page.value = null
	} finally {
		loading.value = false
	}
}
function reload() { offset.value = 0; void load() }
function changePage(direction: number) { offset.value = Math.max(0, offset.value + direction * limit); void load() }
function startEdit(member: MemberRecord, field: MemberFieldDefinition) {
	editingKey.value = cellKey(member, field)
	const value = member.fields[field.field_key]?.value
	draft.value = value == null ? '' : value
	draftMulti.value = Array.isArray(value) ? [...value] : []
	error.value = ''
	notice.value = ''
}
async function save(member: MemberRecord, field: MemberFieldDefinition) {
	saving.value = true
	error.value = ''
	notice.value = ''
	try {
		const old: MemberCell = member.fields[field.field_key] || { value: null, etag: '', updated_at: null }
		const raw = field.type === 'multiselect' ? draftMulti.value : draft.value
		const updated = await saveMemberField(member.user_uid, field.id, raw, old.etag)
		member.fields[field.field_key] = updated
		editingKey.value = null
		notice.value = 'Änderung gespeichert.'
	} catch (e) {
		error.value = apiErrorMessage(e) + ' Bei Konflikten bitte aktualisieren.'
	} finally {
		saving.value = false
	}
}
onMounted(() => void load())
</script>

<style scoped>
.member-directory{padding:24px;max-width:100%;color:var(--color-main-text);display:grid;gap:18px}
header h2{font-size:24px;margin:0} header p{color:var(--color-text-maxcontrast)}
.toolbar{display:flex;flex-wrap:wrap;gap:14px;align-items:end}
.toolbar label{display:grid;gap:6px}
input,select{padding:8px;min-height:36px;max-width:320px;color:var(--color-main-text);background:var(--color-main-background);border:1px solid var(--color-border)}
button{padding:8px 12px;cursor:pointer}
button:disabled{opacity:.5;cursor:default}
.columns{position:relative;border:1px solid var(--color-border);border-radius:8px;padding:8px}
.column-choice{display:block!important;margin:6px 0}
.table-scroll{max-width:100%;overflow:auto}
table{border-collapse:collapse;min-width:760px;width:100%}
th,td{border-bottom:1px solid var(--color-border);padding:11px 12px;text-align:left;vertical-align:top;white-space:nowrap}
tbody th{font-weight:600}
small{display:block;font-weight:400;color:var(--color-text-maxcontrast)}
.cell-button{border:0;background:transparent;color:inherit;text-align:left}
.cell-button:hover{text-decoration:underline}
.actions{display:flex;gap:5px;margin-top:5px}
.error{color:var(--color-error-text)} .notice{color:var(--color-success-text)}
footer{display:flex;align-items:center;gap:16px}
</style>
