<?php

declare(strict_types=1);

namespace Wheesnoza\PestPluginPaoMutation;

use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuite;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuiteSubscriber;
use Pest\Mutate\Repositories\ConfigurationRepository;
use Pest\Support\Container;

/** @internal */
final class MutationResultSubscriber implements FinishMutationSuiteSubscriber
{
    private ?MutationResult $result = null;

    public function notify(FinishMutationSuite $event): void
    {
        // Use the configured minimum score so the reported outcome matches the run.
        /** @var ConfigurationRepository $repository */
        $repository = Container::getInstance()->get(ConfigurationRepository::class);
        $this->result = MutationResult::fromSuite($event->mutationSuite, $repository->mergedConfiguration());
        PaoOutputFilter::setResult($this->result);
    }

    public function result(): ?MutationResult
    {
        return $this->result;
    }
}
