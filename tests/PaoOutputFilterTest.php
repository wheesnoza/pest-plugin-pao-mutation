<?php

declare(strict_types=1);

use Pest\Mutate\Mutation;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\Mutators\Equality\GreaterOrEqualToGreater;
use Pest\Mutate\Support\Configuration\Configuration;
use Pest\Mutate\Support\MutationTestResult;
use Symfony\Component\Finder\SplFileInfo;
use Wheesnoza\PestPluginPaoMutation\MutationResult;
use Wheesnoza\PestPluginPaoMutation\PaoOutputFilter;

/** @return array{0: resource, 1: resource} */
function filteredPaoStream(): array
{
    if (! in_array('pao_mutation_test', stream_get_filters(), true)) {
        stream_filter_register('pao_mutation_test', PaoOutputFilter::class);
    }

    $stream = fopen('php://temp', 'w+');
    $filter = stream_filter_append($stream, 'pao_mutation_test', STREAM_FILTER_WRITE);

    return [$stream, $filter];
}

function readFilteredPaoStream(mixed $stream, mixed $filter): string
{
    stream_filter_remove($filter);
    rewind($stream);
    $output = stream_get_contents($stream);
    fclose($stream);

    return $output;
}

it('replaces a split PAO line with one mutation result', function (): void {
    $configuration = new Configuration(false, [], [], [], [], false, 1, false, null, false, false, false, null, false, false);
    PaoOutputFilter::setResult(MutationResult::fromSuite(new MutationSuite, $configuration));
    [$stream, $filter] = filteredPaoStream();

    $first = '{"tool":"pe';
    $second = 'st","result":"passed","tests":1}'."\n";
    expect(fwrite($stream, $first))->toBe(strlen($first));
    expect(fwrite($stream, $second))->toBe(strlen($second));
    $output = readFilteredPaoStream($stream, $filter);

    expect(substr_count($output, "\n"))->toBe(1);
    expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toMatchArray([
        'tool' => 'pest-mutate', 'result' => 'passed', 'score' => 0.0,
        'mutations' => 0, 'tested' => 0, 'untested' => 0,
    ]);
});

it('keeps mutation details when source text contains invalid UTF-8', function (): void {
    $suite = new MutationSuite;
    $file = new SplFileInfo(__FILE__, '', '');
    $suite->repository->add(new Mutation($file, 'invalid-utf8', GreaterOrEqualToGreater::class, 1, 1, "  <fg=red>- '\xff';</>\n  <fg=green>+ 'ok';</>\n", ''));
    $suite->repository->all()[$file->getRealPath()]->tests()[0]->updateResult(MutationTestResult::Untested);
    $configuration = new Configuration(false, [], [], [], [], false, 1, false, null, false, false, false, null, false, false);
    PaoOutputFilter::setResult(MutationResult::fromSuite($suite, $configuration));
    [$stream, $filter] = filteredPaoStream();
    fwrite($stream, '{"tool":"pest","result":"passed"}'."\n");

    $result = json_decode(readFilteredPaoStream($stream, $filter), true, flags: JSON_THROW_ON_ERROR);
    expect($result['untested_details'][0]['diff']['original'])->toBe(" '\u{FFFD}';");
});

it('fails without invented scores when the result is absent or input is not PAO JSON', function (string $input): void {
    PaoOutputFilter::setResult(null);
    [$stream, $filter] = filteredPaoStream();
    fwrite($stream, $input);
    $output = readFilteredPaoStream($stream, $filter);

    expect($output)->toBe('{"tool":"pest-mutate","result":"failed"}'."\n");
})->with(["{\"tool\":\"pest\",\"result\":\"passed\"}\n", "not json\n", '{"tool":"phpunit"}'."\n", '{"tool":"pest"}']);

