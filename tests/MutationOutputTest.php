<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @return array{stdout: string, stderr: string, exitCode: int} */
function runConsumerPest(string $mode, array $arguments = [], array $environment = [], ?string $autoPrependFile = null): array
{
    $consumer = dirname(__DIR__).'/fixtures/consumer';

    if ($mode === 'mutate' && ! extension_loaded('pcov') && ! extension_loaded('xdebug')) {
        throw new RuntimeException('PCOV or Xdebug is required for mutation testing.');
    }

    $entrypoint = $mode === 'mutate' ? 'bin/mutate.php' : 'vendor/bin/pest';
    $process = new Process(
        [PHP_BINARY, ...($autoPrependFile === null ? [] : ['-d', 'auto_prepend_file='.$autoPrependFile]), $consumer.'/'.$entrypoint, ...$arguments],
        $consumer,
        ['PAO_FORCE' => '1', 'PAO_DISABLE' => '0', 'FIXTURE_FAIL_INITIAL_TEST' => '0', ...$environment],
    );
    $process->setTimeout(180);
    $process->run();

    return [
        'stdout' => $process->getOutput(),
        'stderr' => $process->getErrorOutput(),
        'exitCode' => $process->getExitCode() ?? throw new RuntimeException('Consumer Pest did not exit.'),
    ];
}

/** @return list<array<string, mixed>> */
function expectedUntestedDetails(): array
{
    return [
        ['file' => 'src/Calculator.php', 'line' => 11, 'mutator' => 'DecrementInteger', 'diff' => ['original' => '        if ($left === 0) {', 'mutated' => '        if ($left === -1) {']],
        ['file' => 'src/Calculator.php', 'line' => 11, 'mutator' => 'IncrementInteger', 'diff' => ['original' => '        if ($left === 0) {', 'mutated' => '        if ($left === 1) {']],
    ];
}

it('has a coverage driver for the consumer mutation checks', function (): void {
    expect(extension_loaded('pcov') || extension_loaded('xdebug'))->toBeTrue();
});

it('subscribes only in PAO mutation parent and snapshots completed results', function (string $mode, array $arguments, array $environment, ?int $expectedExit, ?string $expectedResult): void {
    $probe = tempnam(sys_get_temp_dir(), 'pao-probe-');
    $snapshot = tempnam(sys_get_temp_dir(), 'pao-snapshot-');
    if ($probe === false || $snapshot === false) {
        throw new RuntimeException('Cannot create probe files.');
    }

    file_put_contents($probe, <<<'PHP'
<?php
register_shutdown_function(static function (): void {
    if (!class_exists(\Pest\Mutate\Event\Facade::class, false)) {
        return;
    }
    $subscribers = \Pest\Mutate\Event\Facade::instance()->subscribers()[\Pest\Mutate\Event\Events\TestSuite\FinishMutationSuiteSubscriber::class] ?? [];
    foreach ($subscribers as $subscriber) {
        if ($subscriber instanceof \Wheesnoza\PestPluginPaoMutation\MutationResultSubscriber) {
            file_put_contents($_SERVER['MUTATION_SNAPSHOT_FILE'], json_encode([
                'subscribed' => true,
                'result' => $subscriber->result()?->toArray(),
            ], JSON_THROW_ON_ERROR));
        }
    }
});
PHP);

    try {
        $run = runConsumerPest($mode, $arguments, [
            ...$environment,
            'MUTATION_SNAPSHOT_FILE' => $snapshot,
        ], $probe);
        $observed = file_get_contents($snapshot);
        if ($expectedExit !== null) {
            expect($run['exitCode'])->toBe($expectedExit);
        }
        if ($expectedResult === null) {
            expect($observed)->toBe('');
        } else {
            $data = json_decode($observed ?: '', true, flags: JSON_THROW_ON_ERROR);
            expect($data['subscribed'])->toBeTrue()
                ->and($data['result']['result'])->toBe($expectedResult)
                ->and($data['result']['score'])->toEqual(50.0)
                ->and($run['exitCode'])->toBe($expectedResult === 'passed' ? 0 : 1);
        }
    } finally {
        unlink($probe);
        unlink($snapshot);
    }
})->with([
    'score meets threshold' => ['pest', ['--mutate', '--min=50'], [], 0, 'passed'],
    'score below threshold' => ['pest', ['--mutate', '--min=100'], [], 1, 'failed'],
    'PAO disabled' => ['pest', ['--mutate', '--min=100'], ['PAO_DISABLE' => '1'], 1, null],
    'ordinary Pest' => ['pest', [], [], 0, null],
    'parallel worker' => ['pest', ['--mutate', '--min=100'], ['PARATEST' => '1'], null, null],
]);

