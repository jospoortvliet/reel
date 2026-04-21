<?php

declare(strict_types=1);

namespace OCA\Reel\Command;

use OCA\Reel\Service\EventDetectionService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Debug command to preview or apply the full post-insert filter pipeline
 * for a single event, using EventDetectionService::applyPostInsertFilters().
 *
 * Default mode is preview-only (transaction rollback).
 * Use --apply to persist changes.
 *
 * Usage: php occ reel:debug-duplicates <event-id> <user-id> [--debug] [--apply]
 */
class DebugDuplicates extends Command {

    public function __construct(
        private EventDetectionService $eventDetectionService,
        private IDBConnection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('reel:debug-duplicates')
            ->setDescription('Preview/apply the post-insert filter pipeline for an event')
            ->addArgument('event-id', InputArgument::REQUIRED, 'Event ID to analyse')
            ->addArgument('user-id',  InputArgument::REQUIRED, 'User ID')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Print debug lines emitted by filters')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persist filter changes (default is preview/rollback)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $eventId = (int)$input->getArgument('event-id');
        $userId  = (string)$input->getArgument('user-id');
        $withDebug = (bool)$input->getOption('debug');
        $apply = (bool)$input->getOption('apply');

        $modeLabel = $apply ? 'APPLY' : 'PREVIEW (ROLLBACK)';
        $output->writeln("Running post-insert pipeline for event <info>{$eventId}</info> user <info>{$userId}</info> [{$modeLabel}]");
        $output->writeln('');

        $onDebug = null;
        if ($withDebug) {
            $onDebug = static function (string $line) use ($output): void {
                $output->writeln('  <comment>' . $line . '</comment>');
            };
        }

        $this->db->beginTransaction();
        try {
            $result = $this->eventDetectionService->applyPostInsertFilters($eventId, $userId, $onDebug);

            if ($apply) {
                $this->db->commit();
            } else {
                $this->db->rollBack();
            }

            $netExcluded = ($result['utility_excluded'] + $result['duplicates_excluded'] + $result['distinct_excluded']) - $result['triptych_reenabled'];

            $output->writeln('Pipeline summary:');
            $output->writeln(sprintf('  utility excluded: <info>%d</info>', $result['utility_excluded']));
            $output->writeln(sprintf('  duplicates excluded: <info>%d</info>', $result['duplicates_excluded']));
            $output->writeln(sprintf('  distinct excluded: <info>%d</info>', $result['distinct_excluded']));
            $output->writeln(sprintf('  triptych re-enabled: <info>%d</info>', $result['triptych_reenabled']));
            $output->writeln(sprintf('  net selection delta: <info>%+d</info>', -$netExcluded));
            $output->writeln('');

            if ($apply) {
                $output->writeln('<info>Changes were applied to this event.</info>');
            } else {
                $output->writeln('<comment>Preview mode: all changes were rolled back.</comment>');
            }
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $output->writeln(sprintf(
            'Done. Command now mirrors event-detection post-processing for event <info>%d</info>.',
            $eventId,
        ));

        return Command::SUCCESS;
    }
}
