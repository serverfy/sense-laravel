<?php

namespace Serverfy\Sense\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Serverfy\Sense\Client\SenseClient;

class SenseMonologHandler extends AbstractProcessingHandler
{
    protected SenseClient $client;

    public function __construct(int|string|Level $level = Level::Debug, bool $bubble = true, ?SenseClient $client = null)
    {
        parent::__construct($level, $bubble);
        $this->client = $client ?? app(SenseClient::class);
    }

    protected function write(LogRecord $record): void
    {
        // Lines written while a batch is on the wire come from the transport itself.
        if (! $this->client->isConfigured() || $this->client->isSending()) {
            return;
        }

        $this->client->captureLog(
            level: $record->level->name,
            message: $record->message,
            context: $record->context,
            extra: $record->extra,
            channel: $record->channel
        );
    }
}
