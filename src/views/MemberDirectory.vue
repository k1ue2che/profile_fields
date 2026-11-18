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
							<input v-model.trim="createDraft.userUid" required minlength="1" maxlength="64" title="1–64 Zeichen. Leerzeichen, Buchstaben, Zahlen, Umlaute sowie . _ @ - und Apostroph sind erlaubt." autocomplete="off" @input="createUidManuallyEdited = true">
						</label>
						<label>Anzeigename *<input v-model.trim="createDraft.displayName" required maxlength="255" autocomplete="off"></label>
						<label class="span-full">E-Mail-Adresse für Einladung *<input v-model.trim="createDraft.email" type="email" required maxlength="254" autocomplete="off"></label>
						<label v-if="createField('vereins_mobiltelefon')">Mobiltelefon (optional)<input v-model.trim="createDraft.fields.vereins_mobiltelefon" type="tel" maxlength="64" autocomplete="tel"></label>
						<label v-if="createField('vereins_telefon')">Weitere Telefonnummer (optional)<input v-model.trim="createDraft.fields.vereins_telefon" type="tel" maxlength="64" autocomplete="tel-national"></label>
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
							<select v-if="field.type === 'select'" v-model="createDraft.fields[field.field_key]">
								<option value="">Bitte auswählen</option>
								<option v-for="option in field.options || []" :key="option" :value="option">{{ field.field_key === 'vereins_land' ? countryLabel(option) : option }}</option>
							</select>
							<select v-else-if="field.type === 'boolean'" v-model="createDraft.fields[field.field_key]">
								<option value="">–</option><option value="true">Ja</option><option value="false">Nein</option>
							</select>
							<input v-else v-model="createDraft.fields[field.field_key]" :type="inputType(field)">
						</label>
					</div>
				</fieldset>
				<fieldset v-if="createField('datenschutz_hinweise_erhalten') || createField('einwilligung_web') || createField('einwilligung_social')">
					<legend>Erklärungen aus dem Aufnahmeantrag</legend>
					<p>Hier nur die Angaben aus dem bereits unterschriebenen Aufnahmeantrag dokumentieren. Eine neue Einwilligung wird hier nicht eingeholt.</p>
					<div class="create-grid">
						<label v-if="createField('datenschutz_hinweise_erhalten')">Datenschutzhinweise erhalten und gelesen
							<small>Kenntnisnahme laut Antrag, keine pauschale Datenschutz-Einwilligung.</small>
							<select v-model="createDraft.fields.datenschutz_hinweise_erhalten">
								<option value="">Nicht dokumentiert</option><option value="Ja">Ja</option><option value="Nein">Nein</option>
							</select>
						</label>
						<label v-if="createField('einwilligung_web')">Foto-/Videoveröffentlichung auf Vereinswebsite
							<small>Nur die freiwillige Auswahl aus dem unterschriebenen Antrag übernehmen.</small>
							<select v-model="createDraft.fields.einwilligung_web">
								<option value="">Keine Angabe</option><option value="Ja">Ja</option><option value="Nein">Nein</option>
							</select>
						</label>
						<label v-if="createField('einwilligung_social')">Foto-/Videoveröffentlichung auf Vereins-Social-Media
							<small>Getrennte freiwillige Auswahl aus dem unterschriebenen Antrag; konkrete Plattformen beachten.</small>
							<select v-model="createDraft.fields.einwilligung_social">
								<option value="">Keine Angabe</option><option value="Ja">Ja</option><option value="Nein">Nein</option>
							</select>
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
		<div v-if="editingMember" class="member-edit-layer">
			<section ref="editDialog" class="member-create member-edit-dialog" role="dialog" aria-modal="true" aria-labelledby="member-edit-title" tabindex="-1" @keydown.esc.prevent="closeEdit">
				<div class="create-heading">
					<div>
						<h3 id="member-edit-title">{{ canEditMember(editingMember) ? 'Mitglied bearbeiten' : 'Mitglied ansehen' }}</h3>
						<p>{{ editingMember.display_name }} · Nextcloud-Konto: {{ editingMember.user_uid }} (nicht änderbar)</p>
					</div>
					<button type="button" class="create-close" :disabled="saving" @click="closeEdit">Schließen</button>
				</div>
				<form class="create-form" @submit.prevent="saveEdit">
					<p v-if="editError" role="alert" class="edit-error">{{ editError }}</p>
					<p v-if="editNotice" role="status" class="edit-notice">{{ editNotice }}</p>
					<fieldset>
						<legend>Nextcloud-Konto und Abteilung</legend>
						<div class="create-grid">
							<label>Anzeigename
								<input v-model.trim="editDisplayName" maxlength="255" required :disabled="saving || !editingMember.can_edit_display_name">
							</label>
							<label>Abteilung
								<select v-if="editingMember.can_change_department" v-model="editDepartment" :disabled="saving">
									<option value="" disabled>Abteilung auswählen</option>
									<option v-for="group in page?.editable_departments || []" :key="group" :value="group">{{ group }}</option>
								</select>
								<input v-else :value="editingMember.departments.join(', ') || '–'" disabled>
							</label>
						</div>
					</fieldset>
					<fieldset v-for="group in editGroups" :key="group.title">
						<legend>{{ group.title }}</legend>
						<p v-if="group.title === 'Erklärungen aus dem Aufnahmeantrag'" class="edit-hint">Nur den Stand des unterschriebenen Aufnahmeantrags dokumentieren; keine neue Einwilligung einholen.</p>
						<div class="create-grid">
							<label v-for="field in group.fields" :key="field.id" :class="{ 'span-full': field.field_key === 'vereins_strasse' }">
								{{ field.label }}
								<select v-if="field.type === 'select'" v-model="editFieldDraft[field.field_key]" :disabled="saving || !field.can_write">
									<option value="">–</option>
									<option v-for="option in field.options || []" :key="option" :value="option">{{ field.field_key === 'vereins_land' ? countryLabel(option) : option }}</option>
								</select>
								<select v-else-if="field.type === 'boolean'" v-model="editFieldDraft[field.field_key]" :disabled="saving || !field.can_write">
									<option value="">–</option>
									<option value="true">Ja</option>
									<option value="false">Nein</option>
								</select>
								<select v-else-if="field.type === 'multiselect'" v-model="editFieldDraft[field.field_key]" multiple :disabled="saving || !field.can_write">
									<option v-for="option in field.options || []" :key="option" :value="option">{{ option }}</option>
								</select>
								<input v-else v-model="editFieldDraft[field.field_key]" :type="inputType(field)" :disabled="saving || !field.can_write">
								<small v-if="!field.can_write">Nur lesbar</small>
							</label>
						</div>
					</fieldset>
					<div class="create-submit">
						<button type="button" :disabled="saving" @click="closeEdit">Abbrechen</button>
						<button v-if="canEditMember(editingMember)" type="submit" :disabled="saving || !hasEditChanges">{{ saving ? 'Speichere …' : 'Änderungen speichern' }}</button>
					</div>
				</form>
			</section>
		</div>
		<div class="toolbar">
			<label>Suche <input v-model="search" type="search" placeholder="Name, Benutzername oder Profilfeld" @keydown.enter="reload"></label>
			<label>Abteilung
				<select v-model="department" :disabled="loading || saving" @change="reload">
					<option value="">Alle Abteilungen</option>
					<option v-for="group in page?.departments || []" :key="group" :value="group">{{ group }}</option>
				</select>
			</label>
			<button type="button" :disabled="loading || saving" @click="reload">Suchen / Aktualisieren</button>
			<label>Einträge pro Seite
				<select v-model.number="limit" :disabled="loading || saving" @change="changeLimit">
					<option :value="50">50</option>
					<option :value="100">100</option>
					<option :value="0">Alle anzeigen</option>
				</select>
			</label>
			<details v-if="page" class="columns">
				<summary>Zusätzliche Spalten auswählen</summary>
				<label class="column-choice"><input v-model="showDepartment" type="checkbox"> Abteilung</label>
				<label v-for="field in additionalFields" :key="field.id" class="column-choice">
					<input v-model="shownKeys" type="checkbox" :value="field.field_key"> {{ field.label }}
				</label>
			</details>
		</div>
		<p v-if="loading">Mitglieder werden geladen …</p>
		<div v-if="page" class="table-scroll">
			<table class="member-summary-table">
				<thead>
					<tr>
						<th v-if="showMemberNumber" scope="col" class="member-number-column" :aria-sort="ariaSort('mitgliedsnummer')"><button type="button" class="member-sort" :disabled="loading || saving" @click="changeSort('mitgliedsnummer')">Nr. {{ sortMark('mitgliedsnummer') }}</button></th>
						<th v-if="showDepartment" scope="col" :aria-sort="ariaSort('department')"><button type="button" class="member-sort" :disabled="loading || saving" @click="changeSort('department')">Abteilung {{ sortMark('department') }}</button></th>
						<th scope="col"  :aria-sort="ariaSort('vereins_nachname')"><button v-if="canSort('vereins_nachname')" type="button" class="member-sort" :disabled="loading || saving" @click="changeSort('vereins_nachname')">Nachname {{ sortMark('vereins_nachname') }}</button><span v-else>Nachname</span></th>
						<th scope="col"  :aria-sort="ariaSort('vereins_vorname')"><button v-if="canSort('vereins_vorname')" type="button" class="member-sort" :disabled="loading || saving" @click="changeSort('vereins_vorname')">Vorname {{ sortMark('vereins_vorname') }}</button><span v-else>Vorname</span></th>
						<th scope="col"  :aria-sort="ariaSort('vereins_strasse')"><button v-if="canSort('vereins_strasse')" type="button" class="member-sort" :disabled="loading || saving" @click="changeSort('vereins_strasse')">Straße / Nr. {{ sortMark('vereins_strasse') }}</button><span v-else>Straße / Nr.</span></th>
						<th scope="col"  :aria-sort="ariaSort('vereins_plz')"><button v-if="canSort('vereins_plz')" type="button" class="member-sort" :disabled="loading || saving" @click="changeSort('vereins_plz')">PLZ {{ sortMark('vereins_plz') }}</button><span v-else>PLZ</span></th>
						<th scope="col"  :aria-sort="ariaSort('vereins_ort')"><button v-if="canSort('vereins_ort')" type="button" class="member-sort" :disabled="loading || saving" @click="changeSort('vereins_ort')">Ort {{ sortMark('vereins_ort') }}</button><span v-else>Ort</span></th>
						<th v-for="field in visibleFields" :key="field.id" scope="col" :aria-sort="ariaSort(field.field_key)"><button type="button" class="member-sort" :disabled="loading || saving" @click="changeSort(field.field_key)">{{ field.label }} {{ sortMark(field.field_key) }}</button></th>
						<th scope="col">Aktion</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="member in page.items" :key="member.user_uid">
						<td v-if="showMemberNumber" class="member-number-column"><span class="member-number-value">{{ memberValue(member, 'mitgliedsnummer') }}</span></td>
						<td v-if="showDepartment">{{ member.departments.join(', ') || '–' }}</td>
						<th scope="row">{{ memberValue(member, 'vereins_nachname', member.display_name) }}</th>
						<td>{{ memberValue(member, 'vereins_vorname') }}</td>
						<td>{{ streetAndNumber(member) }}</td>
						<td>{{ memberValue(member, 'vereins_plz') }}</td>
						<td>{{ memberValue(member, 'vereins_ort') }}</td>
						<td v-for="field in visibleFields" :key="field.id">{{ display(member.fields[field.field_key]?.value) }}</td>
						<td>
							<button type="button" class="member-edit-button" :disabled="loading || saving" :aria-label="(canEditMember(member) ? 'Mitglied bearbeiten: ' : 'Mitglied anzeigen: ') + member.display_name" @click="openEdit(member)">
								{{ canEditMember(member) ? 'Bearbeiten' : 'Details' }}
							</button>
						</td>
					</tr>
					<tr v-if="!page.items.length"><td :colspan="6 + (showMemberNumber ? 1 : 0) + (showDepartment ? 1 : 0) + visibleFields.length">Keine Mitglieder gefunden</td></tr>
				</tbody>
			</table>
		</div>
		<footer v-if="page">
			<span>{{ page.pagination.total }} Mitglieder</span>
			<template v-if="limit > 0">
				<button type="button" :disabled="loading || saving || offset === 0" @click="changePage(-1)">Zurück</button>
				<span>Seite {{ Math.floor(offset / limit) + 1 }}</span>
				<button type="button" :disabled="loading || saving || offset + limit >= page.pagination.total" @click="changePage(1)">Weiter</button>
			</template>
			<span v-else>Alle {{ page.pagination.total }} Mitglieder</span>
		</footer>
	</main>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import { apiErrorMessage, changeMemberDepartment, createMember, fetchMembers, saveDisplayName, saveMemberField, type MemberCell, type MemberFieldDefinition, type MemberPage, type MemberRecord, type NewMemberPayload } from '../member-directory-api'

