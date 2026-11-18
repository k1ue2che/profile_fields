// SPDX-FileCopyrightText: 2026 profile_fields contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const apiUrl = (path: string) => generateOcsUrl(`/apps/profile_fields/api/v1/member-directory${path}`)
const headers = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
const jsonHeaders = { ...headers, 'Content-Type': 'application/json' }

export type MemberFieldDefinition = {
	id: number
	field_key: string
	label: string
	type: 'text' | 'number' | 'boolean' | 'date' | 'url' | 'email' | 'select' | 'multiselect'
	options: string[] | null
	can_write: boolean
}

export type MemberCell = { value: unknown, etag: string, updated_at: string | null }
export type MemberRecord = {
	account: { email: string, birthdate: string, can_edit_email: boolean, can_edit_birthdate: boolean, can_reset_password: boolean }
	user_uid: string
	display_name: string
	departments: string[]
	can_edit_display_name: boolean
	can_change_department: boolean
	fields: Record<string, MemberCell>
}
export type MemberPage = {
	can_create: boolean
	next_member_number: string | null
	creatable_departments: string[]
	fields: MemberFieldDefinition[]
	departments: string[]
	editable_departments: string[]
	items: MemberRecord[]
	pagination: { total: number, limit: number, offset: number }
	can_write: boolean
}
export type MemberDirectorySettings = {
	enabled: boolean
	baseGroup: string | null
	readGroups: string[]
	writeGroups: string[]
	createGroups: string[]
	departments: string[]
	fieldKeys: string[]
	fieldPermissions: Record<string, { readGroups: string[], writeGroups: string[] }>
}
export type MemberDirectorySettingsPayload = {
	enabled: boolean
	baseGroup: string
	readGroups: string[]
	writeGroups: string[]
	createGroups: string[]
	departments: string[]
	fieldKeys: string[]
	fieldPermissions: Record<string, { readGroups: string[], writeGroups: string[] }>
}
export type AvailableField = { id: number, field_key: string, label: string, active: boolean }

export type NewMemberPayload = {
	userUid: string
	firstName: string
	lastName: string
	displayName: string
	email: string
	department: string
	fields: Record<string, unknown>
}
export type NewMemberResult = { user_uid: string, invitation_sent: boolean }
export const createMember = async(payload: NewMemberPayload): Promise<NewMemberResult> => {
	const response = await axios.post<{ ocs: { data: NewMemberResult } }>(apiUrl('/members'), payload, { headers: jsonHeaders })
	return response.data.ocs.data
}

export const fetchMembers = async(params: { department: string, search: string, limit: number, offset: number, sortBy: string, sortDirection: 'asc' | 'desc' }): Promise<MemberPage> => {
	if (params.limit !== 0) {
		const response = await axios.get<{ ocs: { data: MemberPage } }>(apiUrl('/members'), { headers, params })
		return response.data.ocs.data
	}

	// "Alle anzeigen" uses supported 100-entry API pages rather than limit=0,
	// which older deployed endpoints and parameter validators may reject.
	// Sorting remains global because the API sorts before slicing each page.
	const batchSize = 100
	const maxAll = 5000
	const request = async(offset: number): Promise<MemberPage> => {
		const response = await axios.get<{ ocs: { data: MemberPage } }>(apiUrl('/members'), {
			headers,
			params: { ...params, limit: batchSize, offset },
		})
		return response.data.ocs.data
	}
	const first = await request(0)
	const total = first.pagination.total
	if (total > maxAll) {
		throw new Error('Mehr als 5.000 Mitglieder gefunden. Bitte 50 oder 100 Einträge pro Seite auswählen.')
	}
	const items = [...first.items]
	for (let offset = batchSize; offset < total; offset += batchSize) {
		const batch = await request(offset)
		if (batch.pagination.total !== total || batch.items.length === 0) {
			throw new Error('Die Mitgliederliste hat sich während des Ladens geändert. Bitte erneut aktualisieren.')
		}
		items.push(...batch.items)
	}
	return {
		...first,
		items,
		pagination: { total, limit: 0, offset: 0 },
	}
}