it('emits one failure when input exceeds eight mebibytes', function (): void {
    PaoOutputFilter::setResult(null);
    [$stream, $filter] = filteredPaoStream();
    fwrite($stream, str_repeat('x', 8 * 1024 * 1024 + 1));
    fwrite($stream, "more\n");

    expect(readFilteredPaoStream($stream, $filter))->toBe('{"tool":"pest-mutate","result":"failed"}'."\n");
});

it('discards trailing output after the first complete PAO line', function (): void {
    $configuration = new Configuration(false, [], [], [], [], false, 1, false, null, false, false, false, null, false, false);
    PaoOutputFilter::setResult(MutationResult::fromSuite(new MutationSuite, $configuration));
    [$stream, $filter] = filteredPaoStream();
    fwrite($stream, '{"tool":"pest","result":"passed"}'."\n".str_repeat('x', 8 * 1024 * 1024 + 1));

    expect(json_decode(readFilteredPaoStream($stream, $filter), true, 512, JSON_THROW_ON_ERROR)['result'])->toBe('passed');
});

it('does not turn a failed PAO result into success', function (): void {
    $configuration = new Configuration(false, [], [], [], [], false, 1, false, null, false, false, false, null, false, false);
    PaoOutputFilter::setResult(MutationResult::fromSuite(new MutationSuite, $configuration));
    [$stream, $filter] = filteredPaoStream();
    fwrite($stream, '{"tool":"pest","result":"failed"}'."\n");

    expect(json_decode(readFilteredPaoStream($stream, $filter), true, 512, JSON_THROW_ON_ERROR)['result'])->toBe('failed');
});

it('rejects malformed and incomplete input even when a mutation result exists', function (string $input): void {
    $configuration = new Configuration(false, [], [], [], [], false, 1, false, null, false, false, false, null, false, false);
    PaoOutputFilter::setResult(MutationResult::fromSuite(new MutationSuite, $configuration));
    [$stream, $filter] = filteredPaoStream();

    expect(fwrite($stream, $input))->toBe(strlen($input));
    $output = readFilteredPaoStream($stream, $filter);

    expect(substr_count($output, "\n"))->toBe(1);
    expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe([
        'tool' => 'pest-mutate',
        'result' => 'failed',
    ]);
})->with([
    'malformed JSON' => ['{"tool":"pest","result":}end'."\n"],
    'incomplete line at closing' => ['{"tool":"pest","result":"passed"}'],
    'non-PAO JSON' => ['{"tool":"other","result":"passed"}'."\n"],
]);

it('counts all input bytes but emits nothing after the first replacement', function (): void {
    $configuration = new Configuration(false, [], [], [], [], false, 1, false, null, false, false, false, null, false, false);
    PaoOutputFilter::setResult(MutationResult::fromSuite(new MutationSuite, $configuration));
    [$stream, $filter] = filteredPaoStream();

    $first = '{"tool":"pest","result":"passed"}'."\n";
    $second = 'ignored trailing output'."\n";
    expect(fwrite($stream, $first))->toBe(strlen($first));
    expect(fwrite($stream, $second))->toBe(strlen($second));

    $output = readFilteredPaoStream($stream, $filter);
    expect(substr_count($output, "\n"))->toBe(1);
    expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR)['tool'])->toBe('pest-mutate');
});

it('counts all bytes after an oversized incomplete line but emits one failure', function (): void {
    PaoOutputFilter::setResult(null);
    [$stream, $filter] = filteredPaoStream();

    $first = str_repeat('x', 8 * 1024 * 1024);
    $overflow = 'x';
    $later = "ignored\n";
    expect(fwrite($stream, $first))->toBe(strlen($first));
    expect(fwrite($stream, $overflow))->toBe(strlen($overflow));
    expect(fwrite($stream, $later))->toBe(strlen($later));

    $output = readFilteredPaoStream($stream, $filter);
    expect(substr_count($output, "\n"))->toBe(1);
    expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe([
        'tool' => 'pest-mutate',
        'result' => 'failed',
    ]);
});
