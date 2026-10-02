<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\SourceControl\Services\DplyGit;
use Illuminate\Console\Command;

/**
 * One-time platform setup for dply Git (T-034): the Artifacts namespace and
 * the events queue, switched to an HTTP pull consumer. Idempotent.
 */
class SetupDplyGitCommand extends Command
{
    protected $signature = 'dply:edge:git-setup {--queue=dply-git-events : Queue name for Artifacts push events}';

    protected $description = 'Create the dply Git namespace and push-events queue on the platform Cloudflare account';

    public function handle(DplyGit $dplyGit): int
    {
        $client = $dplyGit->client();
        $namespace = (string) config('edge.git.namespace', 'dply');

        try {
            $client->ensureNamespace($namespace);
            $this->info("Artifacts namespace: {$namespace}");

            $queueId = $client->ensureQueue((string) $this->option('queue'));
            $client->enableHttpPull($queueId);
            $this->info("Events queue: {$this->option('queue')} ({$queueId}), HTTP pull on");
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->line('The platform token (DPLY_EDGE_CF_API_TOKEN) needs Artifacts Edit and Queues Write, and the account needs Artifacts beta access.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Set DPLY_GIT_QUEUE_ID={$queueId} in .env, deploy, then: php artisan dply:feature git <org-slug>");

        return self::SUCCESS;
    }
}
