<?php

namespace App\Models;

use App\Models\Concerns\ManagesOrganizationBeta;
use App\Models\Concerns\ManagesOrganizationMembership;
use App\Models\Concerns\ManagesOrganizationPreferences;
use App\Models\Concerns\ManagesOrganizationQuotas;
use App\Models\Concerns\ManagesOrganizationSubscription;
use App\Models\Concerns\ManagesOrganizationTrialState;
use App\Models\Concerns\RoutesIntercomNotifications;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Billable;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property ?string $email
 * @property ?string $icon_path
 * @property ?string $description
 * @property ?string $timezone
 * @property bool $deploy_email_notifications_enabled
 * @property bool $email_server_credentials_enabled
 * @property bool $email_database_credentials_enabled
 * @property ?array<string, mixed> $server_site_preferences
 * @property ?string $default_site_script_id
 * @property ?Carbon $cron_maintenance_until
 * @property ?string $cron_maintenance_note
 * @property ?array<string, mixed> $firewall_settings
 * @property ?string $edge_data_region
 * @property ?array<string, mixed> $database_workspace_settings
 * @property ?array<string, mixed> $insights_preferences
 * @property ?array<string, mixed> $services_preferences
 * @property ?string $alert_slack_webhook_url
 * @property array<string, mixed>|null $alert_extra_emails
 * @property ?string $invoice_email
 * @property ?string $vat_number
 * @property ?string $billing_currency
 * @property ?array<string, mixed> $billing_details
 * @property ?string $pm_last_four
 * @property ?string $stripe_id
 * @property ?Carbon $trial_ends_at
 * @property ?Carbon $beta_joined_at
 * @property bool $is_internal
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, Server> $servers
 * @property-read Collection<int, Site> $sites
 * @property-read Collection<int, OrganizationSecret> $sharedSecrets
 * @property-read Collection<int, NotificationChannel> $notificationChannels
 * @property-read Collection<int, OrganizationBillingSnapshot> $billingSnapshots
 * @property-read Collection<int, BillingSubscriptionSyncEvent> $billingSubscriptionSyncEvents
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, Workspace> $workspaces
 * @property-read Collection<int, StatusPage> $statusPages
 * @property-read Collection<int, ProviderCredential> $providerCredentials
 * @property-read Collection<int, OrganizationInvitation> $invitations
 * @property-read Collection<int, AuditLog> $auditLogs
 * @property-read Collection<int, ApiToken> $apiTokens
 * @property-read Collection<int, NotificationWebhookDestination> $notificationWebhookDestinations
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use Billable, HasFactory, HasUlids;

    use ManagesOrganizationBeta;
    use ManagesOrganizationMembership;
    use ManagesOrganizationPreferences;
    use ManagesOrganizationQuotas;
    use ManagesOrganizationSubscription;
    use ManagesOrganizationTrialState;
    use RoutesIntercomNotifications;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'icon_path',
        'description',
        'timezone',
        'deploy_email_notifications_enabled',
        'email_server_credentials_enabled',
        'email_database_credentials_enabled',
        'server_site_preferences',
        'default_site_script_id',
        'cron_maintenance_until',
        'cron_maintenance_note',
        'firewall_settings',
        'edge_data_region',
        'database_workspace_settings',
        'insights_preferences',
        'services_preferences',
        'alert_slack_webhook_url',
        'alert_extra_emails',
        // Billing entity. Used on Stripe invoices for this org's
        // subscription. Migrated off users in 2026-05.
        'invoice_email',
        'vat_number',
        'billing_currency',
        'billing_details',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'beta_joined_at' => 'datetime',
            'is_internal' => 'boolean',
            'deploy_email_notifications_enabled' => 'boolean',
            'email_server_credentials_enabled' => 'boolean',
            'email_database_credentials_enabled' => 'boolean',
            'server_site_preferences' => 'array',
            'cron_maintenance_until' => 'datetime',
            'firewall_settings' => 'array',
            'database_workspace_settings' => 'array',
            'insights_preferences' => 'array',
            'services_preferences' => 'array',
            'alert_extra_emails' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            // 14-day no-card trial — committed pricing model. Skip if explicitly
            // set by the caller (factories, imports, fixtures) so they keep control.
            if (data_get($organization->getAttributes(), 'trial_ends_at') === null) {
                $days = (int) config('subscription.standard.trial_days', 14);
                $organization->trial_ends_at = now()->addDays($days);
            }
        });

        static::created(function (Organization $organization): void {
            $organization->createDefaultTeamIfMissing();
        });
    }

    /**
     * Per-request memo for {@see owesNothingThisCycle}.
     */
    private ?bool $owesNothingMemo = null;

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<Team, $this> */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }


    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }


    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** @return HasMany<OrganizationSecret, $this> */
    public function sharedSecrets(): HasMany
    {
        return $this->hasMany(OrganizationSecret::class)->orderBy('key');
    }

    /**
     * Whether this org has any Cloud (container) apps.
     *
     * Gates the Cloud-alerts section on Automation: that form configures alert
     * routing for Cloud apps specifically, so showing it to an org with none is
     * noise. Deliberately an exists() rather than a count — the caller only
     * needs the boolean.
     */
    public function hasCloudApps(): bool
    {
        return $this->sites()->cloudApps()->exists();
    }

    /** The org's secret-residency encryption key (per-org age keypair), if minted. *
     * @return HasOne<OrgSecretKey, $this>
     */
    /** @return HasOne<OrgSecretKey, $this> */
    public function secretKey(): HasOne
    {
        return $this->hasOne(OrgSecretKey::class);
    }


    /** @return HasMany<LookoutProject, $this> */
    public function lookoutProjects(): HasMany
    {
        return $this->hasMany(LookoutProject::class);
    }


    /** @return HasMany<OrganizationBillingSnapshot, $this> */
    public function billingSnapshots(): HasMany
    {
        return $this->hasMany(OrganizationBillingSnapshot::class);
    }

    /** @return HasMany<BillingSubscriptionSyncEvent, $this> */
    public function billingSubscriptionSyncEvents(): HasMany
    {
        return $this->hasMany(BillingSubscriptionSyncEvent::class);
    }







    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Workspace, $this> */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /** @return HasMany<StatusPage, $this> */
    public function statusPages(): HasMany
    {
        return $this->hasMany(StatusPage::class);
    }

    /** @return HasMany<ProviderCredential, $this> */
    public function providerCredentials(): HasMany
    {
        return $this->hasMany(ProviderCredential::class);
    }


    /** @return HasMany<OrganizationInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class, 'organization_id');
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /** @return HasMany<ApiToken, $this> */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /** @return HasMany<NotificationWebhookDestination, $this> */
    public function notificationWebhookDestinations(): HasMany
    {
        return $this->hasMany(NotificationWebhookDestination::class);
    }

    /** @return MorphMany<NotificationChannel, $this> */
    public function notificationChannels(): MorphMany
    {
        return $this->morphMany(NotificationChannel::class, 'owner');
    }

    /**
     * Per-request memo for the current user's pivot role on this org.
     * Without this, hasMember / hasAdminAccess / userIsDeployer each
     * fire their own join against organization_user on every gate call —
     * the debug bar showed the same row queried back-to-back from
     * Organization.php#314, #319, #326 on a single page render.
     *
     * Keyed by user id so the cache works across users (e.g. when an
     * admin views a teammate's permissions on the same org instance).
     *
     * @var array<string, ?string> user-id => role (or null when not a member)
     */
    private array $memberRoleMemo = [];

    /**
     * Class-level cache keyed by `{organization_id}:{user_id}` so two different
     * Organization instances representing the same row (e.g. one from
     * $user->currentOrganization(), one from $server->organization) share a
     * single pivot lookup. The previous instance-level memo only saved
     * repeated calls on the same instance, which left ~3 duplicate queries
     * per page render.
     *
     * @var array<string, ?string>
     */
    private static array $memberRoleStaticMemo = [];
}