it('keeps the consumer CLI baseline observable across PAO and mutation modes', function (string $mode, array $arguments, array $environment, int $expectedExitCode, bool $paoEnabled): void {
    $run = runConsumerPest($mode, $arguments, $environment);

    expect($run['exitCode'])->toBe($expectedExitCode)
        ->and($run['stdout'])->not->toBeEmpty()
        ->and($run['stderr'])->toBe('');

    if ($paoEnabled) {
        $lines = explode("\n", trim($run['stdout']));
        expect($lines)->toHaveCount(1);
        $json = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        expect($json)->toBeArray();
        if ($mode === 'pest') {
            expect($json)->toMatchArray(['tool' => 'pest', 'result' => 'passed', 'tests' => 1, 'passed' => 1, 'assertions' => 1])
                ->not->toHaveKeys(['score', 'mutations', 'tested', 'untested']);
        }
    } else {
        expect(json_decode($run['stdout'], true))->toBeNull()
            ->and($run['stdout'])->toContain('Tests:')
            ->and($run['stdout'])->not->toContain('"tool":"pest-mutate"');
    }
})->with([
    'ordinary Pest, PAO enabled' => ['pest', [], [], 0, true],
    'ordinary Pest, PAO disabled' => ['pest', [], ['PAO_DISABLE' => '1'], 0, false],
    'mutation at minimum score' => ['mutate', ['--min=50'], [], 0, true],
    'mutation below minimum score' => ['mutate', ['--min=100'], [], 1, true],
    'mutation with PAO disabled' => ['mutate', ['--min=100'], ['PAO_DISABLE' => '1'], 1, false],
    'zero mutations below minimum score' => ['mutate', ['--class=Fixture\\NoMutations', '--min=100'], [], 1, true],
    'zero mutations with threshold exception' => ['mutate', ['--class=Fixture\\NoMutations', '--min=100', '--ignore-min-score-on-zero-mutations'], [], 0, true],
    'initial test failure' => ['mutate', ['--min=50'], ['FIXTURE_FAIL_INITIAL_TEST' => '1'], 1, true],
]);

it('puts the mutation result in one top-level PAO JSON line', function (array $arguments, array $environment, int $expectedExit, string $expectedResult, ?float $expectedScore): void {
    $run = runConsumerPest('mutate', $arguments, $environment);
    $lines = explode("\n", trim($run['stdout']));

    expect($run['exitCode'])->toBe($expectedExit)
        ->and($run['stderr'])->toBe('')
        ->and($lines)->toHaveCount(1);

    $json = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
    expect($json)->toMatchArray(['tool' => 'pest-mutate', 'result' => $expectedResult]);

    if ($expectedScore === null) {
        expect($json)->not->toHaveKeys(['score', 'mutations', 'tested', 'untested', 'untested_details']);
    } else {
        expect($json)->toMatchArray(['score' => $expectedScore, ...($expectedScore === 0.0
            ? ['mutations' => 0, 'tested' => 0, 'untested' => 0]
            : ['mutations' => 6, 'tested' => 3, 'untested' => 2])]);
        if ($expectedScore === 0.0) {
            expect($json)->not->toHaveKey('untested_details');
        } else {
            expect($json['untested_details'])->toBe(expectedUntestedDetails());
        }
    }
})->with([
    'threshold met' => [['--min=50'], [], 0, 'passed', 50.0],
    'threshold missed' => [['--min=100'], [], 1, 'failed', 50.0],
    'zero mutations below threshold' => [['--class=Fixture\\NoMutations', '--min=100'], [], 1, 'failed', 0.0],
    'zero mutations with threshold exception' => [['--class=Fixture\\NoMutations', '--min=100', '--ignore-min-score-on-zero-mutations'], [], 0, 'passed', 0.0],
    'initial test failed' => [['--min=50'], ['FIXTURE_FAIL_INITIAL_TEST' => '1'], 1, 'failed', null],
]);

