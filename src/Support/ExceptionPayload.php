<?php

namespace Serverfy\Sense\Support;

use Throwable;

/**
 * Turns an exception into the item the ingest endpoint stores: class, message, frames and
 * the source lines around the place it was thrown.
 */
class ExceptionPayload
{
    public const MAX_FRAMES = 40;

    public const CONTEXT_LINES = 6;

    /**
     * @return array<string, mixed>
     */
    public static function from(Throwable $e): array
    {
        $frames = [[
            'file' => self::relative($e->getFile()),
            'line' => $e->getLine(),
            'function' => null,
            'class' => null,
            'in_app' => self::inApp($e->getFile()),
        ]];

        foreach (array_slice($e->getTrace(), 0, self::MAX_FRAMES - 1) as $frame) {
            $file = $frame['file'] ?? null;

            $frames[] = [
                'file' => $file ? self::relative($file) : '[internal]',
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? null,
                'class' => isset($frame['class']) ? $frame['class'].($frame['type'] ?? '::') : null,
                'in_app' => $file ? self::inApp($file) : false,
            ];
        }

        $previous = [];
        for ($prev = $e->getPrevious(), $i = 0; $prev && $i < 5; $prev = $prev->getPrevious(), $i++) {
            $previous[] = [
                'class' => get_class($prev),
                'message' => mb_strcut($prev->getMessage(), 0, 2000),
                'file' => self::relative($prev->getFile()),
                'line' => $prev->getLine(),
            ];
        }

        return array_filter([
            'class' => get_class($e),
            'message' => mb_strcut($e->getMessage(), 0, 4000),
            'severity' => 'error',
            'environment' => (string) (config('sense.environment') ?: config('app.env', 'production')),
            'release' => config('sense.release') ?: null,
            'stack_trace' => $frames,
            'code_context' => self::codeContext($e->getFile(), $e->getLine()),
            'previous' => $previous ?: null,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], fn ($value) => $value !== null);
    }

    /**
     * Source lines around the failing line, keyed by line number.
     *
     * @return array<string, mixed>|null
     */
    public static function codeContext(string $file, int $line): ?array
    {
        if ($line < 1 || ! is_file($file) || ! is_readable($file) || filesize($file) > 2_000_000) {
            return null;
        }

        try {
            $source = new \SplFileObject($file);
            $start = max(1, $line - self::CONTEXT_LINES);
            $end = $line + self::CONTEXT_LINES;
            $lines = [];

            $source->seek($start - 1);
            for ($number = $start; $number <= $end && ! $source->eof(); $number++) {
                $lines[(string) $number] = mb_strcut(rtrim((string) $source->current(), "\r\n"), 0, 300);
                $source->next();
            }

            return $lines === [] ? null : [
                'file' => self::relative($file),
                'line' => $line,
                'lines' => $lines,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    protected static function relative(string $file): string
    {
        $base = function_exists('base_path') ? base_path() : '';

        return $base !== '' && str_starts_with($file, $base.DIRECTORY_SEPARATOR)
            ? substr($file, strlen($base) + 1)
            : $file;
    }

    protected static function inApp(string $file): bool
    {
        return ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR);
    }
}
