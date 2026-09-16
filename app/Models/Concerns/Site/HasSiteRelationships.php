<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Models\EdgeDeployment;
use App\Models\EdgeSiteAccessRule;
use App\Models\EdgeSiteEnvVar;
use App\Models\EdgeSiteMember;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\OrganizationSecret;
use App\Models\Project;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAccessGate;
use App\Models\SiteAccessGatePassword;
use App\Models\SiteBasicAuthUser;
use App\Models\SiteBinding;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Models\SitePreviewDomain;
use App\Models\SiteRedirect;
use App\Models\SiteSecretResidency;
use App\Models\SiteUptimeMonitor;
use App\Models\User;
use App\Models\WebhookDeliveryLog;
use App\Models\Workspace;
use App\Services\Sites\SecretResidencyResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * Extracted from {@see Site}. Composed back into the model via `use`.
 *
 * @property ?string $active_deploy_pipeline_id
 * @property array<string, mixed> $meta
 * @property-read ?Server $server
 * @property-read ?User $user
 * @property-read ?Organization $organization
 * @property-read ?Workspace $workspace
 * @property-read ?Project $project
 * @property-read ?ProviderCredential $dnsProviderCredential
 * @property-read ?ProviderCredential $edgeProviderCredential
 * @property-read ?ProviderCredential $serverlessProviderCredential
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteDomain> $domains
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SitePreviewDomain> $previewDomains
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteDomainAlias> $domainAliases
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteBasicAuthUser> $basicAuthUsers
 * @property-read ?SiteAccessGate $accessGate
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteAccessGatePassword> $accessGatePasswords
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteUptimeMonitor> $uptimeMonitors
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WebhookDeliveryLog> $webhookDeliveryLogs
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteBinding> $bindings
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OrganizationSecret> $organizationSecrets
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteRedirect> $redirects
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EdgeDeployment> $edgeDeployments
 * @property-read ?EdgeSiteAccessRule $edgeSiteAccessRule
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EdgeSiteEnvVar> $edgeEnvVars
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EdgeSiteMember> $edgeSiteMembers
 * @property-read \Illuminate\Database\Eloquent\Collection<int, NotificationSubscription> $notificationSubscriptions
 */