it('does not report mutation success when the output filter name is already taken', function (): void {
    $probe = tempnam(sys_get_temp_dir(), 'pao-filter-collision-');
    if ($probe === false) {
        throw new RuntimeException('Cannot create filter collision probe.');
    }

    file_put_contents($probe, <<<'PHP'
<?php
class CollisionFilter extends php_user_filter
{
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
stream_filter_register('pao_mutation_output', CollisionFilter::class);
PHP);

    try {
        $run = runConsumerPest('pest', ['--mutate', '--min=100'], [], $probe);
        $json = json_decode(trim($run['stdout']), true);

        expect($run['exitCode'])->not->toBe(0)
            ->and($json['result'] ?? null)->not->toBe('passed')
            ->and($run['stdout'])->not->toContain('"result":"passed"');
    } finally {
        unlink($probe);
    }
});

it('does not report mutation success when the output filter cannot attach', function (): void {
    $probe = tempnam(sys_get_temp_dir(), 'pao-filter-attach-');
    if ($probe === false) {
        throw new RuntimeException('Cannot create filter attachment probe.');
    }

    file_put_contents($probe, <<<'PHP'
<?php
namespace Wheesnoza\PestPluginPaoMutation;

function stream_filter_append($stream, string $filter, int $mode)
{
    return $filter === 'pao_mutation_output' ? false : \stream_filter_append($stream, $filter, $mode);
}
PHP);

    try {
        $run = runConsumerPest('pest', ['--mutate', '--min=100'], [], $probe);
        $json = json_decode(trim($run['stdout']), true);

        expect($run['exitCode'])->not->toBe(0)
            ->and($json['result'] ?? null)->not->toBe('passed')
            ->and($run['stdout'])->not->toContain('"result":"passed"');
    } finally {
        unlink($probe);
    }
});

it('reports one mutation result from the parent in parallel execution', function (int $minimum, int $expectedExit, string $expectedResult): void {
    $run = runConsumerPest('mutate', ['--min='.$minimum, '--parallel']);
    $lines = explode("\n", trim($run['stdout']));

    expect($run['exitCode'])->toBe($expectedExit)
        ->and($run['stderr'])->toBe('')
        ->and($lines)->toHaveCount(1);

    expect(json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
        'tool' => 'pest-mutate',
        'result' => $expectedResult,
        'score' => 50,
        'mutations' => 6,
        'tested' => 3,
        'untested' => 2,
    ]);
})->with([
    'parallel threshold met' => [50, 0, 'passed'],
    'parallel threshold missed' => [100, 1, 'failed'],
]);

it('does not change the exit code or claim success after a bootstrap exception', function (): void {
    $bootstrap = tempnam(sys_get_temp_dir(), 'pao-bootstrap-');
    if ($bootstrap === false) {
        throw new RuntimeException('Cannot create bootstrap probe.');
    }

    file_put_contents($bootstrap, '<?php throw new RuntimeException("bootstrap failure");');

    try {
        $arguments = ['--min=50', '--bootstrap='.$bootstrap];
        $baseline = runConsumerPest('mutate', $arguments, ['PAO_DISABLE' => '1']);
        $run = runConsumerPest('mutate', $arguments);

        expect($baseline['exitCode'])->toBe(2)
            ->and($run['exitCode'])->toBe($baseline['exitCode'])
            ->and($run['stdout'])->toContain('bootstrap failure')
            ->and(substr_count($run['stdout'], "\n"))->toBeGreaterThan(1)
            ->and($run['stdout'])->not->toContain('"tool":"pest-mutate"')
            ->and($run['stdout'])->not->toContain('"result":"passed"')
            ->and($run['stdout'])->not->toContain('PAO output stream is unavailable')
            ->and(json_decode($run['stdout'], true))->toBeNull();
    } finally {
        unlink($bootstrap);
    }
});
