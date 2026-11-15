// SPDX-FileCopyrightText: 2026 profile_fields contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import 'vite/modulepreload-polyfill'
import { createApp } from 'vue'
import MemberDirectory from './views/MemberDirectory.vue'

const target = document.getElementById('profile-fields-member-directory')
if (target) {
	const app = createApp(MemberDirectory)
	app.config.idPrefix = 'profile-fields-member-directory'
	app.mount(target)
}
