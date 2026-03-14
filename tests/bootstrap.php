<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (!class_exists(\Doctrine\DBAL\ParameterType::class)) {
	eval('namespace Doctrine\\DBAL; final class ParameterType { public const NULL = 0; public const INTEGER = 1; public const STRING = 2; public const LARGE_OBJECT = 3; }');
}

if (!class_exists(\Doctrine\DBAL\ArrayParameterType::class)) {
	eval('namespace Doctrine\\DBAL; final class ArrayParameterType { public const INTEGER = 101; public const STRING = 102; }');
}

if (!class_exists(\Doctrine\DBAL\Types\Types::class)) {
	eval('namespace Doctrine\\DBAL\\Types; final class Types { public const BOOLEAN = "boolean"; public const DATETIME_MUTABLE = "datetime"; public const TIME_MUTABLE = "time"; public const DATE_MUTABLE = "date"; public const DATETIMETZ_MUTABLE = "datetimetz"; public const DATE_IMMUTABLE = "date_immutable"; public const DATETIME_IMMUTABLE = "datetime_immutable"; public const DATETIMETZ_IMMUTABLE = "datetimetz_immutable"; }');
}

if (!interface_exists(\OC\Hooks\Emitter::class)) {
	eval('namespace OC\\Hooks; interface Emitter {}');
}

$ocpRoot = dirname(__DIR__) . '/vendor/nextcloud/ocp';
spl_autoload_register(static function (string $class) use ($ocpRoot): void {
	$prefixes = [
		'OCP\\' => $ocpRoot . '/OCP/',
		'NCU\\' => $ocpRoot . '/NCU/',
	];

	foreach ($prefixes as $prefix => $baseDir) {
		if (!str_starts_with($class, $prefix)) {
			continue;
		}

		$relative = substr($class, strlen($prefix));
		$file = $baseDir . str_replace('\\', '/', $relative) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});
