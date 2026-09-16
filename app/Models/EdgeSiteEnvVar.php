<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-site Edge environment variable. The plaintext value is persisted in
 * `value_encrypted` via Laravel's `encrypted` cast — the column holds
 * ciphertext at rest. Callers should set/read `->value` rather than
 * touching `value_encrypted` directly.
 *
 * @property string $id
 * @property string $site_id
 * @property string $key
 * @property string $value
 * @property string $scope
 * @property ?string $created_by_user_id
 * @property string $value_encrypted
 * @property-read ?Site $site
 * @property-read ?User $creator
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EdgeSiteEnvVar extends Model
{
    use HasUlids;

    public const SCOPE_PRODUCTION = 'production';

    public const SCOPE_PREVIEW = 'preview';

    /**
     * Bindings injected by the dply Edge platform — customers cannot
     * overwrite these names. Mirrors the reserved-name pattern used for
     * (future) per-deployment Worker bindings.
     *
     * @var list<string>
     */
    public const RESERVED_NAMES = [
        'HOST_MAP',
        'ASSETS',
        'ARTIFACTS',
        'DEPLOYMENT_ID',
        'SITE_ID',
        'STORAGE_PREFIX',
        'EDGE_ANALYTICS',
        'LOG_INGEST_BASE_URL',
        'LOG_INGEST_KEY',
        'ENVIRONMENT',
    ];

    protected $fillable = [
        'site_id',
        'key',
        'value',
        'scope',
        'created_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value_encrypted' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * `value` is a virtual attribute — reads decrypt `value_encrypted`,
     * writes route through the encrypted cast so the column at rest is
     * always ciphertext.
     */
    public function getValueAttribute(): string
    {
        $raw = $this->attributes['value_encrypted'] ?? null;
        if ($raw === null || $raw === '') {
            return '';
        }

        return (string) $this->getAttribute('value_encrypted');
    }

    public function setValueAttribute(string $value): void
    {
        $this->setAttribute('value_encrypted', $value);
    }

    /**
     * Validate that a key name is acceptable for storage and not a reserved
     * platform binding. Keys are uppercase, alphanumeric, underscored, and
     * must start with a letter.
     */
    public static function keyIsValid(string $key): bool
    {
        return preg_match('/^[A-Z][A-Z0-9_]{0,127}$/', $key) === 1
            && ! in_array($key, self::RESERVED_NAMES, true);
    }

    /**
     * Reason a key was rejected, or null if the key is valid. Useful for
     * surfacing actionable errors to API / Livewire callers.
     */
    public static function rejectionReason(string $key): ?string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,127}$/', $key) !== 1) {
            return 'Key must be ALL_CAPS, start with a letter, and contain only A–Z, 0–9, and underscores.';
        }

        if (in_array($key, self::RESERVED_NAMES, true)) {
            return 'Key '.$key.' is reserved by the dply Edge platform.';
        }

        return null;
    }
}
