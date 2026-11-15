// SPDX-FileCopyrightText: 2026 profile_fields contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import 'vite/modulepreload-polyfill'
import { createApp } from 'vue'
import MemberDirectorySettings from './views/MemberDirectorySettings.vue'

const target = document.getElementById('profile-fields-member-directory-settings')
if (target) {
	const app = createApp(MemberDirectorySettings)
	app.config.idPrefix = 'profile-fields-member-settings'
	app.mount(target)
}
