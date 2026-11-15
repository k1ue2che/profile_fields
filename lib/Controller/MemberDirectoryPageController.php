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

class MemberDirectoryPageController extends Controller {
	public function __construct(
		IRequest $request,
		private MemberDirectoryAccessService $access,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// Read-only HTML entry point, safe to open directly or from bookmarks.
	// Authentication and the directory read permission remain enforced.
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/members')]
	public function index(): TemplateResponse {
		if (!$this->access->canRead($this->userId)) {
			return new TemplateResponse(Application::APP_ID, 'member-directory-denied', [], TemplateResponse::RENDER_AS_USER, 403);
		}
		return new TemplateResponse(Application::APP_ID, 'member-directory');
	}
}
