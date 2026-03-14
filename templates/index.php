<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\Reel\AppInfo\Application::APP_ID, OCA\Reel\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\Reel\AppInfo\Application::APP_ID, OCA\Reel\AppInfo\Application::APP_ID . '-main');

?>

<div id="reel"></div>
