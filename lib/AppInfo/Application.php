<?php

declare(strict_types=1);

namespace OCA\Reel\AppInfo;

use OCA\Reel\BackgroundJob\DetectEventsJob;
use OCA\Reel\Command\DetectEvents;
use OCA\Reel\Command\DebugDuplicates;
use OCA\Reel\Command\RenderEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;

class Application extends App implements IBootstrap {

    public const APP_ID = 'reel';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerCommand(DetectEvents::class);
        $context->registerCommand(RenderEvent::class);
        $context->registerCommand(DebugDuplicates::class);
    }

    public function boot(IBootContext $context): void {
        $context->injectFn(function (IJobList $jobList): void {
            if (!$jobList->has(DetectEventsJob::class, null)) {
                $jobList->add(DetectEventsJob::class, null);
            }
        });
    }
}
