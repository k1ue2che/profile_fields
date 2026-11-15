<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCA\ProfileFields\AppInfo\Application;
use OCP\Util;

Util::addStyle(Application::APP_ID, 'member-directory-table');
Util::addScript(Application::APP_ID, 'profile_fields-member-directory');
?>
<div id="profile-fields-member-directory"></div>