const page = ref<MemberPage | null>(null)
const department = ref('')
const search = ref('')
const offset = ref(0)
const limit = ref(50)
const sortBy = ref('display_name')
const sortDirection = ref<'asc' | 'desc'>('asc')
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
const countryNames: Record<string, string> = {
	DE: 'Deutschland', AT: 'Österreich', CH: 'Schweiz', PL: 'Polen', NL: 'Niederlande',
	CZ: 'Tschechien', FR: 'Frankreich', ES: 'Spanien', IT: 'Italien', SI: 'Slowenien',
}
const countryLabel = (code: string): string => countryNames[code] ? `${countryNames[code]} (${code})` : code
const otherCreateFields = computed(() => creatableFields.value.filter(f => !['mitgliedsnummer', 'eintrittsdatum', 'beitragsfrei', 'beitragsart', 'vereins_strasse', 'vereins_hausnummer', 'vereins_plz', 'vereins_ort', 'vereins_land', 'vereins_mobiltelefon', 'vereins_telefon', 'datenschutz_hinweise_erhalten', 'einwilligung_web', 'einwilligung_social', 'social_media_profil'].includes(f.field_key)))
const memberNumberManuallyEdited = ref(false)
const suggestedUid = (first: string, last: string): string => {
	const clean = (text: string) => text.trim().replace(/\s+/g, ' ')
	return [clean(first), clean(last)].filter(Boolean).join(' ').slice(0, 64)
}
watch(() => [createDraft.value.firstName, createDraft.value.lastName], ([first, last]) => {
	if (!createUidManuallyEdited.value) createDraft.value.userUid = suggestedUid(first, last)
	if (!createDisplayNameManuallyEdited.value) createDraft.value.displayName = [first, last].filter(Boolean).join(' ')
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
const defaultTableKeys = new Set(['vereins_nachname', 'vereins_vorname', 'vereins_strasse', 'vereins_hausnummer', 'vereins_plz', 'vereins_ort'])
const showDepartment = ref(true)
const showMemberNumber = computed(() => page.value?.fields.some(field => field.field_key === 'mitgliedsnummer') ?? false)
const additionalFields = computed(() => page.value?.fields.filter(field => !defaultTableKeys.has(field.field_key) && field.field_key !== 'mitgliedsnummer') || [])
const visibleFields = computed(() => additionalFields.value.filter(field => shownKeys.value.includes(field.field_key)))
const canSort = (fieldKey: string): boolean => page.value?.fields.some(field => field.field_key === fieldKey) ?? false
const ariaSort = (fieldKey: string): 'ascending' | 'descending' | 'none' => sortBy.value === fieldKey ? (sortDirection.value === 'asc' ? 'ascending' : 'descending') : 'none'
const sortMark = (fieldKey: string): string => sortBy.value === fieldKey ? (sortDirection.value === 'asc' ? '▲' : '▼') : '↕'
function changeSort(fieldKey: string) {
	if (saving.value || loading.value || (fieldKey !== 'department' && fieldKey !== 'display_name' && !canSort(fieldKey))) return
	if (sortBy.value === fieldKey) {
		sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
	} else {
		sortBy.value = fieldKey
		sortDirection.value = 'asc'
	}
	offset.value = 0
	closeEdit()
	void load()
}
function changeLimit() {
	if (saving.value || loading.value) return
	offset.value = 0
	closeEdit()
	void load()
}
const display = (value: unknown): string => value == null || value === '' ? '–' : Array.isArray(value) ? value.join(', ') : typeof value === 'boolean' ? (value ? 'Ja' : 'Nein') : String(value)
const memberValue = (member: MemberRecord, key: string, fallback = '–'): string => {
	const value = member.fields[key]?.value
	return value == null || value === '' ? fallback : display(value)
}
const streetAndNumber = (member: MemberRecord): string => {
	const street = memberValue(member, 'vereins_strasse', '')
	const number = memberValue(member, 'vereins_hausnummer', '')
	return [street, number].filter(Boolean).join(' ') || '–'
}
const inputType = (field: MemberFieldDefinition): string => {
	if (['vereins_telefon', 'vereins_mobiltelefon'].includes(field.field_key)) return 'tel'
	const inputTypes: Record<string, string> = { number: 'number', date: 'date', email: 'email', url: 'url' }
	return inputTypes[field.type] || 'text'
}
const editingMember = ref<MemberRecord | null>(null)
const editDialog = ref<HTMLElement | null>(null)
const editFieldDraft = ref<Record<string, unknown>>({})
const editFieldBaseline = ref<Record<string, unknown>>({})
const editDisplayName = ref('')
const editOriginalDisplayName = ref('')
const editDepartment = ref('')
const editOriginalDepartment = ref('')
const editError = ref('')
const editNotice = ref('')
const editGroups = computed(() => {
	const available = page.value?.fields || []
	const plans = [
		{ title: 'Persönliche Daten', keys: ['vereins_vorname', 'vereins_nachname'] },
		{ title: 'Kontakt', keys: ['vereins_mobiltelefon', 'vereins_telefon'] },
		{ title: 'Anschrift', keys: ['vereins_strasse', 'vereins_hausnummer', 'vereins_plz', 'vereins_ort', 'vereins_land'] },
		{ title: 'Vereinszugehörigkeit', keys: ['mitgliedsnummer', 'eintrittsdatum', 'beitragsfrei', 'beitragsart'] },
		{ title: 'Erklärungen aus dem Aufnahmeantrag', keys: ['datenschutz_hinweise_erhalten', 'einwilligung_web', 'einwilligung_social'] },
	]
	const known = new Set(plans.flatMap(group => group.keys))
	const groups = plans.map(group => ({
		title: group.title,
		fields: group.keys.map(key => available.find(field => field.field_key === key)).filter((field): field is MemberFieldDefinition => field !== undefined),
	})).filter(group => group.fields.length > 0)
	const extra = available.filter(field => !known.has(field.field_key))
	if (extra.length) groups.push({ title: 'Weitere Profilfelder', fields: extra })
	return groups
})
const draftValue = (field: MemberFieldDefinition, value: unknown): unknown => {
	if (field.type === 'boolean') return value === true ? 'true' : value === false ? 'false' : ''
	if (field.type === 'multiselect') return Array.isArray(value) ? [...value] : []
	return value == null ? '' : String(value)
}
const sameDraftValue = (a: unknown, b: unknown): boolean => JSON.stringify(a) === JSON.stringify(b)
const changedEditFields = computed(() => (page.value?.fields || []).filter(field => field.can_write && !sameDraftValue(editFieldDraft.value[field.field_key], editFieldBaseline.value[field.field_key])))
const hasEditChanges = computed(() => {
	const member = editingMember.value
	if (!member) return false
	return changedEditFields.value.length > 0
		|| (member.can_edit_display_name && editDisplayName.value.trim() !== editOriginalDisplayName.value)
		|| (member.can_change_department && editDepartment.value !== '' && editDepartment.value !== editOriginalDepartment.value)
})
const canEditMember = (member: MemberRecord): boolean => Boolean(
	member.can_edit_display_name
	|| member.can_change_department
	|| page.value?.fields.some(field => field.can_write),
)
function openEdit(member: MemberRecord) {
	if (saving.value || loading.value || !page.value) return
	createOpen.value = false
	editingMember.value = member
	editDisplayName.value = member.display_name
	editOriginalDisplayName.value = member.display_name
	editDepartment.value = member.departments.length === 1 ? member.departments[0] : ''
	editOriginalDepartment.value = editDepartment.value
	editFieldDraft.value = {}
	editFieldBaseline.value = {}
	for (const field of page.value.fields) {
		const value = draftValue(field, member.fields[field.field_key]?.value)
		editFieldDraft.value[field.field_key] = value
		editFieldBaseline.value[field.field_key] = Array.isArray(value) ? [...value] : value
	}
	editError.value = ''
	editNotice.value = ''
	void nextTick(() => editDialog.value?.focus())
}
function closeEdit() {
	if (saving.value) return
	editingMember.value = null
	editFieldDraft.value = {}
	editFieldBaseline.value = {}
	editError.value = ''
	editNotice.value = ''
}
async function load() {
	loading.value = true
	error.value = ''
	try {
		const oldKeys = shownKeys.value
		const hadPage = page.value !== null
		page.value = await fetchMembers({ department: department.value, search: search.value, offset: offset.value, limit: limit.value, sortBy: sortBy.value, sortDirection: sortDirection.value })
		if (createOpen.value && !memberNumberManuallyEdited.value && page.value.next_member_number) {
			createDraft.value.fields.mitgliedsnummer = page.value.next_member_number
		}
		if (!page.value.can_create) createOpen.value = false
		if (limit.value > 0 && offset.value > 0 && offset.value >= page.value.pagination.total) {
			offset.value = Math.max(0, Math.ceil(page.value.pagination.total / limit.value) - 1) * limit.value
			page.value = await fetchMembers({ department: department.value, search: search.value, offset: offset.value, limit: limit.value, sortBy: sortBy.value, sortDirection: sortDirection.value })
		}
		shownKeys.value = hadPage ? oldKeys.filter(key => additionalFields.value.some(field => field.field_key === key)) : []
	} catch (e) {
		error.value = apiErrorMessage(e)
		page.value = null
	} finally {
		loading.value = false
	}
}
function reload() { if (saving.value || loading.value) return; offset.value = 0; closeEdit(); void load() }
function changePage(direction: number) { if (saving.value || loading.value || limit.value === 0) return; offset.value = Math.max(0, offset.value + direction * limit.value); closeEdit(); void load() }
async function saveEdit() {
	const member = editingMember.value
	if (!member || !page.value || saving.value || loading.value || !hasEditChanges.value) return
	saving.value = true
	editError.value = ''
	editNotice.value = ''
	let saved = 0
	try {
		// The existing endpoint writes one field at a time, not atomically.
		// After each success keep the new ETag and baseline so retries only
		// submit changes which have not yet been saved.
		for (const field of [...changedEditFields.value]) {
			const key = field.field_key
			const old: MemberCell = member.fields[key] || { value: null, etag: '', updated_at: null }
			const updated = await saveMemberField(member.user_uid, field.id, editFieldDraft.value[key], old.etag)
			member.fields[key] = updated
			editFieldBaseline.value[key] = draftValue(field, updated.value)
			editFieldDraft.value[key] = draftValue(field, updated.value)
			saved++
		}
		if (member.can_edit_display_name && editDisplayName.value.trim() !== editOriginalDisplayName.value) {
			const name = editDisplayName.value.trim()
			await saveDisplayName(member, name)
			member.display_name = name
			editOriginalDisplayName.value = name
			saved++
		}
		if (member.can_change_department && editDepartment.value !== '' && editDepartment.value !== editOriginalDepartment.value) {
			await changeMemberDepartment(member, editDepartment.value)
			member.departments = [editDepartment.value]
			editOriginalDepartment.value = editDepartment.value
			saved++
		}
		closeEditAfterSave()
		notice.value = saved === 1 ? 'Änderung gespeichert.' : String(saved) + ' Änderungen gespeichert.'
		await load()
	} catch (e) {
		editError.value = apiErrorMessage(e) + ' Bitte prüfen und gegebenenfalls erneut speichern. Bei Konflikten die Mitgliederliste aktualisieren.'
		if (saved) editNotice.value = String(saved) + ' Änderungen wurden bereits gespeichert; die übrigen bleiben im Formular.'
	} finally {
		saving.value = false
	}
}
function closeEditAfterSave() {
	editingMember.value = null
	editError.value = ''
	editNotice.value = ''
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
