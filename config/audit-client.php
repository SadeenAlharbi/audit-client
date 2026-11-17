<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    | When false, nothing is buffered, queued or sent. Useful for local
    | development and for test suites of the host application.
    */
    'enabled' => (bool) env('AUDIT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Platform connection
    |--------------------------------------------------------------------------
    | endpoint:    base URL of the API including the version prefix,
    |              e.g. https://audit.example.com/api/v1
    | project:     the project slug (sent as X-Project).
    | api_key:     the project API key (sent as "Authorization: Bearer").
    | hmac_secret: signing secret of the key. Null disables request signing.
    | timeout:     whole seconds to wait for a response; connect_timeout
    |              for the TCP/TLS handshake.
    */
    'endpoint' => env('AUDIT_ENDPOINT'),
    'project' => env('AUDIT_PROJECT'),
    'api_key' => env('AUDIT_API_KEY'),
    'hmac_secret' => env('AUDIT_HMAC_SECRET'),
    'timeout' => (int) env('AUDIT_TIMEOUT', 5),
    'connect_timeout' => (int) env('AUDIT_CONNECT_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Logs are always sent from a queued job so the application never waits
    | for, or fails because of, the platform. Null uses the application's
    | default connection / queue. Use the "sync" connection to send inline
    | (tests, one-off console scripts); failures then go straight to the
    | outbox instead of being retried.
    */
    'queue' => [
        'connection' => env('AUDIT_QUEUE_CONNECTION'),
        'name' => env('AUDIT_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    | tries:   total delivery attempts of one job (first try included).
    | backoff: seconds to wait before each retry. The last value is reused
    |          when there are more retries than values. A Retry-After header
    |          sent with 429 responses takes precedence.
    */
    'retry' => [
        'tries' => (int) env('AUDIT_RETRY_TRIES', 6),
        'backoff' => [10, 30, 90, 270, 810],
    ],

    /*
    |--------------------------------------------------------------------------
    | Batching
    |--------------------------------------------------------------------------
    | When enabled, logs recorded during one HTTP request, console command or
    | queued job are buffered and sent together through POST /logs/batch.
    | The buffer is flushed when it reaches "size" (max 500, the API limit)
    | and, when flush_on_terminate is true, when the request / command / job
    | ends. Keep "size" well below 500 if your logs are large (the API
    | accepts at most 1 MB per request).
    */
    'batch' => [
        'enabled' => (bool) env('AUDIT_BATCH', true),
        'size' => (int) env('AUDIT_BATCH_SIZE', 100),
        'flush_on_terminate' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Masking
    |--------------------------------------------------------------------------
    | Values of these keys inside old_values, new_values and metadata are
    | replaced before the log leaves the application (and before it is
    | written to the queue or the outbox), at any depth. A key matches by
    | its words, whatever the case or separator: "password" also masks
    | "user_password", "newPassword" and "password_confirmation"; "token"
    | masks "access_token" and "remember_token". Values that look like
    | credentials (Bearer headers, JWTs, API keys) are masked whatever the
    | key. The default list is the platform's "never store" list.
    */
    'mask' => [
        'password', 'passwd', 'pwd', 'passphrase',
        'secret', 'token', 'api_key', 'apikey', 'authorization',
        'credential', 'credentials', 'cookie', 'jwt', 'private_key',
        'otp', 'pin',
        'card_number', 'cardnumber', 'credit_card', 'pan', 'cvv', 'cvc',
    ],

    'mask_replacement' => '********',

    /*
    |--------------------------------------------------------------------------
    | Outbox
    |--------------------------------------------------------------------------
    | Where logs go when they cannot be delivered (all retries exhausted or
    | rejected by the platform). Re-send them with "php artisan audit:flush".
    |
    | driver: "database" (table below, publish the migration with
    |         --tag=audit-migrations), "file" (JSON lines at "path") or
    |         "null" (drop them, a warning is written to the log).
    */
    'outbox' => [
        'driver' => env('AUDIT_OUTBOX_DRIVER', 'database'),
        'connection' => env('AUDIT_OUTBOX_CONNECTION'),
        'table' => 'audit_outbox',
        'path' => storage_path('audit-client/outbox.jsonl'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic logging
    |--------------------------------------------------------------------------
    | listen_auth_events: log Login, Logout and Failed authentication events
    |                     as auth.login, auth.logout and auth.failed.
    | resolve_actor:      when no actor is given, use the authenticated user.
    */
    'listen_auth_events' => (bool) env('AUDIT_LISTEN_AUTH_EVENTS', true),

    'resolve_actor' => true,

];
