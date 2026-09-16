<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $cookie_secret
 * @property string $method
 * @property ?string $password_salt
 * @property ?string $password_verifier
 * @property ?string $site_id
 * @property-read ?Site $site
 * @property-read Collection<int, SiteAccessGatePassword> $passwords
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiteAccessGate extends Model
{
    use HasUlids;

    public const METHOD_OFF = 'off';

    public const METHOD_BASIC_AUTH = 'basic_auth';

    public const METHOD_FORM_PASSWORD = 'form_password';

    protected $fillable = [
        'site_id',
        'method',
        'password_salt',
        'password_verifier',
        'cookie_secret',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<SiteAccessGatePassword, $this> */
    public function passwords(): HasMany
    {
        return $this->hasMany(SiteAccessGatePassword::class, 'site_id', 'site_id')
            ->orderBy('sort_order')
            ->orderBy('label');
    }

    public function isFormPasswordActive(): bool
    {
        if ($this->method !== self::METHOD_FORM_PASSWORD) {
            return false;
        }

        $this->loadMissing('passwords');

        if ($this->passwords->reject(fn (SiteAccessGatePassword $row): bool => $row->isPendingRemoval())->isNotEmpty()) {
            return true;
        }

        return $this->password_verifier !== '';
    }

    public function isOff(): bool
    {
        return $this->method === self::METHOD_OFF;
    }
}
