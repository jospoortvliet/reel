<?php

declare(strict_types=1);

namespace OCA\Reel\Tests\Unit\Service;

use OCA\Reel\Service\MemoriesRepository;
use OCA\Reel\Service\VideoRenderingService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class VideoRenderingServiceTest extends TestCase {
	private function buildService(?IConfig $config = null): VideoRenderingService {
		return new VideoRenderingService(
			$this->createMock(IDBConnection::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
			$config ?? $this->createMock(IConfig::class),
			$this->createMock(MemoriesRepository::class),
		);
	}

	private function invoke(object $target, string $method, array $args = []) {
		$reflection = new \ReflectionMethod($target, $method);
		$reflection->setAccessible(true);
		return $reflection->invokeArgs($target, $args);
	}

	private function setPrivateProperty(object $target, string $name, mixed $value): void {
		$prop = new \ReflectionProperty($target, $name);
		$prop->setAccessible(true);
		$prop->setValue($target, $value);
	}

	public function testGetOutputDimensionsForAllOrientations(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')
			->willReturnMap([
				['admin', 'reel', 'output_orientation', 'landscape_16_9', 'landscape_16_9'],
				['creator', 'reel', 'output_orientation', 'landscape_16_9', 'portrait_9_16'],
				['square-user', 'reel', 'output_orientation', 'landscape_16_9', 'square_1_1'],
			]);

		$service = $this->buildService($config);

		$this->assertSame([1920, 1080], $this->invoke($service, 'getOutputDimensions', ['admin']));
		$this->assertSame([1080, 1920], $this->invoke($service, 'getOutputDimensions', ['creator']));
		$this->assertSame([1080, 1080], $this->invoke($service, 'getOutputDimensions', ['square-user']));
	}

	public function testScaleFilterUsesConfiguredOutputDimensions(): void {
		$service = $this->buildService();
		$this->setPrivateProperty($service, 'outputWidth', 1080);
		$this->setPrivateProperty($service, 'outputHeight', 1920);

		$filter = $this->invoke($service, 'scaleFilter');
		$this->assertStringContainsString('scale=w=1080:h=1920', $filter);
		$this->assertStringContainsString('pad=1080:1920', $filter);
	}

	public function testBuildVideoNormalizeCmdAddsStartOffsetWhenProvided(): void {
		$service = $this->buildService();
		$this->setPrivateProperty($service, 'outputWidth', 1080);
		$this->setPrivateProperty($service, 'outputHeight', 1080);

		$cmd = $this->invoke($service, 'buildVideoNormalizeCmd', [
			'/tmp/in.mov',
			'/tmp/out.mp4',
			2.5,
			3.5,
		]);

		$this->assertContains('-ss', $cmd);
		$this->assertContains('3.500', $cmd);
		$this->assertContains('-t', $cmd);
		$this->assertContains('2.500', $cmd);
		$this->assertStringContainsString('scale=w=1080:h=1080', implode(' ', $cmd));
	}

	public function testResolveVideoWindowCentersLongVideoByDefault(): void {
		$service = $this->buildService();

		[$start, $length] = $this->invoke($service, 'resolveVideoWindow', [[
			'video_duration' => 20.0,
			'edit_settings' => null,
		], 8.0, false]);

		$this->assertEqualsWithDelta(6.0, $start, 0.001);
		$this->assertEqualsWithDelta(8.0, $length, 0.001);
	}

	public function testResolveVideoWindowAppliesEditSettingsBounds(): void {
		$service = $this->buildService();

		[$start, $length] = $this->invoke($service, 'resolveVideoWindow', [[
			'video_duration' => 10.0,
			'edit_settings' => json_encode([
				'video_start' => 9.8,
				'video_length' => 5.0,
			]),
		], 8.0, false]);

		$this->assertEqualsWithDelta(5.0, $start, 0.001);
		$this->assertEqualsWithDelta(5.0, $length, 0.001);
	}

	public function testResolveVideoWindowAlwaysStartsAtZeroForLivePhotos(): void {
		$service = $this->buildService();

		[$start, $length] = $this->invoke($service, 'resolveVideoWindow', [[
			'video_duration' => 2.8,
			'edit_settings' => json_encode([
				'video_start' => 1.5,
				'video_length' => 1.2,
			]),
		], 2.8, true]);

		$this->assertEqualsWithDelta(0.0, $start, 0.001);
		$this->assertEqualsWithDelta(2.8, $length, 0.001);
	}
}
