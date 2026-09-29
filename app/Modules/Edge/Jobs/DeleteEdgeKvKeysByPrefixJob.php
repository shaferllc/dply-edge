<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Deletes every key under a prefix in one key-value store, a listing page
 * (1000 keys) at a time. Progress sits in the cache for the store's sheet.
 *
 * Each page is two REST calls on the platform account, whose ~1200 calls per
 * 5 minutes every organization shares. So pages are spaced out and one run
 * handles PAGES_PER_RUN of them, then dispatches the next run with the
 * cursor. Safe to re-run: deleting a deleted key is a no-op.
 */
final class DeleteEdgeKvKeysByPrefixJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** ponytail: fixed pacing (~60 REST calls a minute); share a token bucket with other callers if the account limit bites. */
    public const PAGES_PER_RUN = 25;

    public const PAUSE_SECONDS = 2;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public string $namespaceId,
        public string $prefix,
        public ?string $cursor = null,
        public int $removed = 0,
    ) {}

    /** @return array{prefix: string, removed: int, done: bool, failed: ?string}|null */
    public static function progress(string $namespaceId): ?array
    {
        $progress = Cache::get(self::progressKey($namespaceId));

        return is_array($progress) ? $progress : null;
    }

    public static function start(string $namespaceId, string $prefix): void
    {
        self::record($namespaceId, $prefix, 0, false);
        self::dispatch($namespaceId, $prefix);
    }

    public function handle(): void
    {
        if ($this->prefix === '') {
            return; // never "every key": deleting the store is the way to empty it
        }

        $client = EdgeCloudflareClient::fromConfig();
        for ($page = 0; $page < self::PAGES_PER_RUN; $page++) {
            if ($page > 0) {
                Sleep::for(self::PAUSE_SECONDS)->seconds();
            }
            $listed = $client->listKvKeysPage($this->namespaceId, $this->cursor, $this->prefix, 1000);
            $names = array_column($listed['keys'], 'name');
            if ($names !== []) {
                $client->bulkDeleteKvKeys($this->namespaceId, $names);
                $this->removed += count($names);
            }
            $this->cursor = $listed['cursor'];
            self::record($this->namespaceId, $this->prefix, $this->removed, $this->cursor === null);
            if ($this->cursor === null) {
                return;
            }
        }

        self::dispatch($this->namespaceId, $this->prefix, $this->cursor, $this->removed);
    }

    public function failed(?Throwable $e): void
    {
        self::record($this->namespaceId, $this->prefix, $this->removed, true, __('The store stopped answering. Try again.'));
    }

    private static function record(string $namespaceId, string $prefix, int $removed, bool $done, ?string $failed = null): void
    {
        Cache::put(self::progressKey($namespaceId), ['prefix' => $prefix, 'removed' => $removed, 'done' => $done, 'failed' => $failed], now()->addDay());
    }

    private static function progressKey(string $namespaceId): string
    {
        return 'edge-kv-prefix-delete:'.$namespaceId;
    }
}
