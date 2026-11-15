<?php

/**
 * SPDX-FileCopyrightText: 2026 profile_fields contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ProfileFields\Tests\Unit\Controller;

use OCA\ProfileFields\Controller\MemberDirectoryApiController;
use OCA\ProfileFields\Db\FieldDefinition;
use OCA\ProfileFields\Service\FieldDefinitionService;
use OCA\ProfileFields\Service\MemberDirectoryAccessService;
use OCA\ProfileFields\Service\MemberDirectoryConflictException;
use OCA\ProfileFields\Service\MemberDirectoryService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class MemberDirectoryApiControllerTest extends TestCase {
	public function testCannotListWithoutExplicitReadPermission(): void {
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canRead')->willReturn(false);
		$directory = $this->createMock(MemberDirectoryService::class);
		$directory->expects($this->never())->method('listMembers');

		$controller = $this->controller($directory, $access);
		$this->assertSame(403, $controller->index()->getStatus());
	}

	public function testCannotChangeNonMember(): void {
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canWrite')->willReturn(true);
		$access->method('isMember')->with('outsider')->willReturn(false);
		$directory = $this->createMock(MemberDirectoryService::class);
		$directory->expects($this->never())->method('upsertValue');

		$controller = $this->controller($directory, $access);
		$this->assertSame(404, $controller->updateField('outsider', 5, 'x')->getStatus());
	}

	public function testRejectsNonAllowedProfileField(): void {
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canWrite')->willReturn(true);
		$access->method('isMember')->willReturn(true);
		$access->method('canWriteField')->willReturn(false);

		$definition = new FieldDefinition();
		$definitions = $this->createMock(FieldDefinitionService::class);
		$definitions->method('findById')->willReturn($definition);
		$directory = $this->createMock(MemberDirectoryService::class);
		$directory->expects($this->never())->method('upsertValue');

		$controller = $this->controller($directory, $access, $definitions);
		$this->assertSame(404, $controller->updateField('member', 5, 'x')->getStatus());
	}

	public function testUpdateReturnsConflictForStaleEtag(): void {
		$access = $this->createMock(MemberDirectoryAccessService::class);
		$access->method('canWrite')->willReturn(true);
		$access->method('isMember')->willReturn(true);
		$access->method('canWriteField')->willReturn(true);

		$definition = new FieldDefinition();
		$definitions = $this->createMock(FieldDefinitionService::class);
		$definitions->method('findById')->willReturn($definition);
		$directory = $this->createMock(MemberDirectoryService::class);
		$directory->method('upsertValue')->willThrowException(
			new MemberDirectoryConflictException('Reload and try again.'),
		);

		$controller = $this->controller($directory, $access, $definitions);
		$this->assertSame(409, $controller->updateField('member', 5, 'x', 'old')->getStatus());
	}

	private function controller(
		MemberDirectoryService $directory,
		MemberDirectoryAccessService $access,
		?FieldDefinitionService $definitions = null,
	): MemberDirectoryApiController {
		return new MemberDirectoryApiController(
			$this->createMock(IRequest::class),
			$directory,
			$access,
			$definitions ?? $this->createMock(FieldDefinitionService::class),
			'admin',
		);
	}
}
