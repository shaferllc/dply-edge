<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\ProviderCredential;
use App\Modules\Edge\Services\EdgeDeliveryFeaturesEnsurer;
use App\Modules\Edge\Services\EdgeOrgInfraBootstrapper;
use App\Modules\Edge\Support\EdgeOrgCredentialConfig;
use App\Modules\Providers\Cloudflare\CloudflareEdgeCredentialValidator;
use Illuminate\Console\Command;

/**
 * Bootstrap R2 + KV in a customer's Cloudflare account for Edge BYO delivery.
 */
class EdgeInfraBootstrapOrgCommand extends Command
{
    protected $signature = 'dply:edge:bootstrap-org
                            {credential : Provider credential ULID}
                            {--account-id= : Cloudflare account id (required on first bootstrap)}
                            {--bucket= : R2 bucket name (default: dply-edge-{org_id})}
                            {--kv-title=dply-edge-host-map : KV namespace title}
                            {--zone-name= : Zone served by the Edge worker (e.g. example.com)}
                            {--worker-script=dply-edge : Worker script name}
                            {--r2-access-key= : R2 S3 access key id (from Cloudflare dashboard)}
                            {--r2-secret= : R2 S3 secret access key}
                            {--dry-run : Print planned actions only}';

    protected $description = 'Bootstrap Edge R2 bucket and KV namespace in an org Cloudflare account';

    public function handle(
        EdgeOrgInfraBootstrapper $bootstrapper,
        CloudflareEdgeCredentialValidator $validator,
    ): int {
        $credential = ProviderCredential::query()->find($this->argument('credential'));
        if ($credential === null) {
            $this->error('Provider credential not found.');

            return self::FAILURE;
        }

        if ($credential->provider !== 'cloudflare') {
            $this->error('Credential must be a Cloudflare provider credential.');

            return self::FAILURE;
        }

        $existing = EdgeOrgCredentialConfig::read($credential);
        $accountId = trim((string) ($this->option('account-id') ?: ($existing['account_id'] ?? '')));
        if ($accountId === '') {
            $this->error('Pass --account-id= on first bootstrap (Cloudflare account id).');

            return self::FAILURE;
        }

        $orgId = (string) ($credential->organization_id ?? 'org');
        $bucket = (string) ($this->option('bucket') ?: ($existing['r2_bucket'] ?? 'dply-edge-'.$orgId));
        $kvTitle = (string) $this->option('kv-title');
        $zoneName = strtolower(trim((string) ($this->option('zone-name') ?: ($existing['worker_zone_name'] ?? ''))));
        $workerScript = (string) $this->option('worker-script');

        if ($this->option('dry-run')) {
            $this->info('[dry-run] Credential: '.$credential->id.' ('.$credential->name.')');
            $this->line('[dry-run] Account: '.$accountId);
            $this->line('[dry-run] R2 bucket: '.$bucket);
            $this->line('[dry-run] KV title: '.$kvTitle);
            $this->line('[dry-run] Zone: '.($zoneName !== '' ? $zoneName : '(not set)'));

            return self::SUCCESS;
        }

        try {
            $validator->validate($credential, $accountId);
        } catch (\Throwable $e) {
            $this->error('Token validation failed: '.$e->getMessage());
            $this->line('Required scopes: Workers Scripts Edit, Workers KV Storage Edit, Workers R2 Storage Edit.');

            return self::FAILURE;
        }

        // Per-org data residency (P57). Map the org's preferred region
        // to R2 jurisdiction + location hint. CF jurisdictions:
        //   default | eu | fedramp.
        // Location hints: weur | eeur | wnam | enam | apac | oc.
        $region = (string) $credential->organization->edge_data_region;
        [$jurisdiction, $locationHint] = $this->mapRegion($region);

        try {
            $result = $bootstrapper->bootstrap(
                $credential,
                $accountId,
                $bucket,
                $kvTitle,
                $zoneName,
                $workerScript,
                EdgeDeliveryFeaturesEnsurer::CACHE_KV_TITLE,
                $locationHint,
                $jurisdiction,
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Bootstrapped Edge infra for credential '.$credential->id);
        $this->line('R2 bucket: '.$result['bucket']);
        $this->line('KV namespace (host map): '.$result['kv_namespace_id']);
        $this->line('KV namespace (origin cache): '.$result['cache_kv_namespace_id']);

        $accessKey = trim((string) $this->option('r2-access-key'));
        $secret = trim((string) $this->option('r2-secret'));
        if ($accessKey !== '' && $secret !== '') {
            EdgeOrgCredentialConfig::merge($credential, [
                'r2_access_key' => $accessKey,
                'r2_secret' => $secret,
                'r2_endpoint' => 'https://'.$accountId.'.r2.cloudflarestorage.com',
            ]);
            $this->info('Stored R2 S3 access keys on credential.');
        } else {
            $this->newLine();
            $this->warn('Create R2 API keys in Cloudflare (R2 → Manage R2 API tokens) and re-run with:');
            $this->line('  --r2-access-key=... --r2-secret=...');
        }

        if ($zoneName === '') {
            $this->warn('Set --zone-name= so Edge sites get hostnames on your zone and worker routes are generated.');
        }

        $this->newLine();
        $this->line('Deploy worker: php artisan edge:worker:deploy --credential='.$credential->id);

        return self::SUCCESS;
    }

    /**
     * Map an organization's `edge_data_region` to the
     * (jurisdiction, locationHint) tuple R2 expects.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function mapRegion(string $region): array
    {
        return match (strtolower(trim($region))) {
            'eu', 'eu-strict' => ['eu', 'weur'],
            'wnam' => [null, 'wnam'],
            'enam' => [null, 'enam'],
            'weur' => [null, 'weur'],
            'eeur' => [null, 'eeur'],
            'apac' => [null, 'apac'],
            'oc' => [null, 'oc'],
            default => [null, null],
        };
    }
}
