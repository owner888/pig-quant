<?php

declare(strict_types=1);

use Pig\CodingAgent\Extensions\ExtensionApi;
use PigQuant\Extension;

/*
 * pig-quant — a dry-run crypto trading engine in pure PHP: the `/quant` command, the `quant_*`
 * tools and a panel in the web UI.
 *
 * A package installed from git is not run through Composer, so nothing autoloads its classes;
 * this registers the loader for the package's two namespaces, once. `Quant\` is the engine and
 * knows nothing of pig; `PigQuant\` is pig's side of it.
 */
spl_autoload_register(static function (string $class): void {
    foreach (['Quant\\' => '/src/Quant/', 'PigQuant\\' => '/src/PigQuant/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = dirname(__DIR__) . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require_once $file;
            }

            return;
        }
    }
});

return static function (ExtensionApi $pig): void {
    Extension::register($pig);
};
