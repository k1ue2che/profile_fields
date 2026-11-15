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
}

export type MemberCell = { value: unknown, etag: string, updated_at: string | null }
export type MemberRecord = {
	user_uid: string
	display_name: string
	departments: string[]
	fields: Record<string, MemberCell>
}
export type MemberPage = {
	fields: MemberFieldDefinition[]
	departments: string[]
	items: MemberRecord[]
	pagination: { total: number, limit: number, offset: number }
	can_write: boolean
}
export type MemberDirectorySettings = {
	enabled: boolean
	baseGroup: string | null
	readGroups: string[]
	writeGroups: string[]
	departments: string[]
	fieldKeys: string[]
}
export type MemberDirectorySettingsPayload = {
	enabled: boolean
	baseGroup: string
	readGroups: string[]
	writeGroups: string[]
	departments: string[]
	fieldKeys: string[]
}
export type AvailableField = { id: number, field_key: string, label: string, active: boolean }

export const fetchMembers = async(params: { department: string, search: string, limit: number, offset: number }): Promise<MemberPage> => {
	const response = await axios.get<{ ocs: { data: MemberPage } }>(apiUrl('/members'), { headers, params })
	return response.data.ocs.data
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
				ocs?: { data?: { message?: unknown } }
				message?: unknown
			} }
		}
		const messages = [
			candidate.response?.data?.ocs?.data?.message,
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
