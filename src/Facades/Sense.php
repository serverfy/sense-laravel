<?php

namespace Serverfy\Sense\Facades;

use Illuminate\Support\Facades\Facade;
use Serverfy\Sense\Client\SenseClient;

/**
 * @method static bool captureException(\Throwable $e, array $context = [])
 * @method static bool captureLog(string $level, string $message, array $context = [], array $extra = [], ?string $channel = null)
 * @method static bool captureSpan(string $type, string $name, float $durationMs, array $meta = [])
 * @method static bool captureTrace(array $trace)
 * @method static bool metric(string $name, float|int $value = 1)
 * @method static bool flush()
 * @method static int pending()
 * @method static void setTraceContext(?string $traceId, ?string $parentTraceId = null)
 * @method static array getTraceContext()
 * @method static bool isConfigured()
 * @method static array scrubPii(array $data)
 * @method static string scrubSql(string $sql)
 *
 * @see SenseClient
 */
class Sense extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SenseClient::class;
    }
}
