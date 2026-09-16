<?php

namespace App\Models;

use App\Models\Concerns\RoutesIntercomNotifications;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property ?Carbon $email_verified_at
 * @property ?string $password
 * @property ?string $country_code
 * @property ?string $locale
 * @property ?string $timezone
 * @property ?string $invoice_email
 * @property ?string $vat_number
 * @property ?string $billing_currency
 * @property ?array<string, mixed> $billing_details
 * @property ?string $two_factor_secret
 * @property ?string $two_factor_recovery_codes
 * @property ?Carbon $two_factor_confirmed_at
 * @property ?string $referral_code
 * @property ?Carbon $referral_converted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property ?array<string, mixed> $ui_preferences
 * @property-read Collection<int, Organization> $organizations
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, SocialAccount> $socialAccounts
 * @property-read Collection<int, GitProviderToken> $gitProviderTokens
 * @property-read Collection<int, ProviderCredential> $providerCredentials
 * @property-read Collection<int, Server> $servers
 * @property-read Collection<int, RecentResource> $recentResources
 * @property-read Collection<int, ApiToken> $apiTokens
 * @property-read Collection<int, NotificationChannel> $notificationChannels
 * @property-read Collection<int, NotificationInboxItem> $notificationInboxItems
 * @property ?string $referred_by_user_id Real column (users.referred_by_user_id);
 *                                        it lives only in database/schema/pgsql-schema.sql, which Larastan
 *                                        does not read, so it has to be declared here.
 * @property-read ?User $referrer
 * @property-read Collection<int, User> $referredUsers
 */
