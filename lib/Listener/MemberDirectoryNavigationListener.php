<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Listener;

use OCA\ProfileFields\AppInfo\Application;
use OCA\ProfileFields\Service\MemberDirectoryAccessService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;

/**
 * Register the member directory only for users authorized to open it.
 *
 * @template-implements IEventListener<LoadAdditionalEntriesEvent>
 */
class MemberDirectoryNavigationListener implements IEventListener {
	public function __construct(
		private MemberDirectoryAccessService $access,
		private IUserSession $session,
		private INavigationManager $navigation,
		private IURLGenerator $urls,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalEntriesEvent) {
			return;
		}

		$uid = $this->session->getUser()?->getUID();
		if (!$this->access->canRead($uid)) {
			return;
		}

		$this->navigation->add([
			'id' => Application::APP_ID,
			'app' => Application::APP_ID,
			'name' => 'Mitgliederverwaltung',
			'href' => $this->urls->linkToRoute('profile_fields.member_directory_page.index'),
			'icon' => $this->urls->imagePath(Application::APP_ID, 'app.svg'),
			'order' => 80,
			'type' => 'link',
		]);
	}
}
