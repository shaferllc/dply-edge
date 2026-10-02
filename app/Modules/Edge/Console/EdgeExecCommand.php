<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerAgent;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run a command in a container app through the dply agent (EdgeContainerAgent),
 * streaming its output: an operator's way in until the dashboard Console
 * (T-038) exists, and the way to check the agent on a real app.
 *
 *   php artisan dply:edge:exec {site} "php artisan about" [--target=jobs|instance-0|worker-0] [--timeout=300] [--no-wake]
 */
class EdgeExecCommand extends Command
{
    protected $signature = 'dply:edge:exec
        {site : Site id or slug}
        {cmd : The command, run with /bin/sh -c}
        {--target= : jobs, instance-N or worker-N (default: jobs, else instance-0)}
        {--timeout=300 : Seconds before the agent kills it (at most 3600)}
        {--no-wake : Do nothing if the container is asleep}';

    protected $description = 'Run a command in a container app through the dply agent and stream its output.';

    public function handle(): int
    {
        $key = (string) $this->argument('site');
        $site = Site::query()->whereKey($key)->orWhere('slug', $key)->first();
        if ($site === null) {
            $this->error("No site {$key}.");

            return self::FAILURE;
        }
        if (! EdgeContainerAgent::live($site)) {
            $this->warn('The live deploy was not built with the dply agent; this will likely fail. Turn the flag on (dply:feature agent {org}) and redeploy.');
        }
        try {
            $result = EdgeContainerAgent::exec($site, (string) $this->argument('cmd'), function (array $line): void {
                if (isset($line['out'])) {
                    $this->output->write((string) $line['out']);
                } elseif (isset($line['err'])) {
                    $this->output->getErrorStyle()->write((string) $line['err']);
                }
            }, $this->option('target') ?: null, (int) $this->option('timeout'), ! $this->option('no-wake'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (($result['asleep'] ?? false) === true) {
            $this->line('The container is asleep; nothing ran (--no-wake).');

            return self::SUCCESS;
        }
        $this->newLine();
        $this->line(sprintf('exit %d · %.1fs%s%s', (int) $result['exit'], (float) $result['seconds'], ($result['timed_out'] ?? false) ? ' · timed out' : '', ($result['truncated'] ?? false) ? ' · output truncated at 1 MB' : ''));

        return (int) $result['exit'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
