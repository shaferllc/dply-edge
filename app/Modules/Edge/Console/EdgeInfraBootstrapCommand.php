<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use App\Modules\Edge\Support\EdgePlatformCredentials;
use Illuminate\Console\Command;

/**
 * Bootstrap everything dply Edge needs on Cloudflare from ONE API token:
 * account id (looked up), R2 bucket, KV namespaces, SSR dispatch namespace,
 * and R2 S3 credentials — Cloudflare derives those from an API token
 * (access key = token id, secret = sha256(token value)), so no dashboard
 * trip to "Manage R2 API tokens".
 *
 *   php artisan dply:edge:infra:bootstrap --token=<cloudflare API token> --write
 *   php artisan config:cache && php artisan horizon:terminate
 */
class EdgeInfraBootstrapCommand extends Command
{
    protected $signature = 'dply:edge:infra:bootstrap
                            {--token= : Cloudflare user API token (default: DPLY_EDGE_CF_API_TOKEN)}
                            {--account= : Cloudflare account id (default: DPLY_EDGE_CF_ACCOUNT_ID, else looked up from the token)}
                            {--bucket= : R2 bucket name (default: dply-edge-artifacts)}
                            {--kv-title=dply-edge-host-map : KV namespace title for host map}
                            {--cache-kv-title=dply-edge-cache : KV namespace title for hybrid origin cache}
                            {--dispatch-name=dply-edge-ssr : Workers for Platforms dispatch namespace for SSR scripts}
                            {--skip-dispatch : Skip creating the dispatch namespace (SSR sites won\'t work)}
                            {--write : Write the resolved values into .env (backs it up to .env.bak first)}
                            {--dry-run : Print planned actions without calling Cloudflare}';

    protected $description = 'Create Edge R2 bucket, KV + dispatch namespaces and R2 keys via the Cloudflare API';

    private const SECRET_KEYS = ['DPLY_EDGE_CF_API_TOKEN', 'DPLY_EDGE_R2_SECRET'];

    public function handle(): int
    {
        $token = trim((string) ($this->option('token') ?: config('edge.cloudflare.api_token')));
        $accountId = trim((string) ($this->option('account') ?: config('edge.cloudflare.account_id')));

        if ($token === '') {
            $this->error('Pass --token=<Cloudflare API token> or set DPLY_EDGE_CF_API_TOKEN before bootstrapping.');

            return self::FAILURE;
        }

        $bucket = (string) ($this->option('bucket') ?: config('edge.r2.bucket') ?: 'dply-edge-artifacts');
        $kvTitle = (string) $this->option('kv-title');
        $cacheKvTitle = (string) $this->option('cache-kv-title');
        $dispatchName = (string) $this->option('dispatch-name');
        $skipDispatch = (bool) $this->option('skip-dispatch');
        $r2Key = trim((string) config('edge.r2.key'));
        $r2Secret = trim((string) config('edge.r2.secret'));

        if ($this->option('dry-run')) {
            $this->info('[dry-run] Would verify Cloudflare token');
            if ($accountId === '') {
                $this->line('[dry-run] Would look up the account id from the token');
            }
            $this->line('[dry-run] R2 bucket: '.$bucket);
            $this->line('[dry-run] KV namespace title (host map): '.$kvTitle);
            $this->line('[dry-run] KV namespace title (origin cache): '.$cacheKvTitle);
            if ($skipDispatch) {
                $this->line('[dry-run] Skipping dispatch namespace (Phase 4b SSR will be unavailable).');
            } else {
                $this->line('[dry-run] Dispatch namespace (Phase 4b SSR): '.$dispatchName);
            }
            $this->printEnv($this->envValues(
                $bucket,
                $accountId,
                $token,
                (string) config('edge.cloudflare.kv_namespace_id'),
                (string) config('edge.cloudflare.cache_kv_namespace_id'),
                $skipDispatch ? '' : $dispatchName,
                (string) config('edge.cloudflare.dispatch_namespace_id'),
                $r2Key,
                $r2Secret,
            ), mask: true);

            return self::SUCCESS;
        }

        try {
            if ($accountId === '') {
                $accountId = $this->resolveAccountId($token);
                if ($accountId === null) {
                    return self::FAILURE;
                }
            }

            $client = new EdgeCloudflareClient($accountId, $token);
            $verify = $client->verifyToken();
            $this->info('Cloudflare token verified ('.(string) ($verify['status'] ?? 'ok').').');

            if ($client->r2BucketExists($bucket)) {
                $this->line('R2 bucket already exists: '.$bucket);
            } else {
                $client->createR2Bucket($bucket);
                $this->info('Created R2 bucket: '.$bucket);
            }

            $kvId = (string) config('edge.cloudflare.kv_namespace_id');
            if ($kvId === '') {
                $existing = $client->kvNamespaceIdByTitle($kvTitle);
                if ($existing !== null) {
                    $kvId = $existing;
                    $this->line('KV namespace already exists: '.$kvTitle.' ('.$kvId.')');
                } else {
                    $created = $client->createKvNamespace($kvTitle);
                    $kvId = is_string($created['id'] ?? null) ? $created['id'] : '';
                    $this->info('Created KV namespace: '.$kvTitle.' ('.$kvId.')');
                }
            } else {
                $this->line('Using configured KV namespace id: '.$kvId);
            }

            $cacheKvId = (string) config('edge.cloudflare.cache_kv_namespace_id');
            if ($cacheKvId === '') {
                $existingCache = $client->kvNamespaceIdByTitle($cacheKvTitle);
                if ($existingCache !== null) {
                    $cacheKvId = $existingCache;
                    $this->line('Cache KV namespace already exists: '.$cacheKvTitle.' ('.$cacheKvId.')');
                } else {
                    $createdCache = $client->createKvNamespace($cacheKvTitle);
                    $cacheKvId = is_string($createdCache['id'] ?? null) ? $createdCache['id'] : '';
                    $this->info('Created cache KV namespace: '.$cacheKvTitle.' ('.$cacheKvId.')');
                }
            } else {
                $this->line('Using configured cache KV namespace id: '.$cacheKvId);
            }

            $dispatchId = (string) config('edge.cloudflare.dispatch_namespace_id');
            $resolvedDispatchName = '';
            if (! $skipDispatch) {
                try {
                    $existingDispatch = $client->dispatchNamespaceIdByName($dispatchName);
                    if ($existingDispatch !== null) {
                        $dispatchId = $existingDispatch;
                        $resolvedDispatchName = $dispatchName;
                        $this->line('Dispatch namespace already exists: '.$dispatchName.' ('.$dispatchId.')');
                    } else {
                        $createdDispatch = $client->createDispatchNamespace($dispatchName);
                        $newId = $createdDispatch['namespace_id'] ?? $createdDispatch['id'] ?? '';
                        $dispatchId = is_string($newId) ? $newId : '';
                        $resolvedDispatchName = $dispatchName;
                        $this->info('Created dispatch namespace: '.$dispatchName.' ('.$dispatchId.')');
                    }
                } catch (\Throwable $e) {
                    $this->warn('Could not create dispatch namespace ('.$e->getMessage().').');
                    $this->warn('SSR Edge sites (runtime_mode=ssr) will be blocked until this is set up.');
                    $this->warn('Workers for Platforms requires the Workers Paid plan + dispatch namespace permission on the API token.');
                }
            } else {
                $this->line('Skipped dispatch namespace (--skip-dispatch). SSR sites disabled.');
            }

            // R2 S3 credentials from the API token itself. Only valid when the
            // token carries "Workers R2 Storage: Edit".
            if ($r2Key === '' || $r2Secret === '') {
                $tokenId = is_string($verify['id'] ?? null) ? $verify['id'] : '';
                if ($tokenId !== '') {
                    $r2Key = $tokenId;
                    $r2Secret = hash('sha256', $token);
                    $this->info('Derived R2 S3 credentials from the API token (needs Workers R2 Storage: Edit).');
                }
            }

            $env = $this->envValues($bucket, $accountId, $token, $kvId, $cacheKvId, $resolvedDispatchName, $dispatchId, $r2Key, $r2Secret);

            $this->newLine();
            $this->printEnv($env, mask: (bool) $this->option('write'));
            if ($this->option('write')) {
                $this->writeEnv($env);
            }
            $this->newLine();
            $this->line('Next: php artisan config:cache && php artisan horizon:terminate');
            $this->line('Then: php artisan dply:edge:doctor --probe');
            $this->line('Ensure delivery features: php artisan dply:edge:ensure-delivery-features');
            $this->line('Deploy worker: php artisan edge:worker:deploy');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveAccountId(string $token): ?string
    {
        $accounts = array_values(array_filter(
            (new EdgeCloudflareClient('', $token))->listAccounts(),
            static fn (mixed $account): bool => is_array($account) && is_string($account['id'] ?? null),
        ));

        if (count($accounts) === 1) {
            $this->line('Using Cloudflare account: '.($accounts[0]['name'] ?? '').' ('.$accounts[0]['id'].')');

            return $accounts[0]['id'];
        }

        if ($accounts === []) {
            $this->error('This token cannot see any Cloudflare account — give it "Account Settings: Read" or pass --account=<id>.');

            return null;
        }

        $this->error('This token can see several Cloudflare accounts — re-run with --account=<id>:');
        foreach ($accounts as $account) {
            $this->line('  '.$account['id'].'  '.($account['name'] ?? ''));
        }

        return null;
    }

    /**
     * @return array<string, string> only resolved values, in .env order
     */
    private function envValues(
        string $bucket,
        string $accountId,
        string $token,
        string $kvId,
        string $cacheKvId,
        string $dispatchName,
        string $dispatchId,
        string $r2Key,
        string $r2Secret,
    ): array {
        $routes = EdgePlatformCredentials::workerRoutes();
        $zone = trim((string) config('edge.cloudflare.worker_zone_name'));
        if ($zone === '' && $routes !== []) {
            // Default route "*.on-dply.live/*" → zone "on-dply.site".
            $zone = (string) preg_replace('#^\*\.|/.*$#', '', $routes[0]);
        }

        $endpoint = EdgePlatformCredentials::r2Endpoint()
            ?: ($accountId !== '' ? 'https://'.$accountId.'.r2.cloudflarestorage.com' : '');

        return array_filter([
            'DPLY_FAKE_EDGE' => 'false',
            'FEATURE_SURFACE_EDGE' => 'true',
            'DPLY_EDGE_R2_BUCKET' => $bucket,
            'DPLY_EDGE_R2_REGION' => 'auto',
            'DPLY_EDGE_R2_ENDPOINT' => $endpoint,
            'DPLY_EDGE_R2_ACCESS_KEY' => $r2Key,
            'DPLY_EDGE_R2_SECRET' => $r2Secret,
            'DPLY_EDGE_CF_ACCOUNT_ID' => $accountId,
            'DPLY_EDGE_CF_API_TOKEN' => $token,
            'DPLY_EDGE_CF_KV_NAMESPACE_ID' => $kvId,
            'DPLY_EDGE_CF_CACHE_KV_NAMESPACE_ID' => $cacheKvId,
            'DPLY_EDGE_CF_DISPATCH_NAMESPACE' => $dispatchName,
            'DPLY_EDGE_CF_DISPATCH_NAMESPACE_ID' => $dispatchId,
            'DPLY_EDGE_CF_WORKER_SCRIPT' => (string) config('edge.cloudflare.worker_script_name', 'dply-edge'),
            'DPLY_EDGE_CF_ZONE_NAME' => $zone,
            'DPLY_EDGE_CF_WORKER_ROUTES' => $zone !== '' ? implode(',', $routes) : '',
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * @param  array<string, string>  $env
     */
    private function printEnv(array $env, bool $mask): void
    {
        $this->info('Add to production .env:');
        $this->line('');
        foreach ($env as $key => $value) {
            $shown = $mask && in_array($key, self::SECRET_KEYS, true) ? '••••'.substr($value, -4) : $value;
            $this->line($key.'='.$shown);
        }
        if (! isset($env['DPLY_EDGE_R2_ACCESS_KEY'])) {
            $this->line('DPLY_EDGE_R2_ACCESS_KEY=<from Cloudflare R2 API token>');
            $this->line('DPLY_EDGE_R2_SECRET=<from Cloudflare R2 API token>');
        }
    }

    /**
     * Replace each KEY= line in place, append the ones that are missing.
     *
     * @param  array<string, string>  $env
     */
    private function writeEnv(array $env): void
    {
        $path = $this->laravel->environmentFilePath();
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        if (is_file($path)) {
            copy($path, $path.'.bak');
        }

        foreach ($env as $key => $value) {
            $line = $key.'='.$value;
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $contents = preg_match($pattern, $contents) === 1
                ? (string) preg_replace_callback($pattern, static fn (): string => $line, $contents)
                : rtrim($contents, "\n")."\n".$line."\n";
        }

        file_put_contents($path, $contents);
        $this->info('Wrote '.count($env).' keys to '.$path.' (previous copy: '.$path.'.bak).');
    }
}
