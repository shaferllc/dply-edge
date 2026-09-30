<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Storage;

use App\Models\EdgeBucketKey;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * S3 keys for object storage. R2's S3 API takes a Cloudflare account token
 * scoped to buckets: Access Key ID = the token id, Secret Access Key =
 * sha256(token value) (developers.cloudflare.com/r2/api/tokens).
 *
 * Each app gets one key over its awake buckets, created or changed only when
 * that set changes (syncApp, at deploy) and sent as the standard AWS_* env
 * (appEnv), so Laravel's s3 disk, Active Storage's S3 service and the AWS
 * SDKs work unchanged. External keys cover one bucket, for aws cli, rclone
 * and the like. Keys go when their bucket, app or organization goes, and
 * are disabled while the organization is paused (they bypass the Worker).
 *
 * Needs "Account API Tokens: Edit" on the platform token. Without it every
 * call fails; a deploy then goes on without AWS_* (the dply driver still works).
 */
final class EdgeBucketKeys
{
    public const WRITE_GROUP = 'Workers R2 Storage Bucket Item Write';

    public const READ_GROUP = 'Workers R2 Storage Bucket Item Read';

    public static function endpoint(): string
    {
        return 'https://'.config('edge.cloudflare.account_id').'.r2.cloudflarestorage.com';
    }

