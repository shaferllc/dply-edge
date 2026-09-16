<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 *                      OAuth device-flow authorization record. The dply CLI calls
 *                      /api/v1/auth/device/start which mints one of these (status=pending),
 *                      shows the user the short `user_code` to type into the web approval
 *                      page, then polls /api/v1/auth/device/poll with the long device_code
 *                      until status flips to authorized (token returned exactly once) or
 *                      expired/denied.
 *                      Plaintext device_code lives only client-side; we store sha256 of it
 *                      and look up via {@see resolveDeviceCode()}. Plaintext API token is
 *                      cached encrypted-at-rest and zeroed on first /poll delivery so the
 *                      same device_code can never fetch the token twice.
 * @property ?string $api_token_id
 * @property ?Carbon $authorized_at
 * @property ?Carbon $delivered_at
 * @property string $device_code_hash
 * @property ?Carbon $expires_at
 * @property string|null $ip_address
 * @property ?string $organization_id
 * @property string $status
 * @property string|null $token_plaintext
 * @property string|null $user_agent
 * @property string $user_code
 * @property ?string $user_id
 * @property-read ?User $user
 * @property-read ?Organization $organization
 * @property-read ?ApiToken $apiToken
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DeviceAuthorization extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_DENIED = 'denied';

    public const STATUS_EXPIRED = 'expired';

    public const DEFAULT_TTL_SECONDS = 900;

    public const DEFAULT_POLL_INTERVAL_SECONDS = 2;

    protected $fillable = [
        'device_code_hash',
        'user_code',
        'user_id',
        'organization_id',
        'api_token_id',
        'status',
        'token_plaintext',
        'ip_address',
        'user_agent',
        'expires_at',
        'authorized_at',
        'delivered_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'authorized_at' => 'datetime',
            'delivered_at' => 'datetime',
            'token_plaintext' => 'encrypted',
        ];
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

    /** @return BelongsTo<ApiToken, $this> */
    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class);
    }

    /**
     * Mint a new pending device authorization. Returns the row plus the
     * one-time plaintext device_code that the CLI is responsible for
     * presenting back to /poll — we keep only sha256 of it.
     *
     * @return array{record: self, device_code: string}
     */
    public static function start(?string $ip = null, ?string $userAgent = null): array
    {
        $deviceCode = self::generateDeviceCode();
        $userCode = self::generateUniqueUserCode();

        $record = self::query()->create([
            'device_code_hash' => hash('sha256', $deviceCode),
            'user_code' => $userCode,
            'status' => self::STATUS_PENDING,
            'ip_address' => $ip ? mb_substr($ip, 0, 45) : null,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 512) : null,
            'expires_at' => Carbon::now()->addSeconds(self::DEFAULT_TTL_SECONDS),
        ]);

        return ['record' => $record, 'device_code' => $deviceCode];
    }

    public static function resolveDeviceCode(string $deviceCode): ?self
    {
        $deviceCode = trim($deviceCode);
        if ($deviceCode === '') {
            return null;
        }

        return self::query()
            ->where('device_code_hash', hash('sha256', $deviceCode))
            ->first();
    }

    public static function resolveUserCode(string $userCode): ?self
    {
        $userCode = self::normalizeUserCode($userCode);
        if ($userCode === '') {
            return null;
        }

        // Multiple historical rows can share a user_code over time
        // (the constraint is only enforced against pending+unexpired
        // rows). Always pick the most recent one so the approval page
        // never grabs a stale authorized/denied/expired record.
        return self::query()
            ->where('user_code', $userCode)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Strip whitespace/dashes and uppercase so "wxyz-abcd", "WXYZABCD"
     * and "WXYZ-ABCD" all match the same row.
     */
    public static function normalizeUserCode(string $userCode): string
    {
        return mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $userCode) ?? '');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->isExpired();
    }

    /**
     * Format the stored 8-char user_code as `XXXX-XXXX` for display.
     */
    public function formattedUserCode(): string
    {
        $clean = self::normalizeUserCode($this->user_code);
        if (mb_strlen($clean) !== 8) {
            return $clean;
        }

        return mb_substr($clean, 0, 4).'-'.mb_substr($clean, 4, 4);
    }

    /**
     * Generate an opaque 32-char device_code (URL-safe). Used as the
     * CLI's bearer when polling /api/v1/auth/device/poll.
     */
    protected static function generateDeviceCode(): string
    {
        return Str::random(32);
    }

    /**
     * Pick a short 8-char code from an unambiguous alphabet (no I/O/0/1)
     * so it's easy to retype on the web. Retries up to a handful of
     * times if it collides with a live pending code.
     */
    protected static function generateUniqueUserCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $raw = '';
            for ($i = 0; $i < 8; $i++) {
                $raw .= $alphabet[random_int(0, mb_strlen($alphabet) - 1)];
            }
            $exists = self::query()
                ->where('user_code', $raw)
                ->where('status', self::STATUS_PENDING)
                ->where('expires_at', '>', Carbon::now())
                ->exists();
            if (! $exists) {
                return $raw;
            }
        }

        // Extremely unlikely after 8 attempts — fall back to a longer
        // code so we never block a user from logging in.
        return mb_strtoupper(Str::random(12));
    }
}
