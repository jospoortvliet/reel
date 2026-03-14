<?php

declare(strict_types=1);

namespace OCA\Reel\Controller;

use OCA\Viewer\Event\LoadViewer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;

/**
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller {

    public function __construct(
        string                   $appName,
        IRequest                 $request,
        private IEventDispatcher $eventDispatcher,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Serves the Vue app for the root path and all sub-paths.
     * The wildcard catch-all is needed for HTML5 history mode routing —
     * navigating directly to /apps/reel/events/42 must return the same
     * template as /apps/reel/, otherwise the browser gets a 404.
     */
    private function renderApp(): TemplateResponse {
        if (class_exists(LoadViewer::class)) {
            $this->eventDispatcher->dispatchTyped(new LoadViewer());
        }
        return new TemplateResponse($this->appName, 'index');
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    #[OpenAPI(OpenAPI::SCOPE_IGNORE)]
    #[FrontpageRoute(verb: 'GET', url: '/')]
    public function index(): TemplateResponse {
        return $this->renderApp();
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    #[OpenAPI(OpenAPI::SCOPE_IGNORE)]
    #[FrontpageRoute(verb: 'GET', url: '/events/{id}')]
    public function event(): TemplateResponse {
        return $this->renderApp();
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    #[OpenAPI(OpenAPI::SCOPE_IGNORE)]
    #[FrontpageRoute(verb: 'GET', url: '/settings')]
    public function settings(): TemplateResponse {
        return $this->renderApp();
    }
}