    /**
     * The key over this app's awake buckets, brought up to date. Calls
     * Cloudflare only when the bucket set changed. Null when the app has no
     * bucket, or the key could not be made (logged; the deploy goes on).
     */
    public function syncApp(Site $site): ?EdgeBucketKey
    {
        $buckets = array_values(array_unique(array_values(self::appBuckets($site))));
        sort($buckets);
        $key = EdgeBucketKey::query()->where('site_id', $site->id)->first();
        if ($key !== null && $key->buckets === $buckets) {
            return $key;
        }

        try {
            if ($buckets === []) {
                if ($key !== null) {
                    $this->revoke($key);
                }

                return null;
            }
            $name = 'dply '.$site->id.' storage';
            if ($key !== null) {
                $this->client()->updateAccountToken($key->token_id, $name, $this->policies($buckets, 'write'), $key->status);
                $key->forceFill(['buckets' => $buckets])->save();

                return $key;
            }
            $token = $this->client()->createAccountToken($name, $this->policies($buckets, 'write'));
            $secret = hash('sha256', $token['value']);

            return EdgeBucketKey::query()->create([
                'organization_id' => $site->organization_id,
                'site_id' => $site->id,
                'label' => 'App',
                'buckets' => $buckets,
                'access' => 'write',
                'token_id' => $token['id'],
                'secret' => $secret,
                'secret_last4' => substr($secret, -4),
            ]);
        } catch (Throwable $e) {
            Log::warning('Storage S3 key: could not sync the app key', ['site_id' => $site->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * AWS_* for the deploy (EdgeContainerDeployer::secrets). All or nothing:
     * when the app saved its own AWS_ACCESS_KEY_ID none is set, so an app on
     * real S3 is never pointed at R2. Reads the database only.
     *
     * @param  array<string, string>  $saved  The app's own env.
     * @return array<string, string>
     */
    public static function appEnv(Site $site, array $saved): array
    {
        if (($saved['AWS_ACCESS_KEY_ID'] ?? '') !== '') {
            return [];
        }
        $buckets = self::appBuckets($site);
        $default = EdgeContainerConnections::storageDriverEnv($site)['DPLY_STORAGE_DISK'] ?? '';
        $bucket = $buckets[$default] ?? '';
        $key = EdgeBucketKey::query()->where('site_id', $site->id)->first();
        if ($bucket === '' || $key === null || (string) $key->secret === '' || ! in_array($bucket, $key->buckets, true)) {
            return [];
        }

        $env = [
            'AWS_ACCESS_KEY_ID' => $key->token_id,
            'AWS_SECRET_ACCESS_KEY' => (string) $key->secret,
            'AWS_DEFAULT_REGION' => 'auto',
            'AWS_REGION' => 'auto',
            'AWS_BUCKET' => $bucket,
            'AWS_ENDPOINT' => self::endpoint(),
            // Service-specific, never the global AWS_ENDPOINT_URL: that would move SES and SQS too.
            'AWS_ENDPOINT_URL_S3' => self::endpoint(),
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
            'DPLY_STORAGE_BUCKETS' => implode(',', array_map(static fn (string $name, string $b): string => $name.'='.$b, array_keys($buckets), $buckets)),
        ];
        $public = self::publicUrl($site, $default);
        if ($public !== '') {
            $env['AWS_URL'] = $public;
        }

        return $env;
    }

    /**
     * A key for one bucket, for use outside dply. The secret is returned
     * here once and never stored.
     *
     * @return array{key: EdgeBucketKey, secret: string}
     */
    public function createExternal(Organization $organization, string $bucket, string $label, string $access, ?User $by): array
    {
        $access = $access === 'read' ? 'read' : 'write';
        $token = $this->client()->createAccountToken('dply '.$organization->id.' '.$label, $this->policies([$bucket], $access));
        $secret = hash('sha256', $token['value']);
        $key = EdgeBucketKey::query()->create([
            'organization_id' => $organization->id,
            'label' => $label,
            'buckets' => [$bucket],
            'access' => $access,
            'token_id' => $token['id'],
            'secret_last4' => substr($secret, -4),
            'status' => $organization->billing_paused_at !== null ? EdgeBucketKey::STATUS_DISABLED : EdgeBucketKey::STATUS_ACTIVE,
            'created_by' => $by?->id,
        ]);
        if ($key->status === EdgeBucketKey::STATUS_DISABLED) {
            $this->client()->updateAccountToken($key->token_id, 'dply '.$organization->id.' '.$label, $this->policies([$bucket], $access), $key->status);
        }

        return ['key' => $key, 'secret' => $secret];
    }

    public function revoke(EdgeBucketKey $key): void
    {
        $this->client()->deleteAccountToken($key->token_id);
        $key->delete();
    }

    /** The bucket is being deleted: its external keys go, and app keys stop covering it. */
    public function forgetBucket(Organization $organization, string $bucket): void
    {
        foreach (EdgeBucketKey::query()->where('organization_id', $organization->id)->get() as $key) {
            if (! in_array($bucket, $key->buckets, true)) {
                continue;
            }
            $rest = array_values(array_diff($key->buckets, [$bucket]));
            if ($key->site_id === null || $rest === []) {
                $this->revoke($key);

                continue;
            }
            $this->client()->updateAccountToken($key->token_id, 'dply '.$key->site_id.' storage', $this->policies($rest, $key->access), $key->status);
            $key->forceFill(['buckets' => $rest])->save();
        }
    }

    public function forgetSite(Site $site): void
    {
        foreach (EdgeBucketKey::query()->where('site_id', $site->id)->get() as $key) {
            $this->revoke($key);
        }
    }

    public function forgetOrganization(Organization $organization): void
    {
        foreach (EdgeBucketKey::query()->where('organization_id', $organization->id)->get() as $key) {
            $this->revoke($key);
        }
    }

    /** Paused organizations: keys reach R2 directly, past the paused Worker, so they are switched off. */
    public function setOrganizationEnabled(Organization $organization, bool $enabled): void
    {
        $status = $enabled ? EdgeBucketKey::STATUS_ACTIVE : EdgeBucketKey::STATUS_DISABLED;
        foreach (EdgeBucketKey::query()->where('organization_id', $organization->id)->where('status', '!=', $status)->get() as $key) {
            $name = $key->site_id !== null ? 'dply '.$key->site_id.' storage' : 'dply '.$organization->id.' '.$key->label;
            $this->client()->updateAccountToken($key->token_id, $name, $this->policies($key->buckets, $key->access), $status);
            $key->forceFill(['status' => $status])->save();
        }
    }

    /**
     * disk name => bucket, for the app's awake buckets this organization owns.
     *
     * @return array<string, string>
     */
    public static function appBuckets(Site $site): array
    {
        $out = [];
        foreach (EdgeContainerConnections::for($site) as $connection) {
            if ($connection['kind'] === 'object_storage' && ! $connection['asleep'] && $site->organization !== null
                && EdgeContainerConnections::owns('object_storage', $connection['target'], $site->organization)) {
                $out[strtolower($connection['name'])] = $connection['target'];
            }
        }

        return $out;
    }

    /** https://{app}/{public path} for a disk served on the app's domain, or ''. */
    public static function publicUrl(Site $site, string $disk): string
    {
        $url = rtrim((string) ($site->edgePublicUrl() ?? ''), '/');
        foreach (EdgeContainerConnections::publicStorage($site) as $public) {
            if (strtolower($public['name']) === $disk && $url !== '') {
                return $url.$public['path'];
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $buckets
     * @return list<array<string, mixed>>
     */
    private function policies(array $buckets, string $access): array
    {
        $account = (string) config('edge.cloudflare.account_id');
        $resources = [];
        foreach ($buckets as $bucket) {
            // Our buckets are created without a jurisdiction: "default".
            $resources['com.cloudflare.edge.r2.bucket.'.$account.'_default_'.$bucket] = '*';
        }

        return [['effect' => 'allow', 'resources' => $resources, 'permission_groups' => [['id' => $this->permissionGroup($access === 'read' ? self::READ_GROUP : self::WRITE_GROUP)]]]];
    }

    private function permissionGroup(string $name): string
    {
        $id = Cache::remember('cf-token-group:'.$name, now()->addDay(), function () use ($name): string {
            foreach ($this->client()->accountTokenPermissionGroups() as $group) {
                if ($group['name'] === $name) {
                    return $group['id'];
                }
            }

            return '';
        });
        if ($id === '') {
            Cache::forget('cf-token-group:'.$name);
            throw new \RuntimeException('Cloudflare has no "'.$name.'" permission group.');
        }

        return $id;
    }

    private function client(): EdgeCloudflareClient
    {
        return EdgeCloudflareClient::fromConfig();
    }
}
