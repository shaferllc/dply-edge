<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use RuntimeException;

/**
 * Creates (or adopts) the resource behind a dashboard-declared Edge binding
 * (Jobs page "Add binding").
 *
 * dply's Cloudflare account is shared by every organization, so the typed
 * label is a name inside the organization's prefix ("cache" is
 * `{prefix}cache`): an existing one of ours is adopted, so clicking create
 * twice gets the same binding, and a new one is made by
 * {@see EdgeContainerConnections::provision}, which applies plan limits and
 * the card rule and records D1 / queue ownership.
 *
 * Returns the *identifier* the Worker upload needs for that binding kind:
 *   kv -> namespace id, r2 -> bucket name, d1 -> database id, queue -> queue name
 */
class EdgeDashboardBindingProvisioner
{
    /**
     * @param  string  $kind  one of EdgeEffectiveBindings::KINDS
     * @param  string  $label  the resource name, inside the organization's prefix
     * @return string the identifier to store as the binding value
     */
    public function create(Site $site, string $kind, string $label): string
    {
        $connection = array_search($kind, EdgeEffectiveBindings::KIND_FOR_CONNECTION, true);
        if ($connection === false) {
            throw new RuntimeException("Unknown binding kind: {$kind}");
        }
        if ($site->organization === null) {
            throw new RuntimeException('This app has no organization to own the resource.');
        }

        return EdgeContainerConnections::ensure($connection, $label, $site->organization);
    }
}
