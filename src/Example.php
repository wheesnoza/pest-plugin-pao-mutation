<?php

declare(strict_types=1);

namespace Wheesnoza\PestPluginPaoMutation;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
trait Example // @phpstan-ignore-line
{
    /**
     * Example description.
     */
    public function example(string $name): TestCase
    {
        expect($name)->toBeString();

        return $this;
    }
}
