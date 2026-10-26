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
    | Value between 0.0 (0%) and 1.0 (100%) indicating how many requests to trace.
    |
    */
    'sample_rate' => (float) env('SERVERFY_SENSE_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Application Performance Monitoring (APM)
    |--------------------------------------------------------------------------
    |
    | Automatically record route execution times, database queries and
    | full Nightwatch-grade visual waterfall traces.
    |
    */
    'apm' => [
        'enabled' => env('SERVERFY_SENSE_APM_ENABLED', true),
        'slow_query_threshold' => (float) env('SERVERFY_SENSE_SLOW_QUERY_THRESHOLD', 100.0),
        'traces_enabled' => env('SERVERFY_SENSE_TRACES_ENABLED', true),
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
