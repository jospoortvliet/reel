<?php

declare(strict_types=1);

namespace OCA\Reel\Tests\Unit\Service;

use OCA\Reel\Service\DuplicateFilterService;
use OCA\Reel\Service\EventDetectionService;
use OCA\Reel\Service\MemoriesRepository;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class EventDetectionServiceTest extends TestCase {
	private function buildService(): EventDetectionService {
		return new EventDetectionService(
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(DuplicateFilterService::class),
			$this->createMock(MemoriesRepository::class),
		);
	}

	private function invoke(object $target, string $method, array $args = []) {
		$reflection = new \ReflectionMethod($target, $method);
		$reflection->setAccessible(true);
		return $reflection->invokeArgs($target, $args);
	}

	private function mediaRow(int $fileId, int $epoch, ?string $place = 'Utrecht'): array {
		return [
			'fileid' => $fileId,
			'epoch' => $epoch,
			'place_name' => $place,
		];
	}

	public function testClusteringUsesRollingGapFromLastItem(): void {
		$service = $this->buildService();
		$gap = (6 * 60 * 60) - 60;
		$media = [];

		for ($i = 0; $i < 6; $i++) {
			$media[] = $this->mediaRow($i + 1, $i * $gap);
		}

		$clusters = $this->invoke($service, 'clusterIntoEvents', [$media]);

		$this->assertCount(1, $clusters);
		$this->assertCount(6, $clusters[0]['media']);
		$this->assertSame(1, $clusters[0]['media'][0]['fileid']);
		$this->assertSame(6, $clusters[0]['media'][5]['fileid']);
	}

	public function testClusteringDropsEventsSmallerThanSixItems(): void {
		$service = $this->buildService();
		$media = [];

		for ($i = 0; $i < 5; $i++) {
			$media[] = $this->mediaRow($i + 1, $i * 600);
		}

		$clusters = $this->invoke($service, 'clusterIntoEvents', [$media]);

		$this->assertSame([], $clusters);
	}

	public function testClusteringSplitsWhenGapExceedsSixHoursFromPreviousItem(): void {
		$service = $this->buildService();
		$withinGap = (6 * 60 * 60) - 60;
		$splitGap = (6 * 60 * 60) + 1;
		$media = [];
		$epoch = 0;

		for ($i = 0; $i < 6; $i++) {
			$media[] = $this->mediaRow($i + 1, $epoch);
			$epoch += $withinGap;
		}

		$epoch += $splitGap;

		for ($i = 0; $i < 6; $i++) {
			$media[] = $this->mediaRow($i + 7, $epoch, 'Amsterdam');
			$epoch += 300;
		}

		$clusters = $this->invoke($service, 'clusterIntoEvents', [$media]);

		$this->assertCount(2, $clusters);
		$this->assertCount(6, $clusters[0]['media']);
		$this->assertCount(6, $clusters[1]['media']);
	}

	public function testQuickPlaceNameFlickerDoesNotSplitEvent(): void {
		$service = $this->buildService();
		$media = [
			$this->mediaRow(1, 0, 'Bruxelles - Brussel'),
			$this->mediaRow(2, 60, 'Bruxelles - Brussel'),
			$this->mediaRow(3, 120, 'Brussel-Hoofdstad - Bruxelles-Capitale'),
			$this->mediaRow(4, 8 * 60, 'Brussel-Hoofdstad - Bruxelles-Capitale'),
			$this->mediaRow(5, 12 * 60, 'Bruxelles - Brussel'),
			$this->mediaRow(6, 13 * 60, 'Brussel-Hoofdstad - Bruxelles-Capitale'),
			$this->mediaRow(7, 14 * 60, 'Brussel-Hoofdstad - Bruxelles-Capitale'),
		];

		$clusters = $this->invoke($service, 'clusterIntoEvents', [$media]);

		$this->assertCount(1, $clusters);
		$this->assertCount(7, $clusters[0]['media']);
	}

	public function testTiedPlaceCountsDoNotForceSplit(): void {
		$service = $this->buildService();
		$media = [
			$this->mediaRow(1, 0, 'Bruxelles - Brussel'),
			$this->mediaRow(2, 60, 'Bruxelles - Brussel'),
			$this->mediaRow(3, 90 * 60, 'Brussel-Hoofdstad - Bruxelles-Capitale'),
			$this->mediaRow(4, 96 * 60, 'Brussel-Hoofdstad - Bruxelles-Capitale'),
			$this->mediaRow(5, 135 * 60, 'Brussel-Hoofdstad - Bruxelles-Capitale'),
			$this->mediaRow(6, 136 * 60, 'Brussel-Hoofdstad - Bruxelles-Capitale'),
			$this->mediaRow(7, 137 * 60, 'Brussel-Hoofdstad - Bruxelles-Capitale'),
		];

		$clusters = $this->invoke($service, 'clusterIntoEvents', [$media]);

		$this->assertCount(1, $clusters);
		$this->assertSame('Brussel-Hoofdstad - Bruxelles-Capitale', $clusters[0]['location']);
		$this->assertCount(7, $clusters[0]['media']);
	}

	public function testPlaceChangeStillSplitsAfterMeaningfulPause(): void {
		$service = $this->buildService();
		$media = [
			$this->mediaRow(1, 0, 'Utrecht'),
			$this->mediaRow(2, 60, 'Utrecht'),
			$this->mediaRow(3, 120, 'Utrecht'),
			$this->mediaRow(4, 180, 'Utrecht'),
			$this->mediaRow(5, 240, 'Utrecht'),
			$this->mediaRow(6, 300, 'Utrecht'),
			$this->mediaRow(7, (37 * 60), 'Amsterdam'),
			$this->mediaRow(8, (38 * 60), 'Amsterdam'),
			$this->mediaRow(9, (39 * 60), 'Amsterdam'),
			$this->mediaRow(10, (40 * 60), 'Amsterdam'),
			$this->mediaRow(11, (41 * 60), 'Amsterdam'),
			$this->mediaRow(12, (42 * 60), 'Amsterdam'),
		];

		$clusters = $this->invoke($service, 'clusterIntoEvents', [$media]);

		$this->assertCount(2, $clusters);
		$this->assertSame('Utrecht', $clusters[0]['location']);
		$this->assertSame('Amsterdam', $clusters[1]['location']);
	}
}
