<?php

declare(strict_types=1);

/*
 * The engine tests need only PHPUnit. It comes from a pig checkout's Composer autoloader — where
 * pig is: $PIG_DIR, else a `pig` directory next to this package — or from this package's own
 * vendor/ after `composer install`.
 *
 *     PIG_DIR=~/Development/owner/pig ~/Development/owner/pig/vendor/bin/phpunit -c phpunit.xml
 */

$own = dirname(__DIR__) . '/vendor/autoload.php';
$pig = rtrim(getenv('PIG_DIR') ?: dirname(__DIR__, 2) . '/pig', '/') . '/vendor/autoload.php';

if (is_file($own)) {
    require $own;
} elseif (is_file($pig)) {
    require $pig;
} else {
    fwrite(STDERR, "pig-quant tests need PHPUnit: run `composer install` here, or set PIG_DIR to a pig checkout with vendor/ (tried {$pig}).\n");
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    foreach (['Quant\\Test\\' => __DIR__ . '/', 'Quant\\' => dirname(__DIR__) . '/src/Quant/', 'PigQuant\\' => dirname(__DIR__) . '/src/PigQuant/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require_once $file;
            }

            return;
        }
    }
});
