<?php

declare(strict_types=1);

namespace Wheesnoza\PestPluginPaoMutation;

use Laravel\Pao\Execution;
use Pest\Contracts\Plugins\Bootable;
use Pest\Mutate\Event\Facade;
use Pest\Plugins\Parallel;

/** @internal */
final class Plugin implements Bootable
{
    public function boot(): void
    {
        $arguments = $_SERVER['argv'] ?? null;

        // Only handle the parent mutation run with PAO; leave normal tests and child runs untouched.
        if (! Execution::running()
            || ! is_array($arguments)
            || ! in_array('--mutate', $arguments, true)
            || Parallel::isWorker()
            || getenv('PEST_MUTATION_TESTING') !== false
        ) {
            return;
        }

        // Clear any previous result before preparing to replace PAO's final output.
        PaoOutputFilter::setResult(null);

        // Tell PHP which code should handle the replacement output.
        if (! stream_filter_register('pao_mutation_output', PaoOutputFilter::class)) {
            throw new \RuntimeException('Cannot register the PAO mutation output filter.');
        }

        // Connect that code to standard output; stop if the connection fails.
        if (! stream_filter_append(STDOUT, 'pao_mutation_output', STREAM_FILTER_WRITE)) {
            throw new \RuntimeException('Cannot attach the PAO mutation output filter.');
        }

        // Receive the final result when mutation testing finishes.
        Facade::instance()->registerSubscriber(new MutationResultSubscriber);
    }
}
