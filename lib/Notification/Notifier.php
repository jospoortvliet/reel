<?php

declare(strict_types=1);

namespace OCA\Reel\Notification;

use OCA\Reel\AppInfo\Application;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;
use OCP\IURLGenerator;

class Notifier implements INotifier {

    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
    ) {}

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return $this->l10nFactory->get(Application::APP_ID)->t('Reel');
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new UnknownNotificationException();
        }

        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $subject = $notification->getSubject();

        if ($subject !== 'video_ready') {
            throw new UnknownNotificationException();
        }

        $params = $notification->getSubjectParameters();
        $title = trim((string)($params['event_title'] ?? 'your event'));

        $notification->setParsedSubject($l->t('Your Reel video is ready: %s', [$title]));
        if ($notification->getParsedMessage() === '') {
            $notification->setParsedMessage($l->t('Open Reel to watch and share your new highlight video.'));
        }

        if ($notification->getLink() === '') {
            $eventId = (string)($notification->getObjectId() ?? '');
            if ($eventId !== '') {
                $notification->setLink($this->urlGenerator->linkToRouteAbsolute('reel.page.event', ['id' => $eventId]));
            }
        }

        $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')));

        return $notification;
    }
}
