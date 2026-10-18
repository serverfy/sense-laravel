<?php

namespace Serverfy\Sense\Console\Commands;

use Illuminate\Console\Command;
use Serverfy\Sense\Client\SenseClient;

class SenseTestCommand extends Command
{
    protected $signature = 'sense:test';

    protected $description = 'Send a test telemetry event and APM span to verify connectivity with Serverfy Sense';

    public function handle(SenseClient $client): int
    {
        $this->info('📡 Testing Serverfy Sense connectivity...');

        if (! $client->isConfigured()) {
            $this->error('❌ Serverfy Sense is not configured. Please set SERVERFY_SENSE_DSN in your .env file.');

            return self::FAILURE;
        }

        $this->line("• Endpoint: <comment>{$client->getEndpoint()}</comment>");
        $this->line('• API Key:  <comment>'.substr($client->getKey() ?? '', 0, 12).'...</comment>');

        $startTime = microtime(true);

        // Test Span
        $success = $client->captureSpan(
            type: 'custom',
            name: 'artisan sense:test verification',
            durationMs: 12.34,
            meta: [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'environment' => app()->environment(),
            ]
        );

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);

        if ($success) {
            $this->info("✅ Successfully delivered test APM span to Serverfy Sense in {$elapsed}ms!");

            return self::SUCCESS;
        }

        $this->error("❌ Delivery failed after {$elapsed}ms. Check network connectivity or local agent daemon.");

        return self::FAILURE;
    }
}
