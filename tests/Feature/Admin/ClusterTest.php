<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\ClusterTest;

use App\Livewire\Admin\Cluster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function k8sPod(string $ns, string $name, array $statuses, array $containers, string $created = '-2 days'): array
{
    return [
        'metadata' => ['namespace' => $ns, 'name' => $name, 'creationTimestamp' => now()->modify($created)->toIso8601String()],
        'spec' => ['nodeName' => 'pool-c4-abc', 'containers' => $containers],
        'status' => ['phase' => 'Running', 'containerStatuses' => $statuses],
    ];
}

beforeEach(function () {
    config()->set('dply.kubernetes', ['api_url' => 'https://k8s.test', 'token' => 'tok', 'ca' => null]);

    Http::fake([
        'k8s.test/api/v1/nodes' => Http::response(['items' => [[
            'metadata' => ['name' => 'pool-c4-abc', 'creationTimestamp' => now()->subDay()->toIso8601String(), 'labels' => ['node.kubernetes.io/instance-type' => 'c-4']],
            'status' => ['conditions' => [['type' => 'Ready', 'status' => 'True']], 'allocatable' => ['cpu' => '4', 'memory' => '7Gi']],
        ]]]),
        'k8s.test/api/v1/pods' => Http::response(['items' => [
            k8sPod('dply-builders', 'dply-builder-0',
                [
                    ['name' => 'builder', 'ready' => true, 'restartCount' => 2, 'state' => ['running' => []],
                        'lastState' => ['terminated' => ['reason' => 'OOMKilled', 'exitCode' => 137, 'finishedAt' => now()->subMinutes(10)->toIso8601String()]]],
                    ['name' => 'dind', 'ready' => true, 'restartCount' => 0, 'state' => ['running' => []]],
                ],
                [
                    ['name' => 'builder', 'image' => 'registry.digitalocean.com/dply-cloud/dply-builder:abc123', 'resources' => ['limits' => ['memory' => '2Gi']]],
                    ['name' => 'dind', 'image' => 'docker:dind'],
                ]),
            k8sPod('kube-system', 'coredns-1',
                [['name' => 'coredns', 'ready' => true, 'restartCount' => 0, 'state' => ['running' => []]]],
                [['name' => 'coredns', 'image' => 'coredns:1.11']]),
        ]]),
        'k8s.test/api/v1/events*' => Http::response(['items' => [
            ['involvedObject' => ['kind' => 'Pod', 'name' => 'dply-builder-0', 'namespace' => 'dply-builders'], 'reason' => 'BackOff', 'message' => 'Back-off restarting failed container', 'count' => 3, 'lastTimestamp' => now()->subMinutes(5)->toIso8601String()],
            ['involvedObject' => ['kind' => 'Pod', 'name' => 'old', 'namespace' => 'dply-db'], 'reason' => 'Ancient', 'message' => 'too old', 'lastTimestamp' => now()->subHours(3)->toIso8601String()],
        ]]),
        'k8s.test/apis/metrics.k8s.io/v1beta1/pods' => Http::response(['items' => [[
            'metadata' => ['namespace' => 'dply-builders', 'name' => 'dply-builder-0'],
            'containers' => [['name' => 'builder', 'usage' => ['cpu' => '12000000n', 'memory' => '1843Mi']], ['name' => 'dind', 'usage' => ['cpu' => '1m', 'memory' => '100Mi']]],
        ]]]),
        'k8s.test/api/v1/namespaces/dply-builders/pods/dply-builder-0/log*' => Http::response("line one\nline two"),
    ]);
});

test('guest cannot open the cluster page', function () {
    $this->get(route('admin.cluster'))->assertRedirect(route('login', absolute: false));
});

test('shows nodes, pods with OOM and memory, collapses system namespaces, and recent warnings only', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(Cluster::class)
        ->assertSee('pool-c4-abc')
        ->assertSee('c-4')
        ->assertSee('dply-builder-0')
        ->assertSee('OOMKilled')
        ->assertSee('exit 137')
        ->assertSee('1.8Gi / 2Gi')
        ->assertSee('100Mi / no limit')
        ->assertSee('abc123')
        ->assertSee('Back-off restarting failed container')
        ->assertDontSee('too old');

    $groups = collect($component->viewData('groups'))->keyBy('namespace');
    expect($groups->keys()->all())->toBe(['dply-builders', 'kube-system'])
        ->and($groups['dply-builders']['open'])->toBeTrue()
        ->and($groups['dply-builders']['trouble'])->toBeTrue()
        ->and($groups['kube-system']['open'])->toBeFalse();
});

test('shows a container\'s logs and rejects bad names', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Cluster::class)
        ->call('showLogs', 'dply-builders', 'dply-builder-0', 'builder')
        ->assertSee('line two')
        ->call('showLogs', '../etc', 'x', 'y')
        ->assertStatus(422);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/log') && str_contains($r->url(), 'container=builder') && str_contains($r->url(), 'tailLines=200'));
});

test('says not configured without credentials', function () {
    config()->set('dply.kubernetes', ['api_url' => null, 'token' => null, 'ca' => null]);
    $this->actingAs(User::factory()->create());

    Livewire::test(Cluster::class)->assertSee('Not configured');
    Http::assertNothingSent();
});

test('quantity parses kubernetes units', function () {
    expect(Cluster::quantity('2Gi'))->toBe(2.0 * 1024 ** 3)
        ->and(Cluster::quantity('12m'))->toBe(0.012)
        ->and(Cluster::quantity('123456Ki'))->toBe(123456.0 * 1024)
        ->and(Cluster::bytes(835.0 * 1024 ** 2))->toBe('835Mi');
});