#[Fillable([
    'name',
    'email',
    'password',
    'country_code',
    'locale',
    'timezone',
    'invoice_email',
    'vat_number',
    'billing_currency',
    'billing_details',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'two_factor_confirmed_at',
    'referral_code',
    'ui_preferences',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable, PasskeyAuthenticatable;

    use RoutesIntercomNotifications;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (blank(data_get($user->getAttributes(), 'referral_code'))) {
                $user->referral_code = static::newUniqueReferralCode();
            }
        });
    }

    public static function newUniqueReferralCode(): string
    {
        if (! Schema::hasTable((new self)->getTable())) {
            return Str::lower(Str::random(20));
        }

        do {
            $code = Str::lower(Str::random(20));
        } while (static::query()->where('referral_code', $code)->exists());

        return $code;
    }

    /** @return BelongsToMany<Organization, $this> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return HasMany<GitProviderToken, $this> */
    public function gitProviderTokens(): HasMany
    {
        return $this->hasMany(GitProviderToken::class);
    }

    /** @return HasMany<ProviderCredential, $this> */
    public function providerCredentials(): HasMany
    {
        return $this->hasMany(ProviderCredential::class, 'user_id');
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'user_id');
    }

    /** @return HasMany<RecentResource, $this> */
    public function recentResources(): HasMany
    {
        return $this->hasMany(RecentResource::class);
    }

    /** @return HasMany<ApiToken, $this> */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /** @return MorphMany<NotificationChannel, $this> */
    public function notificationChannels(): MorphMany
    {
        return $this->morphMany(NotificationChannel::class, 'owner');
    }

    /** @return HasMany<NotificationInboxItem, $this> */
    public function notificationInboxItems(): HasMany
    {
        return $this->hasMany(NotificationInboxItem::class)->latest();
    }

    /** @return BelongsTo<User, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    /**
     * Users who registered with this user’s referral code.
     *
     * @return HasMany<User, $this>
     */
    /** @return HasMany<User, $this> */
    public function referredUsers(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by_user_id');
    }

    /**
     * Per-request memo for {@see currentOrganization()}. Keyed by the
     * resolved organization id (or the literal '__default__' marker for
     * the no-session-fallback path) so a session switch mid-request is
     * still respected. Without this, every callsite (page header, gates,
     * preflight, debug bar, layouts) re-runs the same join query — the
     * debug bar showed 14+ duplicate hits for a single page load.
     *
     * @var array<string, ?Organization>
     */
    private array $currentOrganizationMemo = [];

    public function currentOrganization(): ?Organization
    {
        $id = session('current_organization_id');
        $key = $id ? (string) $id : '__default__';

        if (array_key_exists($key, $this->currentOrganizationMemo)) {
            return $this->currentOrganizationMemo[$key];
        }

        if (! $id) {
            return $this->currentOrganizationMemo[$key] = $this->primeOrganizationMemberRole(
                $this->organizations()
                    ->orderByPivot('created_at')
                    ->orderBy('organizations.id')
                    ->first()
            );
        }

        return $this->currentOrganizationMemo[$key] = $this->primeOrganizationMemberRole(
            $this->organizations()->find($id)
        );
    }

    /**
     * Prime the per-request {@see currentOrganization()} memo after code
     * has already loaded the org (e.g. middleware picking the first org).
     */
    public function rememberCurrentOrganization(Organization $organization): void
    {
        $id = session('current_organization_id');
        $key = $id ? (string) $id : '__default__';
        $this->currentOrganizationMemo[$key] = $this->primeOrganizationMemberRole($organization);
    }

    private function primeOrganizationMemberRole(?Organization $organization): ?Organization
    {
        if ($organization !== null && $organization->relationLoaded('pivot')) {
            $role = data_get($organization->getRelation('pivot'), 'role');
            if ($role !== null) {
                $organization->rememberMemberRoleFor($this, (string) $role);
            }
        }

        return $organization;
    }

    /**
     * Drop the memoized {@see currentOrganization()} result. Call after
     * any code path that mutates session('current_organization_id') so
     * the next read reflects the new session value.
     */
    public function flushCurrentOrganizationCache(): void
    {
        $this->currentOrganizationMemo = [];
    }

    /**
     * Active team scope for the current organization (session). Null means all teams in the org.
     */
    public function currentTeam(): ?Team
    {
        $org = $this->currentOrganization();
        if (! $org) {
            return null;
        }

        $teamId = session('current_team_id');
        if (! $teamId) {
            return null;
        }

        $team = Team::query()
            ->whereKey($teamId)
            ->where('organization_id', $org->id)
            ->first();

        if (! $team || ! $org->hasMember($this)) {
            return null;
        }

        return $team;
    }

    /**
     * Teams the user may scope the UI to within an organization (org members see all teams in that org).
     *
     * @return Collection<int, Team>
     */
    public function accessibleTeamsForOrganization(?Organization $org = null): Collection
    {
        $org ??= $this->currentOrganization();
        if (! $org || ! $org->hasMember($this)) {
            return new Collection([]);
        }

        return $org->teams()->orderBy('name')->get();
    }

    public function hasVerifiedEmail(): bool
    {
        if (! config('dply.require_email_verification')) {
            return true;
        }

        return parent::hasVerifiedEmail();
    }

    /**
     * Count sites in this user's organizations whose Git remote URL appears to use the given host (e.g. github.com).
     */
    public function gitHostRepositoryCount(string $hostFragment): int
    {
        $orgIds = $this->organizations()->pluck('organizations.id');
        if ($orgIds->isEmpty()) {
            return 0;
        }

        return Site::query()
            ->whereIn('organization_id', $orgIds)
            ->whereNotNull('git_repository_url')
            ->where('git_repository_url', 'like', '%'.$hostFragment.'%')
            ->count();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return data_get($this->getAttributes(), 'two_factor_confirmed_at') !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'referral_converted_at' => 'datetime',
            'ui_preferences' => 'array',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mergedUiPreferences(): array
    {
        $defaults = config('user_preferences.defaults', []);
        $keys = array_keys($defaults);
        $stored = $this->ui_preferences ?? [];

        return array_merge($defaults, array_intersect_key($stored, array_flip($keys)));
    }
}
