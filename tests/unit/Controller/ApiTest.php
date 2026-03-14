<?php

declare(strict_types=1);

namespace OCA\Reel\Tests\Unit\Controller;

use OCA\Reel\AppInfo\Application;
use OCA\Reel\Controller\ApiController;
use OCA\Reel\Service\EventDetectionService;
use OCA\Reel\Service\RenderJobService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase {
	private function buildController(): ApiController {
		return new ApiController(
			Application::APP_ID,
			$this->createMock(IRequest::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(EventDetectionService::class),
			$this->createMock(RenderJobService::class),
			'admin',
			$this->createMock(IRootFolder::class),
			$this->createMock(IConfig::class),
		);
	}

	public function testFormatJobNormalizesTypes(): void {
		$controller = $this->buildController();
		$method = new \ReflectionMethod($controller, 'formatJob');
		$method->setAccessible(true);

		$formatted = $method->invoke($controller, [
			'id' => '9',
			'event_id' => '1114',
			'status' => 'running',
			'progress' => '42',
			'error' => null,
			'created_at' => '1700000000',
			'updated_at' => '1700000001',
		]);

		$this->assertSame(9, $formatted['id']);
		$this->assertSame(1114, $formatted['event_id']);
		$this->assertSame('running', $formatted['status']);
		$this->assertSame(42, $formatted['progress']);
		$this->assertNull($formatted['error']);
		$this->assertSame(1700000000, $formatted['created_at']);
		$this->assertSame(1700000001, $formatted['updated_at']);
	}

	public function testFormatJobKeepsErrorMessage(): void {
		$controller = $this->buildController();
		$method = new \ReflectionMethod($controller, 'formatJob');
		$method->setAccessible(true);

		$formatted = $method->invoke($controller, [
			'id' => 5,
			'event_id' => 99,
			'status' => 'failed',
			'progress' => 90,
			'error' => 'ffmpeg failed',
			'created_at' => 1,
			'updated_at' => 2,
		]);

		$this->assertSame('ffmpeg failed', $formatted['error']);
		$this->assertSame('failed', $formatted['status']);
	}
}
