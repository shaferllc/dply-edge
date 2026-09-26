<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 *                      A closed-beta invitation, bound to a single email address. Admin-issued (one
 *                      by one, in bulk, or pulled from the coming-soon waitlist); a valid unredeemed
 *                      token lets that email register while public signups are closed, and flags the
 *                      resulting org as a beta participant. See Livewire\Auth\Register and
 *                      Livewire\Admin\BetaInvites.
 * @property string $email
 * @property ?Carbon $expires_at
 * @property ?Carbon $redeemed_at
 * @property ?Carbon $revoked_at
 * @property string|null $source
 * @property string $token
 * @property-read ?User $inviter
 * @property-read ?User $redeemer
 * @property-read ?Organization $organization
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BetaInvitation extends Model
{
    use HasUlids;

    public const SOURCE_SINGLE = 'admin_single';

    public const SOURCE_BULK = 'admin_bulk';

    public const SOURCE_WAITLIST = 'waitlist';

    protected $fillable = [
        'email',
        'token',
        'source',
        'expires_at',
        'invited_by',
        'redeemed_at',
        'redeemed_by_user_id',
        'organization_id',
        'revoked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<User, $this> */
    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Redeemable = not yet redeemed, not revoked, not expired. The one gate the
     * register flow checks before letting a token bypass closed signups.
     */
    public function isRedeemable(): bool
    {
        return ! $this->isRedeemed() && ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRedeemable(Builder $query): Builder
    {
        return $query->whereNull('redeemed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Issue an invite bound to an email. Re-issuing for an address that already
     * has a live (redeemable) invite returns the existing one rather than
     * minting duplicates — `resend` just re-mails it.
     */
    public static function issue(string $email, ?User $inviter = null, string $source = self::SOURCE_SINGLE): self
    {
        $email = Str::lower(trim($email));

        $existing = self::query()->where('email', $email)->redeemable()->first();
        if ($existing !== null) {
            return $existing;
        }

        return self::create([
            'email' => $email,
            'token' => Str::random(64),
            'source' => $source,
            'expires_at' => now()->addDays(self::expiryDays()),
            'invited_by' => $inviter?->id,
        ]);
    }

    /**
     * Mark this invite redeemed by a freshly-registered user, flag the resulting
     * org as a beta participant.
     */
    public function redeem(User $user, Organization $organization): void
    {
        $this->forceFill([
            'redeemed_at' => now(),
            'redeemed_by_user_id' => $user->id,
            'organization_id' => $organization->id,
        ])->save();

        if ($organization->beta_joined_at === null) {
            $organization->forceFill(['beta_joined_at' => now()])->save();
        }

    }

    public function revoke(): void
    {
        if (! $this->isRevoked()) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }

    public static function expiryDays(): int
    {
        return max(1, (int) config('subscription.standard.beta.invite_expiry_days', 30));
    }
}