trait HasSiteRelationships
{
    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function dnsProviderCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class, 'dns_provider_credential_id');
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function edgeProviderCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class, 'edge_provider_credential_id');
    }

    /**
     * Provider credential used for DNS automation on this site (preview hostnames, DNS-01 defaults, etc.).
     * Uses the site override when set and DNS-capable; otherwise the latest DNS-capable credential for the organization (any provider).
     */
    public function dnsAutomationCredential(): ?ProviderCredential
    {
        $this->loadMissing('dnsProviderCredential');

        if ($this->dns_provider_credential_id) {
            $explicit = $this->dnsProviderCredential;
            if ($explicit !== null
                && $explicit->organization_id === $this->organization_id
                && $explicit->supportsDnsAutomation()) {
                return $explicit;
            }
        }

        if ($this->organization_id === null) {
            return null;
        }

        return ProviderCredential::query()
            ->where('organization_id', $this->organization_id)
            ->whereIn('provider', ProviderCredential::dnsAutomationProviderKeys())
            ->latest('updated_at')
            ->first();
    }

    /** @return HasMany<SiteDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(SiteDomain::class);
    }

    /** @return HasMany<SitePreviewDomain, $this> */
    public function previewDomains(): HasMany
    {
        return $this->hasMany(SitePreviewDomain::class)->orderByDesc('is_primary')->orderBy('hostname');
    }

    /** @return HasMany<SiteDomainAlias, $this> */
    public function domainAliases(): HasMany
    {
        return $this->hasMany(SiteDomainAlias::class)->orderBy('sort_order')->orderBy('hostname');
    }

    /** @return HasMany<SiteBasicAuthUser, $this> */
    public function basicAuthUsers(): HasMany
    {
        return $this->hasMany(SiteBasicAuthUser::class)->orderBy('sort_order')->orderBy('username');
    }

    /** @return HasOne<SiteAccessGate, $this> */
    public function accessGate(): HasOne
    {
        return $this->hasOne(SiteAccessGate::class);
    }

    /** @return HasMany<SiteAccessGatePassword, $this> */
    public function accessGatePasswords(): HasMany
    {
        return $this->hasMany(SiteAccessGatePassword::class)->orderBy('sort_order')->orderBy('label');
    }

    /**
     * Password gate credentials that should be written to config.json and enforced.
     *
     * @return Collection<int, SiteAccessGatePassword>
     */
    public function enforceableAccessGatePasswords(): Collection
    {
        $this->loadMissing('accessGatePasswords');

        return $this->accessGatePasswords->reject(
            fn (SiteAccessGatePassword $row): bool => $row->isPendingRemoval(),
        )->values();
    }

    /**
     * Subset of {@see basicAuthUsers()} that the webserver should actually
     * enforce: managed (Dply wrote the htpasswd) AND not pending-removal
     * (the next apply will drop them). Both the nginx config builder and the
     * htpasswd-sync helper must use this same subset — otherwise the config
     * can reference an htpasswd file the sync just deleted, locking everyone
     * out with a 500 from nginx.
     *
     * @return Collection<int, SiteBasicAuthUser>
     */
    public function enforceableBasicAuthUsers(): Collection
    {
        $this->loadMissing('basicAuthUsers');

        return $this->basicAuthUsers->reject(
            fn (SiteBasicAuthUser $u): bool => $u->isPendingRemoval() || $u->isDiscoveredFromServer()
        )->values();
    }

    /** @return HasMany<SiteUptimeMonitor, $this> */
    public function uptimeMonitors(): HasMany
    {
        return $this->hasMany(SiteUptimeMonitor::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<WebhookDeliveryLog, $this> */
    public function webhookDeliveryLogs(): HasMany
    {
        return $this->hasMany(WebhookDeliveryLog::class)->orderByDesc('id');
    }

    /** @return HasMany<SiteBinding, $this> */
    public function bindings(): HasMany
    {
        return $this->hasMany(SiteBinding::class);
    }

    /** @return BelongsToMany<OrganizationSecret, $this> */
    public function organizationSecrets(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationSecret::class, 'organization_secret_sites')
            ->withPivot('key')
            ->withTimestamps();
    }

    /**
     * Per-key secret residency records — the env vars this site keeps OUT of the
     * loose plaintext-in-DB `.env` blob (escrowed under an org key, or referenced
     * from an external store). The blob carries only placeholders for these keys;
     * {@see SecretResidencyResolver} resolves them at push. *
     *
     * @return HasMany<SiteSecretResidency, $this>
     */
    /** @return HasMany<SiteSecretResidency, $this> */
    public function secretResidencies(): HasMany
    {
        return $this->hasMany(SiteSecretResidency::class);
    }

    /** @return HasMany<SiteRedirect, $this> */
    public function redirects(): HasMany
    {
        return $this->hasMany(SiteRedirect::class)->orderBy('sort_order');
    }

    /** @return HasMany<EdgeDeployment, $this> */
    public function edgeDeployments(): HasMany
    {
        return $this->hasMany(EdgeDeployment::class)->orderByDesc('created_at');
    }

    /** @return HasOne<EdgeSiteAccessRule, $this> */
    public function edgeSiteAccessRule(): HasOne
    {
        return $this->hasOne(EdgeSiteAccessRule::class);
    }

    /** @return HasMany<EdgeSiteEnvVar, $this> */
    public function edgeEnvVars(): HasMany
    {
        return $this->hasMany(EdgeSiteEnvVar::class)->orderBy('key');
    }

    /** @return HasMany<EdgeSiteMember, $this> */
    public function edgeSiteMembers(): HasMany
    {
        return $this->hasMany(EdgeSiteMember::class);
    }

    /** @return MorphMany<NotificationSubscription, $this> */
    public function notificationSubscriptions(): MorphMany
    {
        return $this->morphMany(NotificationSubscription::class, 'subscribable');
    }
}
