<?php

declare(strict_types=1);

use App\Support\AuditActionMeta;

/*
| AuditActionMeta resolves an audit action string into the { family, label,
| icon, tone } quad the activity feed renders. Its four-step resolution order
| (exact override → prefix pattern → family default → fallback) is what these
| tests pin down, plus the family map itself, which several activity views
| filter against by id.
*/

test('exact overrides win and carry their own icon and tone', function () {
    expect(AuditActionMeta::meta('server.created'))->toBe([
        'label' => 'Server created',
        'icon' => 'heroicon-o-plus-circle',
        'tone' => 'success',
        'family' => 'other',
    ]);

    expect(AuditActionMeta::meta('server.deleted'))->toBe([
        'label' => 'Server deleted',
        'icon' => 'heroicon-o-trash',
        'tone' => 'danger',
        'family' => 'other',
    ]);
});

test('an exact override is preferred over the prefix pattern that also matches', function () {
    // `script.created` matches both the exact map and the `script.` prefix
    // pattern. Step 1 must win, otherwise the label degrades to "Script created"
    // built by the prefix branch and the tone drops to neutral.
    expect(AuditActionMeta::meta('script.created'))->toMatchArray([
        'label' => 'Script created',
        'icon' => 'heroicon-o-command-line',
        'tone' => 'success',
    ]);

    // `script.run` has no exact entry, so it falls through to the prefix branch.
    expect(AuditActionMeta::meta('script.run'))->toMatchArray([
        'label' => 'Script run',
        'icon' => 'heroicon-o-command-line',
    ]);
});

test('prefix patterns build a label from the family label plus the humanized tail', function () {
    expect(AuditActionMeta::meta('server.firewall.rule_created'))->toMatchArray([
        'label' => 'Firewall rule created',
        'icon' => 'heroicon-o-fire',
        'family' => 'other',
    ]);

    expect(AuditActionMeta::meta('server.ssh_keys.key_added'))->toMatchArray([
        'label' => 'SSH key key added',
        'icon' => 'heroicon-o-key',
    ]);

    expect(AuditActionMeta::meta('server.caches.cache_service_installed'))->toMatchArray([
        'label' => 'Cache engine cache service installed',
        'icon' => 'heroicon-o-circle-stack',
    ]);

    expect(AuditActionMeta::meta('server.databases.engine_installed'))->toMatchArray([
        'label' => 'Database engine engine installed',
        'icon' => 'heroicon-o-circle-stack',
    ]);
});

test('more specific prefixes are matched before the general one', function () {
    // `server.service.bulk_` is registered ahead of `server.service.`; order in
    // the pattern list is load-bearing and a reorder would silently relabel
    // every bulk service action.
    expect(AuditActionMeta::meta('server.service.bulk_restart'))->toMatchArray([
        'label' => 'Service (bulk) restart',
        'icon' => 'heroicon-o-bolt',
    ]);

    expect(AuditActionMeta::meta('server.service.restart'))->toMatchArray([
        'label' => 'Service restart',
        'icon' => 'heroicon-o-cog-6-tooth',
    ]);
});

test('queue worker actions from the VM era resolve to other', function () {
    expect(AuditActionMeta::meta('queue_worker.pool_created'))->toMatchArray([
        'label' => 'Queue worker pool created',
        'icon' => 'heroicon-o-queue-list',
        'family' => 'other',
    ]);
});

test('unknown actions fall back to a humanized label and the family icon', function () {
    expect(AuditActionMeta::meta('site.env.var_renamed'))->toBe([
        'family' => 'site',
        'label' => 'Site env var renamed',
        'icon' => 'heroicon-o-globe-alt',
        'tone' => 'neutral',
    ]);

    // Outside every known family: the `other` bucket and its icon.
    expect(AuditActionMeta::meta('something.entirely.new'))->toBe([
        'family' => 'other',
        'label' => 'Something entirely new',
        'icon' => 'heroicon-o-ellipsis-horizontal-circle',
        'tone' => 'neutral',
    ]);
});

test('family resolution prefers the more specific prefix', function () {
    // `site.edge.*` must resolve to `edge`, not `site` — the match arms are
    // ordered, and swapping them would fold the whole Edge feed into Sites.
    expect(AuditActionMeta::family('site.edge.created'))->toBe('edge')
        ->and(AuditActionMeta::family('site.deploy.success'))->toBe('site')
        ->and(AuditActionMeta::family('database.created'))->toBe('resources')
        ->and(AuditActionMeta::family('queue.deleted'))->toBe('resources')
        ->and(AuditActionMeta::family('team.created'))->toBe('team')
        ->and(AuditActionMeta::family('billing.portal_accessed'))->toBe('billing')
        ->and(AuditActionMeta::family('organization.created'))->toBe('org')
        ->and(AuditActionMeta::family('script.created'))->toBe('other')
        ->and(AuditActionMeta::family('nonsense'))->toBe('other');
});

