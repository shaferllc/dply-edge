<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeCachePurger;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Cache extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public string $purgePath = '';

    public string $purgeTag = '';

    public string $mode = 'assets';

    public string $edgeTtl = '86400';

    public string $browserTtl = '86400';

    public string $queryString = 'ignore';

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $cfg = is_array($site->edgeMeta()['cache'] ?? null) ? $site->edgeMeta()['cache'] : [];
        $mode = (string) ($cfg['mode'] ?? 'assets');
        $this->mode = in_array($mode, ['off', 'assets', 'standard', 'everything'], true) ? $mode : 'assets';
        $edge = (int) ($cfg['edge_ttl_seconds'] ?? 86400);
        $browser = (int) ($cfg['browser_ttl_seconds'] ?? 86400);
        $this->edgeTtl = in_array($edge, [60, 300, 3600, 14400, 86400, 604800, 2592000, 31536000], true)
            ? (string) $edge
            : '86400';
        $this->browserTtl = in_array($browser, [0, 300, 3600, 86400, 604800, 2592000, 31536000], true)
            ? (string) $browser
            : '86400';
        $this->queryString = ($cfg['query_string'] ?? 'ignore') === 'include' ? 'include' : 'ignore';
    }

    public function saveOptions(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'mode' => ['required', 'in:off,assets,standard,everything'],
            'edgeTtl' => ['required', 'integer', 'in:60,300,3600,14400,86400,604800,2592000,31536000'],
            'browserTtl' => ['required', 'integer', 'in:0,300,3600,86400,604800,2592000,31536000'],
            'queryString' => ['required', 'in:ignore,include'],
        ]);

        $this->site->mergeEdgeMeta([
            'cache' => [
                'mode' => $this->mode,
                'edge_ttl_seconds' => (int) $this->edgeTtl,
                'browser_ttl_seconds' => (int) $this->browserTtl,
                'query_string' => $this->queryString,
            ],
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Cache options saved.'));
    }

    public function purgeByPath(EdgeCachePurger $purger): void
    {
        $this->authorize('update', $this->site);

        $path = trim($this->purgePath);
        if ($path === '') {
            $this->addError('purgePath', __('Enter a path to purge.'));

            return;
        }

        $result = $purger->purgeByPaths($this->site->fresh(), [$path]);
        $this->finishPurge($result, 'site.edge.cache.purged_by_path', ['path' => $path]);
        if ($result['ok']) {
            $this->purgePath = '';
        }
    }

    public function purgeStored(string $path, EdgeCachePurger $purger): void
    {
        $this->authorize('update', $this->site);
        $result = $purger->purgeByPaths($this->site->fresh(), [$path]);
        $this->finishPurge($result, 'site.edge.cache.purged_by_path', ['path' => $path]);
    }

    public function clearAll(EdgeCachePurger $purger): void
    {
        $this->authorize('update', $this->site);
        $result = $purger->purgeAll($this->site->fresh());
        $this->finishPurge($result, 'site.edge.cache.purged_all', []);
    }

    public function purgeByTag(EdgeCachePurger $purger): void
    {
        $this->authorize('update', $this->site);

        $tag = trim($this->purgeTag);
        if ($tag === '') {
            $this->addError('purgeTag', __('Enter a tag to purge.'));

            return;
        }

        $result = $purger->purgeByTag($this->site->fresh(), $tag);
        $this->finishPurge($result, 'site.edge.cache.purged_by_tag', ['tag' => $tag]);
        if ($result['ok']) {
            $this->purgeTag = '';
        }
    }

    /**
     * @param  array{ok: bool, purged_keys: list<string>, message: string}  $result
     * @param  array<string, mixed>  $audit
     */
    private function finishPurge(array $result, string $action, array $audit): void
    {
        $org = $this->site->organization;
        if ($result['ok'] && $org !== null && $result['purged_keys'] !== []) {
            audit_log($org, auth()->user(), $action, $this->site, null, [
                ...$audit,
                'purged_keys' => $result['purged_keys'],
            ]);
        }

        if ($result['ok']) {
            $this->toastSuccess($result['message']);

            return;
        }

        $this->toastError($result['message']);
    }

    public function render(): View
    {
        $listed = app(EdgeCachePurger::class)->listEntries($this->site);

        return view('livewire.sites.edge.workspace.cache', [
            'server' => $this->server,
            'site' => $this->site,
            'entries' => $listed['entries'],
            'listMessage' => $listed['ok'] ? '' : $listed['message'],
        ]);
    }
}
