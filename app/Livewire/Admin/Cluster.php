<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Modules\Providers\Services\KubernetesReadClient;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Read-only view of the DOKS cluster (builders, valkey, dply databases):
 * nodes, pods with restarts / last termination / memory, recent Warning
 * events, and a pod's last log lines. Data comes from the Kubernetes API
 * through a read-only ServiceAccount (deploy/k8s-readonly/rbac.yaml).
 *
 * ponytail: every render re-reads the cluster (4 small GETs). Cache a
 * snapshot if the page gets slow or gains polling.
 */
#[Layout('layouts.admin')]
class Cluster extends Component
{
    use AuthorizesPlatformAdmin;

    /** Shown first and expanded. Every other namespace collapses unless a pod in it is in trouble. */
    public const FOCUS_NAMESPACES = ['dply-builders', 'dply-valkey', 'dply-db'];

    /** A termination this recent counts as trouble. */
    private const RECENT_SECONDS = 3600;

    private const NAME = '/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/';

    public ?string $logNamespace = null;

    public ?string $logPod = null;

    public ?string $logContainer = null;

    public bool $logPrevious = false;

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
    }

    public function showLogs(string $namespace, string $pod, string $container): void
    {
        $this->authorizePlatformAdmin();
        foreach ([$namespace, $pod, $container] as $name) {
            abort_unless(preg_match(self::NAME, $name) === 1, 422);
        }
        [$this->logNamespace, $this->logPod, $this->logContainer, $this->logPrevious] = [$namespace, $pod, $container, false];
    }

    public function closeLogs(): void
    {
        $this->reset('logNamespace', 'logPod', 'logContainer', 'logPrevious');
    }

    public function render(): View
    {
        $this->authorizePlatformAdmin();
        $client = KubernetesReadClient::fromConfig();
        $data = ['configured' => $client !== null, 'error' => null, 'nodes' => [], 'groups' => [], 'events' => [], 'metrics' => false, 'logs' => null, 'logError' => null];
        if ($client === null) {
            return view('livewire.admin.cluster', $data);
        }

        try {
            $metrics = $client->podMetrics();
            $data['metrics'] = $metrics !== [];
            $data['nodes'] = array_map($this->nodeRow(...), $client->nodes());
            $data['groups'] = $this->groups($client->pods(), $this->metricsByPod($metrics));
            $data['events'] = $this->events($client->warningEvents());
        } catch (Throwable $e) {
            $data['error'] = $e->getMessage();
        }

        if ($this->logPod !== null) {
            try {
                $data['logs'] = $client->logs((string) $this->logNamespace, $this->logPod, (string) $this->logContainer, $this->logPrevious);
            } catch (Throwable $e) {
                $data['logError'] = $e->getMessage();
            }
        }

        return view('livewire.admin.cluster', $data);
    }

    /** "835Mi", "2Gi", "12m", "123456n" → a plain number (bytes or cores). */
    public static function quantity(string $value): float
    {
        if (preg_match('/^([0-9.]+)([a-zA-Z]*)$/', trim($value), $m) !== 1) {
            return 0.0;
        }
        $scale = ['n' => 1e-9, 'u' => 1e-6, 'm' => 1e-3, '' => 1, 'k' => 1e3, 'M' => 1e6, 'G' => 1e9, 'T' => 1e12,
            'Ki' => 1024, 'Mi' => 1024 ** 2, 'Gi' => 1024 ** 3, 'Ti' => 1024 ** 4][$m[2]] ?? 1;

        return (float) $m[1] * $scale;
    }

    public static function bytes(?float $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1).'Gi' : round($bytes / 1024 ** 2).'Mi';
    }

    /** @return array<string, mixed> */
    private function nodeRow(array $node): array
    {
        $ready = collect($node['status']['conditions'] ?? [])->firstWhere('type', 'Ready');
        $labels = $node['metadata']['labels'] ?? [];

        return [
            'name' => $node['metadata']['name'] ?? '?',
            'pool' => $labels['doks.digitalocean.com/node-pool'] ?? '',
            'size' => $labels['node.kubernetes.io/instance-type'] ?? '',
            'ready' => ($ready['status'] ?? '') === 'True',
            'age' => $this->age($node['metadata']['creationTimestamp'] ?? null),
            'memory' => self::bytes(isset($node['status']['allocatable']['memory']) ? self::quantity($node['status']['allocatable']['memory']) : null),
            'cpu' => $node['status']['allocatable']['cpu'] ?? '',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $metrics
     * @return array<string, array<string, array{cpu: float, memory: float}>> "ns/pod" → container → usage
     */
    private function metricsByPod(array $metrics): array
    {
        $out = [];
        foreach ($metrics as $m) {
            $key = ($m['metadata']['namespace'] ?? '').'/'.($m['metadata']['name'] ?? '');
            foreach ($m['containers'] ?? [] as $c) {
                $out[$key][$c['name']] = ['cpu' => self::quantity($c['usage']['cpu'] ?? '0'), 'memory' => self::quantity($c['usage']['memory'] ?? '0')];
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $pods
     * @return list<array<string, mixed>>
     */
    private function groups(array $pods, array $usage): array
    {
        $byNamespace = [];
        foreach ($pods as $pod) {
            $row = $this->podRow($pod, $usage);
            $byNamespace[$row['namespace']][] = $row;
        }
        $others = array_diff(array_keys($byNamespace), self::FOCUS_NAMESPACES);
        sort($others);

        $groups = [];
        foreach ([...array_intersect(self::FOCUS_NAMESPACES, array_keys($byNamespace)), ...$others] as $ns) {
            $rows = $byNamespace[$ns];
            usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));
            $trouble = collect($rows)->contains('trouble', true);
            $groups[] = [
                'namespace' => $ns,
                'pods' => $rows,
                'open' => in_array($ns, self::FOCUS_NAMESPACES, true) || $trouble,
                'trouble' => $trouble,
                'restarts' => array_sum(array_column($rows, 'restarts')),
                'notReady' => count(array_filter($rows, fn ($r) => ! $r['ready'])),
            ];
        }

        return $groups;
    }

    /** @return array<string, mixed> */
    private function podRow(array $pod, array $usage): array
    {
        $ns = $pod['metadata']['namespace'] ?? '';
        $name = $pod['metadata']['name'] ?? '';
        $specs = $pod['spec']['containers'] ?? [];
        $statuses = collect($pod['status']['containerStatuses'] ?? []);
        $phase = $pod['status']['phase'] ?? 'Unknown';

        $status = isset($pod['metadata']['deletionTimestamp']) ? 'Terminating'
            : ($statuses->map(fn ($s) => $s['state']['waiting']['reason'] ?? $s['state']['terminated']['reason'] ?? null)->filter()->first() ?? $phase);

        $last = $statuses
            ->map(fn ($s) => ($t = $s['state']['terminated'] ?? $s['lastState']['terminated'] ?? null) ? ['container' => $s['name'], 'reason' => $t['reason'] ?? 'Terminated', 'exitCode' => $t['exitCode'] ?? null, 'at' => $t['finishedAt'] ?? null] : null)
            ->filter()
            ->sortByDesc('at')
            ->first();

        $readyCount = $statuses->where('ready', true)->count();
        $ready = $phase === 'Succeeded' || $readyCount === count($specs);
        $recent = $last !== null && $last['at'] !== null && Carbon::parse($last['at'])->diffInSeconds(now(), true) <= self::RECENT_SECONDS;

        $containers = array_map(function (array $c) use ($ns, $name, $usage): array {
            $limit = $c['resources']['limits']['memory'] ?? null;

            return [
                'name' => $c['name'],
                'memory' => $usage[$ns.'/'.$name][$c['name']]['memory'] ?? null,
                'limit' => $limit !== null ? self::quantity($limit) : null,
                'cpu' => $usage[$ns.'/'.$name][$c['name']]['cpu'] ?? null,
            ];
        }, $specs);

        $image = (string) ($specs[0]['image'] ?? '');

        return [
            'namespace' => $ns,
            'name' => $name,
            'status' => $status,
            'ready' => $ready,
            'readyText' => $readyCount.'/'.count($specs),
            'restarts' => (int) $statuses->sum('restartCount'),
            'last' => $last === null ? null : $last + ['ago' => $this->age($last['at']), 'recent' => $recent],
            'node' => $pod['spec']['nodeName'] ?? '',
            'age' => $this->age($pod['metadata']['creationTimestamp'] ?? null),
            'tag' => str_contains($image, '@') ? substr(strrchr($image, '@'), 1, 19) : (str_contains(basename($image), ':') ? substr(strrchr($image, ':'), 1) : 'latest'),
            'image' => $image,
            'containers' => $containers,
            'trouble' => ! $ready || $recent,
        ];
    }

    /**
     * Warning events from the last hour, newest first.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function events(array $events): array
    {
        return collect($events)
            ->map(fn (array $e) => [
                'at' => $e['lastTimestamp'] ?? $e['eventTime'] ?? $e['metadata']['creationTimestamp'] ?? null,
                'namespace' => $e['involvedObject']['namespace'] ?? $e['metadata']['namespace'] ?? '',
                'object' => ($e['involvedObject']['kind'] ?? '').'/'.($e['involvedObject']['name'] ?? ''),
                'reason' => $e['reason'] ?? '',
                'message' => $e['message'] ?? '',
                'count' => (int) ($e['count'] ?? 1),
            ])
            ->filter(fn ($e) => $e['at'] !== null && Carbon::parse($e['at'])->diffInSeconds(now(), true) <= self::RECENT_SECONDS)
            ->sortByDesc('at')
            ->take(50)
            ->map(fn ($e) => $e + ['ago' => $this->age($e['at'])])
            ->values()
            ->all();
    }

    private function age(?string $timestamp): string
    {
        return $timestamp === null ? '—' : Carbon::parse($timestamp)->shortAbsoluteDiffForHumans();
    }
}
