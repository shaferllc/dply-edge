<?php

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\SocketHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    // A container has no durable storage/logs: stderr is what the platform
    // collects (JSON lines, see the stderr channel).
    'default' => env('LOG_CHANNEL', strtolower((string) env('DPLY_RUNTIME')) === 'container' ? 'stderr' : 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            // Opt-in (default false = unchanged): set true when a network sink
            // like `dply_logs` is in the stack so a drain hiccup can't break
            // application logging.
            'ignore_exceptions' => filter_var(env('LOG_STACK_IGNORE_EXCEPTIONS', false), FILTER_VALIDATE_BOOL),
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        // dply Logs — ships this app's own logs to the dply Logs drain over
        // TLS/TCP (built-in Monolog handlers only). Opt in by adding `dply_logs`
        // to LOG_STACK and setting DPLY_LOG_DRAIN_HOST/PORT/TOKEN. warning+ keeps
        // volume sane; short timeouts + LOG_STACK_IGNORE_EXCEPTIONS keep a slow or
        // unreachable drain from affecting the app. See docs/LOG_DRAIN_RECEIVER.md.
        'dply_logs' => [
            'driver' => 'monolog',
            'level' => env('DPLY_LOG_DRAIN_LEVEL', 'warning'),
            'handler' => SocketHandler::class,
            'handler_with' => [
                'connectionString' => (filter_var(env('DPLY_LOG_DRAIN_TLS', true), FILTER_VALIDATE_BOOL) ? 'tls://' : 'tcp://').env('DPLY_LOG_DRAIN_HOST').':'.env('DPLY_LOG_DRAIN_PORT'),
                'persistent' => true,
                'timeout' => 0.5,
                'writingTimeout' => 0.5,
                'connectionTimeout' => 0.5,
            ],
            'formatter' => LineFormatter::class,
            'formatter_with' => [
                'format' => env('DPLY_LOG_DRAIN_TOKEN').' %level_name% %message% %context%'."\n",
                'allowInlineLineBreaks' => false,
                'ignoreEmptyContextAndExtra' => true,
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            // Structured (one JSON object per line) in a container.
            'formatter' => env('LOG_STDERR_FORMATTER', strtolower((string) env('DPLY_RUNTIME')) === 'container' ? JsonFormatter::class : null),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
