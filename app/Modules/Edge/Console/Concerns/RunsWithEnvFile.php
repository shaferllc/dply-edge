<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console\Concerns;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Process;

/**
 * `--env-file=<prod .env>` for the dply:self:* commands: run the command
 * again in a child whose process env IS that file, so every config value
 * (Cloudflare account, namespace, R2, database, APP_KEY) is production's and
 * never this machine's .env. Real env vars win over .env in Laravel, and
 * every key only this machine's .env sets is unset in the child.
 */
trait RunsWithEnvFile
{
    public const CHILD_FLAG = 'DPLY_SELF_CHILD';

    private const GLOBAL_OPTIONS = ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env'];

    protected function isEnvFileChild(): bool
    {
        return getenv(self::CHILD_FLAG) === '1';
    }

    /**
     * @param  list<string>  $required  keys the file must set
     * @param  array<string, string>  $overrides  extra options for the child (e.g. a phase)
     */
    protected function reexecWithEnvFile(string $envFile, array $required, array $overrides = []): int
    {
        if (! is_readable($envFile)) {
            $this->error("Cannot read {$envFile}.");

            return self::FAILURE;
        }
        if (app()->configurationIsCached()) {
            $this->error('Config is cached (bootstrap/cache/config.php), so the env file would be ignored. Run php artisan config:clear first.');

            return self::FAILURE;
        }
        $vars = Dotenv::parse((string) file_get_contents($envFile));
        // Settings committed in .env.<APP_ENV> (.env.production) are loaded by
        // the child and the deployed app too, so they satisfy the check.
        $committedFile = base_path('.env.'.($vars['APP_ENV'] ?? ''));
        $committed = isset($vars['APP_ENV']) && is_readable($committedFile) ? Dotenv::parse((string) file_get_contents($committedFile)) : [];
        foreach ($required as $key) {
            if (trim((string) ($vars[$key] ?? $committed[$key] ?? '')) === '') {
                $this->error("{$envFile} has no {$key}.");

                return self::FAILURE;
            }
        }
        $this->line(sprintf('Using %s: %d variables (%s…).', $envFile, count($vars), implode(', ', array_slice(array_keys($vars), 0, 6))));

        $argv = [PHP_BINARY, base_path('artisan'), (string) $this->getName()];
        foreach (array_merge($this->options(), $overrides) as $name => $value) {
            if (in_array($name, self::GLOBAL_OPTIONS, true) || $value === null || $value === false || $value === '') {
                continue;
            }
            $argv[] = $value === true ? '--'.$name : '--'.$name.'='.$value;
        }

        $local = is_readable(app()->environmentFilePath()) ? Dotenv::parse((string) file_get_contents(app()->environmentFilePath())) : [];
        $forced = [
            self::CHILD_FLAG => '1',
            // This process only (the deployed env is the file as written):
            // never fake, and no Valkey needed to run the command itself.
            'DPLY_FAKE_EDGE' => 'false',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'LOG_CHANNEL' => 'stderr',
        ];
        $unset = array_fill_keys(array_keys(array_diff_key($local, $vars)), false);

        $result = Process::forever()->env($forced + $vars + $unset)->run($argv, function (string $type, string $output): void {
            $this->output->write($output);
        });

        return $result->exitCode() ?? self::FAILURE;
    }
}
