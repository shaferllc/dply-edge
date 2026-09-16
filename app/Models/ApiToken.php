<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * @property string $id
 * @property ?list<string> $abilities
 * @property ?list<string> $allowed_ips
 * @property ?Carbon $expires_at
 * @property ?Carbon $last_used_at
 * @property string $name
 * @property ?string $organization_id
 * @property string $token_hash
 * @property string $token_prefix
 * @property ?string $user_id
 * @property-read ?User $user
 * @property-read ?Organization $organization
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApiToken extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'organization_id',
        'name',
        'token_prefix',
        'token_hash',
        'last_used_at',
        'expires_at',
        'abilities',
        'allowed_ips',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'abilities' => 'array',
            'allowed_ips' => 'array',
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

    /**
     * All ability strings defined in config/api_token_permissions.php (for validation).
     *
     * @return list<string>
     */
    public static function catalogAbilities(): array
    {
        $out = [];
        foreach (config('api_token_permissions.categories', []) as $cat) {
            foreach ($cat['permissions'] ?? [] as $p) {
                if (! empty($p['ability'])) {
                    $out[] = (string) $p['ability'];
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Abilities the deployer org role may use when calling the HTTP API (subset of catalog).
     *
     * @return list<string>
     */
    public static function deployerApiAllowlist(): array
    {
        return array_values(config('api_token_permissions.deployer_api_allowlist', []));
    }

    /**
     * Whether a string may be stored on api_tokens.abilities (concrete catalog entry, full access, or prefix wildcard).
     */
    public static function abilityIsAllowedForStorage(string $ability): bool
    {
        if ($ability === '*') {
            return true;
        }

        if (in_array($ability, self::catalogAbilities(), true)) {
            return true;
        }

        if (preg_match('/^([a-z0-9_]+)\.\*$/', $ability, $m)) {
            $prefix = $m[1];
            foreach (self::catalogAbilities() as $a) {
                if (str_starts_with($a, $prefix.'.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>|null  $abilities  null or [] means unrestricted (legacy).
     *
     * @throws InvalidArgumentException
     */
    public static function assertAbilitiesValidForStorage(?array $abilities): void
    {
        if ($abilities === null || $abilities === []) {
            return;
        }

        foreach ($abilities as $ab) {
            if ($ab === '') {
                throw new InvalidArgumentException(__('API token abilities must be non-empty strings.'));
            }
            if (! self::abilityIsAllowedForStorage($ab)) {
                throw new InvalidArgumentException(__('Invalid API token ability: :ability', ['ability' => $ab]));
            }
        }
    }

    /**
     * Parse IP allow list from textarea (newlines) or comma-separated input.
     *
     * @return list<string>|null
     */
    public static function parseAllowedIpsInput(string $raw, string $errorKey = 'allowed_ips'): ?array
    {
        $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $line = trim((string) $part);
            if ($line === '') {
                continue;
            }
            if (! self::ipOrCidrIsValid($line)) {
                throw ValidationException::withMessages([
                    $errorKey => __('Invalid IP or CIDR: :value', ['value' => $line]),
                ]);
            }
            $clean[] = $line;
        }

        return $clean !== [] ? $clean : null;
    }

    public static function ipOrCidrIsValid(string $value): bool
    {
        if (str_contains($value, '/')) {
            return (bool) preg_match('#^(\d{1,3}\.){3}\d{1,3}/(3[0-2]|[12]?\d)$#', $value);
        }

        return (bool) filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6);
    }

    /**
     * Create a new token and return the plaintext value (only time it is visible).
     *
     * @param  list<string>|null  $abilities
     * @param  list<string>|null  $allowedIps
     * @return array{token: self, plaintext: string}
     */
    public static function createToken(
        User $user,
        Organization $organization,
        string $name,
        ?\DateTimeInterface $expiresAt = null,
        ?array $abilities = null,
        ?array $allowedIps = null
    ): array {
        self::assertAbilitiesValidForStorage($abilities);

        $plaintext = 'dply_'.Str::random(64);
        $prefix = substr($plaintext, 0, 16);

        $token = self::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => $name,
            'token_prefix' => $prefix,
            'token_hash' => bcrypt($plaintext),
            'expires_at' => $expiresAt,
            'abilities' => $abilities,
            'allowed_ips' => $allowedIps,
        ]);

        return ['token' => $token, 'plaintext' => $plaintext];
    }

    public function isValid(): bool
    {
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * @param  string|null  $ability  e.g. sites.deploy, servers.read, commands.run
     */
    public function allows(?string $ability = null): bool
    {
        if ($ability === null || $ability === '') {
            return false;
        }

        if (! $this->tokenAllowsAbility($ability)) {
            return false;
        }

        $this->loadMissing('user', 'organization');
        if ($this->user && $this->organization?->userIsDeployer($this->user)) {
            return in_array($ability, self::deployerApiAllowlist(), true);
        }

        return true;
    }

    protected function tokenAllowsAbility(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        if ($abilities === []) {
            return true;
        }

        if (in_array('*', $abilities, true)) {
            return true;
        }

        if (in_array($ability, $abilities, true)) {
            return true;
        }

        $parts = explode('.', $ability, 2);
        $prefix = $parts[0];

        return $prefix !== '' && in_array($prefix.'.*', $abilities, true);
    }

    public function touchLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Find a token by the raw value from Authorization header.
     */
    public static function findTokenByPlaintext(string $plaintext): ?self
    {
        if (strlen($plaintext) < 16) {
            return null;
        }

        $prefix = substr($plaintext, 0, 16);
        $token = self::where('token_prefix', $prefix)->first();

        if (! $token || ! Hash::check($plaintext, $token->token_hash)) {
            return null;
        }

        return $token;
    }

    /**
     * Masked display (e.g. dply_abc12345...).
     */
    public function getMaskedDisplayAttribute(): string
    {
        return $this->token_prefix.'…';
    }
}
