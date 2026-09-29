<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class RateLimits extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    /** @var list<array{path: string, limit: int, window_seconds: int, action: string}> */
    public array $rules = [];

    /** Rule open in the modal: its index, -1 for a new one, null when closed. */
    public ?int $editingRule = null;

    public string $rule_path = '/api/*';

    /** int once validated; a string while the box is being typed in. */
    public int|string $rule_limit = 60;

    public int|string $rule_window = 60;

    public string $rule_action = 'block';

    /** Starters for common protections: path, limit, window seconds, action. */
    public const PRESETS = [
        'login' => ['/login', 5, 60, 'challenge'],
        'api' => ['/api/*', 60, 60, 'block'],
        'forms' => ['/contact', 10, 60, 'block'],
        'site' => ['/*', 600, 60, 'block'],
    ];

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->loadFromSite();
    }

    /** Saved config → component state; drops unsaved edits. */
    private function loadFromSite(): void
    {
        $cfg = is_array($this->site->edgeMeta()['rate_limit'] ?? null) ? $this->site->edgeMeta()['rate_limit'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->rules = array_values(array_map(fn ($r) => [
            'path' => (string) ($r['path'] ?? '/*'),
            'limit' => (int) ($r['limit'] ?? 60),
            'window_seconds' => (int) ($r['window_seconds'] ?? 60),
            'action' => in_array(($r['action'] ?? 'block'), ['block', 'challenge'], true) ? (string) $r['action'] : 'block',
        ], is_array($cfg['rules'] ?? null) ? $cfg['rules'] : []));
    }

    public function newRule(): void
    {
        $this->authorize('update', $this->site);
        $this->loadFromSite();
        $this->resetErrorBag();
        $this->usePreset('api');
        $this->editingRule = -1;
        $this->dispatch('open-modal', 'edge-rate-rule');
    }

    public function editRule(int $index): void
    {
        $this->authorize('update', $this->site);
        $this->loadFromSite();
        abort_unless(isset($this->rules[$index]), 404);
        $this->resetErrorBag();
        $rule = $this->rules[$index];
        $this->rule_path = $rule['path'];
        $this->rule_limit = $rule['limit'];
        $this->rule_window = $rule['window_seconds'];
        $this->rule_action = $rule['action'];
        $this->editingRule = $index;
        $this->dispatch('open-modal', 'edge-rate-rule');
    }

    public function usePreset(string $key): void
    {
        $preset = self::PRESETS[$key] ?? null;
        if ($preset === null) {
            return;
        }
        [$this->rule_path, $this->rule_limit, $this->rule_window, $this->rule_action] = $preset;
    }

    public function closeRule(): void
    {
        $this->loadFromSite();
        $this->editingRule = null;
        $this->dispatch('close-modal', 'edge-rate-rule');
    }

    public function saveRule(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'rule_path' => ['required', 'string', 'max:255', 'regex:#^(\*|/.*)$#'],
            'rule_limit' => ['required', 'integer', 'min:1', 'max:10000'],
            'rule_window' => ['required', 'integer', 'min:1', 'max:3600'],
            'rule_action' => ['required', 'in:block,challenge'],
        ], ['rule_path.regex' => __('Start the path with / (or use * for everything).')]);

        $this->loadFromSite();
        $rule = ['path' => trim($this->rule_path), 'limit' => (int) $this->rule_limit, 'window_seconds' => (int) $this->rule_window, 'action' => $this->rule_action];
        if ($this->editingRule !== null && isset($this->rules[$this->editingRule])) {
            $this->rules[$this->editingRule] = $rule;
        } else {
            $this->rules[] = $rule;
            // A first rule should do something: turn rate limits on with it.
            if (count($this->rules) === 1) {
                $this->enabled = true;
            }
        }

        if ($this->save()) {
            $this->editingRule = null;
            $this->dispatch('close-modal', 'edge-rate-rule');
        }
    }

    public function removeEditingRule(): void
    {
        $this->authorize('update', $this->site);
        $this->loadFromSite();
        if ($this->editingRule !== null && isset($this->rules[$this->editingRule])) {
            unset($this->rules[$this->editingRule]);
            $this->rules = array_values($this->rules);
            $this->save();
        }
        $this->editingRule = null;
        $this->dispatch('close-modal', 'edge-rate-rule');
    }

    public function updatedEnabled(): void
    {
        $this->save();
    }

    public function save(): bool
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Rate limits require Dply-hosted Edge delivery.'));

            return false;
        }

        $this->site->mergeEdgeMeta([
            'rate_limit' => [
                'enabled' => $this->enabled,
                'rules' => $this->rules,
            ],
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Rate limits saved.'));

        return true;
    }

    /** "a minute", "every 30 seconds"… for a window length. */
    public static function per(int $seconds): string
    {
        return match ($seconds) {
            1 => __('a second'),
            60 => __('a minute'),
            3600 => __('an hour'),
            86400 => __('a day'),
            default => $seconds % 60 === 0 && $seconds < 3600
                ? __('every :n minutes', ['n' => $seconds / 60])
                : __('every :n seconds', ['n' => $seconds]),
        };
    }

    /** "about one request a second" / "about one every 12 seconds". */
    public static function pace(int $limit, int $seconds): string
    {
        $perSecond = $limit / max(1, $seconds);

        return $perSecond >= 1
            ? __('about :n requests a second', ['n' => rtrim(rtrim(number_format($perSecond, 1), '0'), '.')])
            : __('about one request every :n seconds', ['n' => (int) round(1 / $perSecond)]);
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.rate-limits', array_merge(
            EdgeSiteViewData::context($this->site, 'rate-limits'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
                'botProtectionReady' => (bool) ($this->site->edgeMeta()['turnstile']['enabled'] ?? false)
                    && trim((string) ($this->site->edgeMeta()['turnstile']['site_key'] ?? '')) !== ''
                    && trim((string) ($this->site->edgeMeta()['turnstile']['secret_key'] ?? '')) !== '',
            ],
        ));
    }
}
