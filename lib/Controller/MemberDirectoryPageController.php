<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Controller;

use OCA\ProfileFields\AppInfo\Application;
use OCA\ProfileFields\Service\MemberDirectoryAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class MemberDirectoryPageController extends Controller {
	public function __construct(
		IRequest $request,
		private MemberDirectoryAccessService $access,
		private ?string $userId,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// Read-only HTML entry point, safe to open directly or from bookmarks.
	// Authentication and the directory read permission remain enforced.
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/members')]
	public function index(): TemplateResponse {
		if ($this->access->isAdministrator($this->userId) && !$this->access->canRead($this->userId)) {
			return new TemplateResponse(Application::APP_ID, 'member-directory-disabled-admin', [
				'settingsUrl' => $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => Application::APP_ID]),
			]);
		}
		if (!$this->access->canRead($this->userId)) {
			return new TemplateResponse(Application::APP_ID, 'member-directory-denied', [], TemplateResponse::RENDER_AS_USER, 403);
		}
		// Register assets before layout rendering, not from the inner template.
		// Otherwise Nextcloud may miss the styles on this frontend page.
		Util::addStyle(Application::APP_ID, 'member-directory-table-v5');
		Util::addScript(Application::APP_ID, 'profile_fields-member-directory');
		return new TemplateResponse(Application::APP_ID, 'member-directory');
	}
}
