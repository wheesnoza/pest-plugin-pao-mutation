<?php

declare(strict_types=1);

namespace Wheesnoza\PestPluginPaoMutation;

use JsonException;
use LogicException;
use php_user_filter;

/** @internal */
final class PaoOutputFilter extends php_user_filter
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const FAILURE = '{"tool":"pest-mutate","result":"failed"}'."\n";

    private static ?MutationResult $result = null;

    private string $buffer = '';

    private bool $emitted = false;

    public static function setResult(?MutationResult $result): void
    {
        self::$result = $result;
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        // Wait for the first complete line even when PAO's output arrives in pieces.
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;

            // Emit one final result and discard anything that follows it.
            if ($this->emitted) {
                continue;
            }

            $end = strpos($bucket->data, "\n");
            $partLength = $end === false ? $bucket->datalen : $end;

            // Stop buffering oversized output and emit a short failure result.
            if (strlen($this->buffer) + $partLength > self::MAX_BYTES) {
                $this->emit($out, self::FAILURE);

                continue;
            }

            $this->buffer .= $end === false ? $bucket->data : substr($bucket->data, 0, $end);

            if ($end !== false) {
                $this->emit($out, $this->replace($this->buffer));
            }
        }

        // Do not report success if the stream ends before a full line arrives.
        if ($closing && ! $this->emitted && is_resource($this->stream)) {
            $this->emit($out, self::FAILURE);
        }

        return PSFS_PASS_ON;
    }

    /** @param resource $out */
    private function emit($out, string $text): void
    {
        if (! is_resource($this->stream)) {
            throw new LogicException('PAO output stream is unavailable.');
        }

        stream_bucket_append($out, stream_bucket_new($this->stream, $text));
        $this->buffer = '';
        $this->emitted = true;
    }

    private function replace(string $line): string
    {
        try {
            $pao = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            // Replace output only when both PAO's Pest result and the mutation result are available.
            if (! is_array($pao)
                || ($pao['tool'] ?? null) !== 'pest'
                || ! in_array($pao['result'] ?? null, ['passed', 'failed'], true)
                || ! self::$result instanceof MutationResult
            ) {
                return self::FAILURE;
            }

            $result = self::$result->toArray();

            // Preserve a failed original test run regardless of the mutation score.
            if ($pao['result'] === 'failed') {
                $result['result'] = 'failed';
            }

            return json_encode(['tool' => 'pest-mutate'] + $result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
        } catch (JsonException) {
            return self::FAILURE;
        }
    }
}
