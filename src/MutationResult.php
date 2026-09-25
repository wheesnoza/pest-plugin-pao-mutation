<?php

declare(strict_types=1);

namespace Wheesnoza\PestPluginPaoMutation;

use Pest\Mutate\Contracts\Mutator;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\Support\Configuration\Configuration;
use Pest\Mutate\Support\MutationTestResult;
use Symfony\Component\Console\Formatter\OutputFormatter;
use UnexpectedValueException;

final readonly class MutationResult
{
    /**
     * @param  'passed'|'failed'  $result
     * @param  list<array{file: string, line: int, mutator: string, diff?: array{original: string, mutated: string}}>  $untestedDetails
     */
    private function __construct(
        private string $result,
        private float $score,
        private int $mutations,
        private int $tested,
        private int $untested,
        private array $untestedDetails,
    ) {}

    public static function fromSuite(MutationSuite $suite, Configuration $configuration): self
    {
        $score = $suite->score();
        // Pass when no minimum is set; also allow the configured exception when no files were mutated.
        $passed = $configuration->minScore === null
            || ($suite->repository->count() === 0 && $configuration->ignoreMinScoreOnZeroMutations)
            || $score >= $configuration->minScore;

        return new self(
            $passed ? 'passed' : 'failed',
            $score,
            $suite->repository->total(),
            $suite->repository->tested(),
            $suite->repository->untested(),
            self::untestedDetails($suite),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [
            'result' => $this->result,
            'score' => $this->score,
            'mutations' => $this->mutations,
            'tested' => $this->tested,
            'untested' => $this->untested,
        ];

        // Omit the details field when no mutations survived.
        if ($this->untestedDetails !== []) {
            $result['untested_details'] = $this->untestedDetails;
        }

        return $result;
    }

    /** @return list<array{file: string, line: int, mutator: string, diff?: array{original: string, mutated: string}}> */
    private static function untestedDetails(MutationSuite $suite): array
    {
        $details = [];
        $cwd = getcwd();
        $prefix = $cwd === false ? '' : $cwd.DIRECTORY_SEPARATOR;

        // Record details only for mutations that the tests did not catch.
        foreach ($suite->repository->all() as $collection) {
            foreach ($collection->tests() as $test) {
                if ($test->result() !== MutationTestResult::Untested) {
                    continue;
                }

                $mutation = $test->mutation;
                if (! is_a($mutation->mutator, Mutator::class, true)) {
                    throw new UnexpectedValueException('Unknown mutation mutator.');
                }
                // Show files under the working directory as relative paths.
                $file = $mutation->file->getRealPath() ?: $mutation->file->getPathname();
                $detail = [
                    'file' => $prefix !== '' && str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file,
                    'line' => $mutation->startLine,
                    'mutator' => $mutation->mutator::name(),
                ];

                // Remove display colors and diff markers, keeping only the code before and after.
                $original = $mutated = [];
                foreach (explode("\n", (new OutputFormatter)->format($mutation->diff) ?? '') as $line) {
                    $line = ltrim($line);
                    if (str_starts_with($line, '-') && ! str_starts_with($line, '---')) {
                        $original[] = substr($line, 1);
                    } elseif (str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                        $mutated[] = substr($line, 1);
                    }
                }
                if ($original !== [] || $mutated !== []) {
                    $detail['diff'] = ['original' => implode("\n", $original), 'mutated' => implode("\n", $mutated)];
                }

                $details[] = $detail;
            }
        }

        return $details;
    }
}
