<?php

declare(strict_types=1);

use Pest\Mutate\Mutation;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\Mutators\Equality\GreaterOrEqualToGreater;
use Pest\Mutate\Support\Configuration\Configuration;
use Pest\Mutate\Support\MutationTestResult;
use Symfony\Component\Finder\SplFileInfo;
use Wheesnoza\PestPluginPaoMutation\MutationResult;

function mutationConfiguration(?float $minScore, bool $ignoreZero = false): Configuration
{
    return new Configuration(false, [], [], [], [], false, 1, false, $minScore, $ignoreZero, false, false, null, false, false);
}

function mutationSuiteWithResults(MutationTestResult ...$results): MutationSuite
{
    $suite = new MutationSuite;
    $file = new SplFileInfo(__FILE__, '', '');

    foreach ($results as $index => $result) {
        $suite->repository->add(new Mutation($file, (string) $index, GreaterOrEqualToGreater::class, 19, 19, "  <fg=red>-    return \$total >= 5000;</>\n  <fg=green>+    return \$total > 5000;</>\n", ''));
        $suite->repository->all()[$file->getRealPath()]->tests()[$index]->updateResult($result);
    }

    return $suite;
}

it('snapshots mutation counts and score, not file count', function (): void {
    $suite = mutationSuiteWithResults(MutationTestResult::Tested, MutationTestResult::Untested);

    expect(MutationResult::fromSuite($suite, mutationConfiguration(50.0))->toArray())->toBe([
        'result' => 'passed',
        'score' => 50.0,
        'mutations' => 2,
        'tested' => 1,
        'untested' => 1,
        'untested_details' => [[
            'file' => 'tests/MutationResultTest.php',
            'line' => 19,
            'mutator' => 'GreaterOrEqualToGreater',
            'diff' => ['original' => '    return $total >= 5000;', 'mutated' => '    return $total > 5000;'],
        ]],
    ]);
});

it('matches Mutate minimum-score and zero-file rules', function (array $results, ?float $minimum, bool $ignoreZero, string $expected): void {
    expect(MutationResult::fromSuite(mutationSuiteWithResults(...$results), mutationConfiguration($minimum, $ignoreZero))->toArray()['result'])->toBe($expected);
})->with([
    'minimum absent' => [[MutationTestResult::Untested], null, false, 'passed'],
    'minimum missed' => [[MutationTestResult::Tested, MutationTestResult::Untested], 100.0, false, 'failed'],
    'zero files without exception' => [[], 100.0, false, 'failed'],
    'zero files with exception' => [[], 100.0, true, 'passed'],
]);
