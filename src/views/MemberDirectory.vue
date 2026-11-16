<!-- SPDX-FileCopyrightText: 2026 profile_fields contributors
SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<main class="member-directory">
		<header>
			<div class="member-heading">
				<h2>Mitgliederverwaltung</h2>
				<button v-if="page?.can_create && page.creatable_departments.length" type="button" class="create-button" :disabled="loading || saving" @click="openCreateForm">+ Neues Mitglied</button>
			</div>
			<p>Die Benutzer-ID ist fest mit dem Nextcloud-Konto verknüpft und kann hier nicht geändert werden.</p>
		</header>
		<p v-if="error" role="alert" class="error">{{ error }}</p>
		<p v-if="notice" role="status" class="notice">{{ notice }}</p>
		<section v-if="createOpen" class="member-create" aria-label="Neues Mitglied anlegen">
			<div class="create-heading">
				<div><h3>Neues Mitglied</h3><p>Das Konto erhält die Basisgruppe und genau eine Abteilung. Die Einladung zur Passworteinrichtung erfolgt per E-Mail.</p></div>
				<button type="button" class="create-close" :disabled="saving" @click="createOpen = false">Schließen</button>
			</div>
			<form class="create-form" @submit.prevent="submitCreate">
				<fieldset>
					<legend>Persönliche Daten</legend>
					<div class="create-grid">
						<label>Vorname *<input v-model.trim="createDraft.firstName" required maxlength="255" autocomplete="off"></label>
						<label>Nachname *<input v-model.trim="createDraft.lastName" required maxlength="255" autocomplete="off"></label>
						<label>Kontoname * <small>Wird automatisch vorgeschlagen. Leerzeichen sind erlaubt; vor der Anlage änderbar.</small>
							<input v-model.trim="createDraft.userUid" required minlength="1" maxlength="64" title="1–64 Zeichen. Leerzeichen, Buchstaben, Zahlen, Umlaute sowie . _ @ - und Apostroph sind erlaubt." autocomplete="off">
						</label>
						<label>Anzeigename *<input v-model.trim="createDraft.displayName" required maxlength="255" autocomplete="off"></label>
						<label class="span-full">E-Mail-Adresse für Einladung *<input v-model.trim="createDraft.email" type="email" required maxlength="254" autocomplete="off"></label>
					</div>
				</fieldset>
				<fieldset>
					<legend>Vereinszugehörigkeit</legend>
					<div class="create-grid">
						<label>Abteilung *
							<select v-model="createDraft.department" required>
								<option value="" disabled>Abteilung auswählen</option>
								<option v-for="group in page?.creatable_departments || []" :key="group" :value="group">{{ group }}</option>
							</select>
						</label>
						<label v-if="memberNumberField">Mitgliedsnummer <small>Vorschlag: {{ page?.next_member_number ?? 'nicht verfügbar' }}. Die Nummer bleibt vor der Anlage korrigierbar.</small>
							<input v-model.trim="createDraft.fields.mitgliedsnummer" type="text" inputmode="numeric" pattern="[0-9]+" placeholder="Automatisch beim Speichern" autocomplete="off" @input="memberNumberManuallyEdited = true">
						</label>
						<label v-if="createField('eintrittsdatum')">Eintrittsdatum
							<input v-model="createDraft.fields.eintrittsdatum" type="date">
						</label>
						<label v-if="createField('beitragsfrei')">Beitragsfrei
							<select v-model="createDraft.fields.beitragsfrei"><option value="">–</option><option value="true">Ja</option><option value="false">Nein</option></select>
						</label>
						<label v-if="createField('beitragsart')">Beitragsart
							<select v-model="createDraft.fields.beitragsart"><option value="">–</option><option v-for="option in createField('beitragsart')?.options || []" :key="option" :value="option">{{ option }}</option></select>
						</label>
					</div>
				</fieldset>
				<fieldset v-if="addressFields.length">
					<legend>Anschrift</legend>
					<div class="create-grid">
						<label v-for="field in addressFields" :key="field.id" :class="{ 'span-full': field.field_key === 'vereins_strasse' }">{{ field.label }}
							<input v-model="createDraft.fields[field.field_key]" :type="inputType(field)">
						</label>
					</div>
				</fieldset>
				<fieldset v-if="otherCreateFields.length">
					<legend>Weitere Mitgliedsdaten</legend>
					<div class="create-grid">
						<label v-for="field in otherCreateFields" :key="field.id">{{ field.label }}
							<select v-if="field.type === 'select'" v-model="createDraft.fields[field.field_key]">
								<option value="">–</option><option v-for="option in field.options || []" :key="option" :value="option">{{ option }}</option>
							</select>
							<select v-else-if="field.type === 'boolean'" v-model="createDraft.fields[field.field_key]">
								<option value="">–</option><option value="true">Ja</option><option value="false">Nein</option>
							</select>
							<select v-else-if="field.type === 'multiselect'" v-model="createDraft.fields[field.field_key]" multiple>
								<option v-for="option in field.options || []" :key="option" :value="option">{{ option }}</option>
							</select>
							<input v-else v-model="createDraft.fields[field.field_key]" :type="inputType(field)">
						</label>
					</div>
				</fieldset>
				<div class="create-submit">
					<button type="button" :disabled="saving" @click="createOpen = false">Abbrechen</button>
					<button type="submit" :disabled="saving || loading || !page?.can_create">{{ saving ? 'Lege an …' : 'Mitglied anlegen und einladen' }}</button>
				</div>
			</form>
		</section>
		<div class="toolbar">
			<label>Suche <input v-model="search" type="search" placeholder="Name, Benutzername oder Profilfeld" @keydown.enter="reload"></label>
			<label>Abteilung
				<select v-model="department" :disabled="loading || saving" @change="reload">
					<option value="">Alle Abteilungen</option>
					<option v-for="group in page?.departments || []" :key="group" :value="group">{{ group }}</option>
				</select>
			</label>
			<button type="button" :disabled="loading || saving" @click="reload">Suchen / Aktualisieren</button>
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
						<th scope="row">
							<form v-if="accountEdit?.uid === member.user_uid && accountEdit.kind === 'name'" @submit.prevent="saveAccount(member)">
								<label>Nextcloud-Anzeigename <input v-model="accountDraft" type="text" required :disabled="saving"></label>
								<div class="actions">
									<button type="submit" :disabled="saving">Speichern</button>
									<button type="button" :disabled="saving" @click="accountEdit = null">Abbrechen</button>
								</div>
							</form>
							<button v-else-if="member.can_edit_display_name" type="button" class="cell-button" :disabled="loading || saving" :aria-label="`Anzeigename von ${member.user_uid} bearbeiten`" @click="startAccountEdit(member, 'name')">
								{{ member.display_name }} ✎
							</button>
							<span v-else>{{ member.display_name }}</span>
							<small>{{ member.user_uid }}</small>
						</th>
						<td>
							<form v-if="accountEdit?.uid === member.user_uid && accountEdit.kind === 'department'" @submit.prevent="saveAccount(member)">
								<label>Zielabteilung
									<select v-model="accountDraft" required :disabled="saving">
										<option value="" disabled>Abteilung auswählen</option>
										<option v-for="group in page.editable_departments" :key="group" :value="group">{{ group }}</option>
									</select>
								</label>
								<small>Bisherige Abteilungen: {{ member.departments.join(', ') || 'keine' }}. Es bleibt nur die Zielabteilung.</small>
								<div class="actions">
									<button type="submit" :disabled="saving">Speichern</button>
									<button type="button" :disabled="saving" @click="accountEdit = null">Abbrechen</button>
								</div>
							</form>
							<button v-else-if="member.can_change_department" type="button" class="cell-button" :disabled="loading || saving" :aria-label="`Abteilung von ${member.user_uid} ändern`" @click="startAccountEdit(member, 'department')">
								{{ member.departments.join(', ') || '–' }} ✎
							</button>
							<span v-else>{{ member.departments.join(', ') || '–' }}</span>
						</td>
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
							<button v-else-if="field.can_write" type="button" class="cell-button" :disabled="loading || saving" :aria-label="`${field.label} von ${member.display_name} bearbeiten`" @click="startEdit(member, field)">
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
			<button type="button" :disabled="loading || saving || offset === 0" @click="changePage(-1)">Zurück</button>
			<span>Seite {{ Math.floor(offset / limit) + 1 }}</span>
			<button type="button" :disabled="loading || saving || offset + limit >= page.pagination.total" @click="changePage(1)">Weiter</button>
		</footer>
	</main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import { apiErrorMessage, changeMemberDepartment, createMember, fetchMembers, saveDisplayName, saveMemberField, type MemberCell, type MemberFieldDefinition, type MemberPage, type MemberRecord, type NewMemberPayload } from '../member-directory-api'

