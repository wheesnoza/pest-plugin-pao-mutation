<?php

declare(strict_types=1);

use Wheesnoza\PestPluginPaoMutation\Plugin;

it('declares its runtime integrations and registers the Pest plugin without example scaffolding', function (): void {
    $root = dirname(__DIR__);
    $composer = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require']['laravel/pao'])->toBe('^1.1')
        ->and($composer['require']['pestphp/pest-plugin-mutate'])->toBe('^5.0')
        ->and($composer['extra']['pest']['plugins'])->toContain(Plugin::class)
        ->and($composer['autoload'])->not->toHaveKey('files')
        ->and(file_exists($root.'/src/Autoload.php'))->toBeFalse()
        ->and(file_exists($root.'/src/Example.php'))->toBeFalse()
        ->and(file_exists($root.'/tests/Example.php'))->toBeFalse();
});
