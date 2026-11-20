<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Serverfy Sense DSN
    |--------------------------------------------------------------------------
    |
    | The Data Source Name provided by your Serverfy Console under Site > Sense.
    | Format: https://your-serverfy.com/api/v1/telemetry?key=sfy_live_...
    |
    */
    'dsn' => env('SERVERFY_SENSE_DSN', env('SERVERFY_DSN', '')),

    /*
    |--------------------------------------------------------------------------
    | Master Switch, Environment and Release
    |--------------------------------------------------------------------------
    |
    | Turn everything off without removing the package, and label what is sent.
    | "release" is usually a git commit or a version tag; it lets Sense tell
    | you in which deploy an error first appeared.
    |
    */
    'enabled' => env('SERVERFY_SENSE_ENABLED', true),
    'environment' => env('SERVERFY_SENSE_ENVIRONMENT'),
    'release' => env('SERVERFY_SENSE_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Batch Size
    |--------------------------------------------------------------------------
    |
    | Telemetry is collected in memory and sent once, after the response has
    | gone out (or when a job or command ends). A long-running process that
    | collects more than this many items of one kind sends them early.
    |
    */
    'batch_size' => (int) env('SERVERFY_SENSE_BATCH_SIZE', 100),

    /*
    |--------------------------------------------------------------------------
    | Local Agent Unix Domain Socket Path
    |--------------------------------------------------------------------------
    |
    | If your server runs the local `serverfy-agent` daemon, telemetry spans
    | are dispatched in microseconds (< 0.1ms) via this Unix socket.
    | If the socket is unavailable, it falls back to HTTP automatically.
    |
    */
    'socket_path' => env('SERVERFY_SENSE_SOCKET', '/run/serverfy/sense.sock'),

    /*
    |--------------------------------------------------------------------------
    | Local Agent Loopback UDP Ingestion
    |--------------------------------------------------------------------------
    |
    | For containerized or restricted environments without socket filesystem
    | access, you can stream telemetry frames to the agent via UDP loopback.
    | Set to 0 to disable UDP client transport.
    |
    */
    'udp_host' => env('SERVERFY_SENSE_UDP_HOST', '127.0.0.1'),
    'udp_port' => (int) env('SERVERFY_SENSE_UDP_PORT', 0),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for HTTP requests to the telemetry ingestion endpoint.
    |
    */
    'timeout' => (int) env('SERVERFY_SENSE_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Dynamic Sampling Rate
    |--------------------------------------------------------------------------
    |
    | Value between 0.0 (0%) and 1.0 (100%) indicating how many traces to send.
    | Failed requests and jobs are always sent, and totals shown in Sense are
    | corrected for the rate, so request counts and percentiles stay accurate.
    |
    */
    'sample_rate' => (float) env('SERVERFY_SENSE_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Application Performance Monitoring (APM)
    |--------------------------------------------------------------------------
    |
    | One trace per request, queued job, command and scheduled task, with the
    | queries, cache operations and outgoing HTTP calls made inside it.
    |
    */
    'apm' => [
        'enabled' => env('SERVERFY_SENSE_APM_ENABLED', true),
        'traces_enabled' => env('SERVERFY_SENSE_TRACES_ENABLED', true),

        // Queries slower than this (ms) are reported even outside of a trace.
        'slow_query_threshold' => (float) env('SERVERFY_SENSE_SLOW_QUERY_THRESHOLD', 100.0),

        // Spans kept per trace. Anything beyond is counted but not sent.
        'max_spans' => (int) env('SERVERFY_SENSE_MAX_SPANS', 300),

        // What is recorded inside a trace.
        'cache' => env('SERVERFY_SENSE_TRACE_CACHE', true),
        'http_client' => env('SERVERFY_SENSE_TRACE_HTTP_CLIENT', true),
        'queue' => env('SERVERFY_SENSE_TRACE_QUEUE', true),
        'commands' => env('SERVERFY_SENSE_TRACE_COMMANDS', true),

        // Request paths (as in Request::is) and artisan commands that are never traced.
        'ignore_paths' => ['up', 'livewire/update', 'horizon/*', 'telescope/*', 'pulse/*'],
        'ignore_commands' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Tracking
    |--------------------------------------------------------------------------
    |
    | Every exception your application reports is sent with its stack trace,
    | the source lines around it, the request and the signed-in user id.
    | The request body is only sent when you opt in.
    |
    */
    'errors' => [
        'enabled' => env('SERVERFY_SENSE_ERRORS_ENABLED', true),
        'send_request_body' => env('SERVERFY_SENSE_SEND_REQUEST_BODY', false),
        'hidden_fields' => ['password', 'password_confirmation', 'current_password', '_token'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy by Design & Data Scrubbing
    |--------------------------------------------------------------------------
    |
    | Automatically scrub passwords, authorization tokens, credit cards,
    | cookies and sensitive SQL literals before transmission (LGPD/GDPR).
    |
    */
    'scrubbing' => [
        'pii' => true,
        'sql' => true,
        'mask' => '[REDACTED]',
    ],
];
