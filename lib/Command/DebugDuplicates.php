<?php

declare(strict_types=1);

namespace OCA\Reel\Command;

use OCA\Reel\Service\DuplicateFilterService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Debug command to preview duplicate detection for a single event.
 * Uses DuplicateFilterService::analyseEvent() directly — the exact same
 * code path as the real filter — so output is guaranteed to match what
 * filterEvent() would do. Read-only: no DB changes.
 *
 * Usage: php occ reel:debug-duplicates <event-id> <user-id>
 */
class DebugDuplicates extends Command {

    public function __construct(
        private DuplicateFilterService $duplicateFilter,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('reel:debug-duplicates')
            ->setDescription('Preview duplicate detection for an event (read-only)')
            ->addArgument('event-id', InputArgument::REQUIRED, 'Event ID to analyse')
            ->addArgument('user-id',  InputArgument::REQUIRED, 'User ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $eventId = (int)$input->getArgument('event-id');
        $userId  = $input->getArgument('user-id');

        $output->writeln("Analysing event <info>{$eventId}</info> for user <info>{$userId}</info>");
        $output->writeln('');

        $analysis = $this->duplicateFilter->analyseEvent($eventId, $userId);

        $t = $analysis['thresholds'];
        $output->writeln(sprintf(
            'Thresholds (from user settings): burst_gap=<info>%ds</info>  similarity=<info>%d</info>',
            $t['burst_gap'], $t['similarity']
        ));
        $output->writeln('');

        if (empty($analysis['bursts'])) {
            $output->writeln('<comment>No duplicate bursts detected.</comment>');
            return Command::SUCCESS;
        }

        foreach ($analysis['bursts'] as $i => $burst) {
            $output->writeln(sprintf('<comment>--- Burst %d (winner by: %s) ---</comment>', $i + 1, $burst['method']));

            foreach ($burst['photos'] as $photo) {
                $fileId   = (int)$photo['file_id'];
                $isWinner = $fileId === $burst['winner'];
                $marker   = $isWinner ? '<info>KEEP    </info>' : '<fg=red>EXCLUDE</fg> ';
                $output->writeln(sprintf(
                    '  %s  file_id=<info>%d</info>  %s',
                    $marker,
                    $fileId,
                    basename($photo['name']),
                ));
            }
            $output->writeln('');
        }

        $totalExcluded = array_sum(array_map(fn($b) => count($b['excluded']), $analysis['bursts']));
        $output->writeln(sprintf(
            'Would exclude <info>%d</info> photo(s) across <info>%d</info> burst(s).',
            $totalExcluded,
            count($analysis['bursts']),
        ));

        return Command::SUCCESS;
    }
}