const page = ref<MemberPage | null>(null)
const department = ref('')
const search = ref('')
const offset = ref(0)
const limit = 50
const shownKeys = ref<string[]>([])
const loading = ref(false)
const createOpen = ref(false)
const createDraft = ref<NewMemberPayload>({ userUid: '', firstName: '', lastName: '', displayName: '', email: '', department: '', fields: {} })
const createUidManuallyEdited = ref(false)
const createDisplayNameManuallyEdited = ref(false)
const creatableFields = computed(() => page.value?.fields.filter(f => f.can_write && !['vereins_vorname', 'vereins_nachname'].includes(f.field_key)) || [])
const createField = (key: string) => creatableFields.value.find(f => f.field_key === key)
const memberNumberField = computed(() => createField('mitgliedsnummer'))
const addressFields = computed(() => creatableFields.value.filter(f => ['vereins_strasse', 'vereins_hausnummer', 'vereins_plz', 'vereins_ort', 'vereins_land'].includes(f.field_key)))
const otherCreateFields = computed(() => creatableFields.value.filter(f => !['mitgliedsnummer', 'eintrittsdatum', 'beitragsfrei', 'beitragsart', 'vereins_strasse', 'vereins_hausnummer', 'vereins_plz', 'vereins_ort', 'vereins_land'].includes(f.field_key)))
const memberNumberManuallyEdited = ref(false)
const suggestedUid = (first: string, last: string): string => {
	const strip = (text: string) => text.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/ß/g, 'ss').replace(/[^A-Za-z0-9]/g, '')
	return (strip(first).slice(0, 3) + strip(last).slice(0, 30)).slice(0, 64)
}
watch(() => [createDraft.value.firstName, createDraft.value.lastName], ([first, last]) => {
	if (!createUidManuallyEdited.value) createDraft.value.userUid = suggestedUid(first, last)
	if (!createDisplayNameManuallyEdited.value) createDraft.value.displayName = [first, last].filter(Boolean).join(' ')
})
watch(() => createDraft.value.userUid, value => {
	if (value !== suggestedUid(createDraft.value.firstName, createDraft.value.lastName)) createUidManuallyEdited.value = true
})
watch(() => createDraft.value.displayName, value => {
	if (value !== [createDraft.value.firstName, createDraft.value.lastName].filter(Boolean).join(' ')) createDisplayNameManuallyEdited.value = true
})
function openCreateForm() {
	if (!memberNumberManuallyEdited.value && page.value?.next_member_number) {
		createDraft.value.fields.mitgliedsnummer = page.value.next_member_number
	}
	createOpen.value = true
	error.value = ''
	notice.value = ''
}
async function submitCreate() {
	if (!page.value?.can_create || saving.value || loading.value) return
	saving.value = true
	error.value = ''
	notice.value = ''
	try {
		// The server route requires a recent password confirmation.
		// Use Nextcloud's own dialog; never handle the account password ourselves.
		try {
			await confirmPassword()
		} catch {
			notice.value = 'Anlage abgebrochen: Die Passwortbestätigung wurde nicht abgeschlossen.'
			return
		}
		const result = await createMember(createDraft.value)
		createOpen.value = false
		createDraft.value = { userUid: '', firstName: '', lastName: '', displayName: '', email: '', department: '', fields: {} }
		createUidManuallyEdited.value = false
		createDisplayNameManuallyEdited.value = false
		memberNumberManuallyEdited.value = false
		offset.value = 0
		await load()
		notice.value = result.invitation_sent
			? 'Mitglied angelegt. Einladung zur Passworteinrichtung versendet.'
			: 'Mitglied angelegt, aber die Einladung konnte nicht versendet werden.'
	} catch (e) {
		error.value = apiErrorMessage(e)
	} finally {
		saving.value = false
	}
}
const saving = ref(false)
const error = ref('')
const notice = ref('')
const editingKey = ref<string | null>(null)
type AccountEditKind = 'name' | 'department'
const accountEdit = ref<{ uid: string, kind: AccountEditKind } | null>(null)
const accountDraft = ref('')
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
		const hadPage = page.value !== null
		page.value = await fetchMembers({ department: department.value, search: search.value, offset: offset.value, limit })
		if (createOpen.value && !memberNumberManuallyEdited.value && page.value.next_member_number) {
			createDraft.value.fields.mitgliedsnummer = page.value.next_member_number
		}
		if (!page.value.can_create) createOpen.value = false
		if (offset.value > 0 && offset.value >= page.value.pagination.total) {
			offset.value = Math.max(0, Math.ceil(page.value.pagination.total / limit) - 1) * limit
			page.value = await fetchMembers({ department: department.value, search: search.value, offset: offset.value, limit })
		}
		shownKeys.value = hadPage ? previousKeys.filter(k => page.value!.fields.some(f => f.field_key === k)) : page.value.fields.map(f => f.field_key)
		editingKey.value = null
		accountEdit.value = null
	} catch (e) {
		error.value = apiErrorMessage(e)
		page.value = null
	} finally {
		loading.value = false
	}
}
function reload() { if (saving.value || loading.value) return; offset.value = 0; void load() }
function changePage(direction: number) { offset.value = Math.max(0, offset.value + direction * limit); void load() }
function startEdit(member: MemberRecord, field: MemberFieldDefinition) {
	accountEdit.value = null
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
function startAccountEdit(member: MemberRecord, kind: AccountEditKind) {
	editingKey.value = null
	accountEdit.value = { uid: member.user_uid, kind }
	accountDraft.value = kind === 'name'
		? member.display_name
		: member.departments.length === 1 ? member.departments[0] : ''
	error.value = ''
	notice.value = ''
}
async function saveAccount(member: MemberRecord) {
	if (saving.value || loading.value || accountEdit.value?.uid !== member.user_uid) return
	saving.value = true
	error.value = ''
	notice.value = ''
	try {
		if (accountEdit.value.kind === 'name') {
			await saveDisplayName(member, accountDraft.value)
		} else {
			await changeMemberDepartment(member, accountDraft.value)
		}
		accountEdit.value = null
		notice.value = 'Änderung gespeichert.'
		await load()
	} catch (e) {
		error.value = apiErrorMessage(e)
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
.member-actions{display:flex;justify-content:flex-end}
.create-button,.create-submit button[type=submit]{background:var(--color-primary-element);color:var(--color-primary-element-text);border-radius:8px}
.member-create{width:min(100%,960px);box-sizing:border-box;padding:clamp(18px,3vw,32px);margin:4px auto 10px;border:1px solid var(--color-border);border-radius:16px;background:var(--color-main-background);box-shadow:0 8px 28px rgba(0,0,0,.10);color:var(--color-main-text)}
.member-create p{line-height:1.5;color:var(--color-text-maxcontrast);margin:8px 0 0}
.create-form{display:grid;gap:22px;margin-top:20px}
.create-form fieldset{border:0;border-top:1px solid var(--color-border);padding:16px 0 0;margin:0;min-width:0}
.create-form legend{font-size:17px;font-weight:700;padding-right:16px}
.create-form .span-full{grid-column:1/-1}
.create-form label{font-size:14px;font-weight:600;line-height:1.4}
.create-form label small{font-size:12px;font-weight:400;margin:3px 0 2px;white-space:normal}
.create-form input,.create-form select{min-width:0;min-height:42px;border-radius:8px;padding:9px 11px}
.create-form input:focus-visible,.create-form select:focus-visible{outline:2px solid var(--color-primary-element);outline-offset:2px}
.create-close{align-self:start}
@media(max-width:620px){.create-grid{grid-template-columns:1fr!important}.member-create{padding:16px}.create-heading{gap:12px}}
.create-heading{display:flex;justify-content:space-between;align-items:center}
.create-heading h3{margin:0}
.create-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 20px}
.create-grid label{display:flex;flex-direction:column;gap:6px}
.create-grid input,.create-grid select{width:100%;max-width:100%;box-sizing:border-box}
.create-submit{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:12px;margin-top:2px}
footer{display:flex;align-items:center;gap:16px}
</style>