test('every security-family prefix maps to security', function () {
    foreach ([
        'api_token.created',
        'invitation.sent',
        'notification_channel.created',
        'user.password_changed',
        'credential.created',
        'impersonation.started',
    ] as $action) {
        expect(AuditActionMeta::family($action))->toBe('security');
    }
});

test('actions from the removed VM products land in other', function () {
    foreach (['server.created', 'project.deploy.queued', 'backup.schedule.created', 'insight.ignored', 'import.migration.started', 'marketplace.server_recipe_imported', 'queue_worker.restarted'] as $action) {
        expect(AuditActionMeta::family($action))->toBe('other');
    }
    expect(array_column(AuditActionMeta::FAMILIES, 'id'))->not->toContain('server', 'project', 'backup', 'insight', 'import', 'background');
});

test('every family declares an id label and icon, and every resolved family exists', function () {
    $ids = [];

    foreach (AuditActionMeta::FAMILIES as $family) {
        expect($family)->toHaveKeys(['id', 'label', 'icon'])
            ->and($family['id'])->not->toBe('')
            ->and($family['label'])->not->toBe('')
            ->and($family['icon'])->toStartWith('heroicon-');

        $ids[] = $family['id'];
    }

    expect($ids)->toBe(array_unique($ids));

    // Nothing may resolve to a family the map does not declare, or the activity
    // view renders a tab with no icon.
    foreach ([
        'server.created', 'site.deploy.success', 'site.edge.created',
        'project.deploy.queued', 'team.created', 'billing.portal_accessed',
        'user.password_changed', 'organization.created', 'backup.schedule.created',
        'insight.ignored', 'import.migration.started', 'queue_worker.restarted',
        'totally.unknown',
    ] as $action) {
        expect($ids)->toContain(AuditActionMeta::meta($action)['family']);
    }
});

test('every exact override returns a complete non-empty quad', function () {
    // Guards against a typo'd entry (missing tone, empty icon) silently
    // rendering a blank chip in the feed.
    foreach ([
        'server.created', 'server.deleted', 'site.suspended', 'site.ssl.issued',
        'site.edge.preview.promoted', 'project.member_removed', 'team.member_added',
        'api_token.revoked', 'credential.verify_failed', 'organization.created',
        'billing.subscription_canceled', 'script.deleted',
        'marketplace.workspace_runbook_imported', 'backup.database.deleted',
        'insight.fix_reverted', 'import.migration.cutover_rolled_back',
    ] as $action) {
        $meta = AuditActionMeta::meta($action);

        expect($meta)->toHaveKeys(['family', 'label', 'icon', 'tone'])
            ->and($meta['label'])->not->toBe('')
            ->and($meta['icon'])->toStartWith('heroicon-')
            ->and($meta['tone'])->toBeIn(['success', 'danger', 'warning', 'info', 'neutral']);
    }
});

test('prefix-matched actions currently always resolve to the info tone', function () {
    /*
     * Documents a live defect rather than endorsing it.
     *
     * prefixMatch()'s $verbTone closure tests the *full* action against
     * '.deleted' / '.created' / '.enabled' / … — dot-prefixed. But every action
     * that reaches prefixMatch is built by concatenating a snake_case event
     * onto a dotted prefix ('server.firewall.'.$event, where $event is
     * ServerFirewallAuditEvent::EVENT_RULE_DELETED = 'rule_deleted'), so the
     * tail separator is an underscore and none of those arms can ever match.
     *
     * Net effect: firewall / ssh-key / cache / database / service / queue-worker
     * events all render neutral 'info' in the activity feed — a deletion never
     * shows danger. Fixing it means matching the tail on '_' as well as '.';
     * when that lands, this test should flip to asserting the real tones.
     */
    foreach ([
        'server.firewall.rule_deleted',
        'server.ssh_keys.key_removed',
        'server.caches.cache_service_uninstalled',
        'server.databases.engine_created',
        'server.service.disable',
        'queue_worker.pool_deleted',
    ] as $action) {
        expect(AuditActionMeta::meta($action)['tone'])->toBe('info');
    }
});