export const saveMemberField = async(
	userUid: string,
	fieldDefinitionId: number,
	value: unknown,
	expectedEtag: string,
): Promise<MemberCell> => {
	const response = await axios.put<{ ocs: { data: MemberCell } }>(
		apiUrl(`/members/${encodeURIComponent(userUid)}/fields/${fieldDefinitionId}`),
		{ value, expectedEtag },
		{ headers: jsonHeaders },
	)
	return response.data.ocs.data
}

export const saveDisplayName = async(member: MemberRecord, displayName: string): Promise<void> => {
	await axios.put(apiUrl(`/members/${encodeURIComponent(member.user_uid)}/display-name`),
		{ displayName, expectedName: member.display_name }, { headers: jsonHeaders })
}

export const saveMemberEmail = async(member: MemberRecord, email: string): Promise<void> => {
	await axios.put(apiUrl(`/members/${encodeURIComponent(member.user_uid)}/email`),
		{ email, expectedEmail: member.account.email }, { headers: jsonHeaders })
}

export const saveMemberBirthdate = async(member: MemberRecord, birthdate: string): Promise<void> => {
	await axios.put(apiUrl(`/members/${encodeURIComponent(member.user_uid)}/birthdate`),
		{ birthdate, expectedBirthdate: member.account.birthdate }, { headers: jsonHeaders })
}

export const requestMemberPasswordReset = async(member: MemberRecord): Promise<void> => {
	await axios.post(apiUrl(`/members/${encodeURIComponent(member.user_uid)}/password-reset`), {}, { headers: jsonHeaders })
}

export const changeMemberDepartment = async(member: MemberRecord, department: string): Promise<void> => {
	await axios.put(apiUrl(`/members/${encodeURIComponent(member.user_uid)}/department`),
		{ department, expectedDepartments: member.departments }, { headers: jsonHeaders })
}

export const fetchMemberSettings = async(): Promise<MemberDirectorySettings> => {
	const response = await axios.get<{ ocs: { data: MemberDirectorySettings } }>(apiUrl('/settings'), { headers })
	return response.data.ocs.data
}

export const saveMemberSettings = async(payload: MemberDirectorySettingsPayload): Promise<MemberDirectorySettings> => {
	const response = await axios.put<{ ocs: { data: MemberDirectorySettings } }>(apiUrl('/settings'), payload, { headers: jsonHeaders })
	return response.data.ocs.data
}

export const fetchAvailableFields = async(): Promise<AvailableField[]> => {
	const response = await axios.get<{ ocs: { data: AvailableField[] } }>(
		generateOcsUrl('/apps/profile_fields/api/v1/definitions'),
		{ headers },
	)
	return response.data.ocs.data
}

export const apiErrorMessage = (error: unknown): string => {
	// @nextcloud/axios exports a configured instance, not the Axios namespace.
	// Inspect the error shape without assuming an isAxiosError() method exists.
	if (error !== null && typeof error === 'object') {
		const candidate = error as {
			message?: unknown
			response?: { data?: {
				ocs?: { data?: { message?: unknown }, meta?: { message?: unknown } }
				message?: unknown
			} }
		}
		const messages = [
			candidate.response?.data?.ocs?.data?.message,
			candidate.response?.data?.ocs?.meta?.message,
			candidate.response?.data?.message,
			candidate.message,
		]
		const message = messages.find((value) => typeof value === 'string' && value.length > 0)
		if (typeof message === 'string') {
			return message
		}
	}
	return 'Unbekannter Fehler'
}

export type MemberSetupField = { field_key: string, label: string, status: 'existing' | 'missing' | 'conflict', message: string, similar_keys: string[] }
export type MemberSetupPreview = { ready: boolean, can_apply: boolean, missing_count: number, conflicts: number, fields: MemberSetupField[] }

export const fetchMemberSetup = async(): Promise<MemberSetupPreview> => {
	const response = await axios.get<{ ocs: { data: MemberSetupPreview } }>(apiUrl('/setup'), { headers })
	return response.data.ocs.data
}

export const applyMemberSetup = async(): Promise<MemberSetupPreview> => {
	const response = await axios.post<{ ocs: { data: MemberSetupPreview } }>(apiUrl('/setup'), {}, { headers: jsonHeaders })
	return response.data.ocs.data
}
