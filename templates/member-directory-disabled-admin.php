<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCA\ProfileFields\AppInfo\Application;
use OCP\Util;

Util::addStyle(Application::APP_ID, 'member-directory-table');
?>
<main class="member-directory" style="padding:24px;max-width:900px">
  <h2>Mitgliederverwaltung</h2>
  <p>Die Mitgliederverwaltung ist derzeit deaktiviert oder nicht für dein Administratorkonto freigegeben.</p>
  <p>Als Nextcloud-Administrator kannst du die Konfiguration verwalten. Solange die Mitgliederverwaltung deaktiviert ist, werden keine Mitgliederdaten angezeigt.</p>
  <p><a class="button primary" href="<?php p($_['settingsUrl']); ?>">Einstellungen öffnen</a></p>
</main>
