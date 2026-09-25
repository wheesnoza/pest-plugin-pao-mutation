<?php

declare(strict_types=1);

if (! extension_loaded('pcov') && ! extension_loaded('xdebug')) {
    fwrite(STDERR, "PCOV or Xdebug is required for mutation testing.\n");
    exit(1);
}

$command = array_map(escapeshellarg(...), [PHP_BINARY, __DIR__.'/../vendor/bin/pest', '--mutate', ...array_slice($argv, 1)]);
passthru(implode(' ', $command), $exitCode);
exit($exitCode);
