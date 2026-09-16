<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      A customer-owned external secret store dply references but never copies values
 *                      out of (see the table migration). The connection `config` is APP_KEY-encrypted
 *                      (auto-covered by secrets:reencrypt) — but it is only credentials TO the store,
 *                      not the secrets themselves, which never enter dply.
 * @property array<string, mixed> $config
 * @property string $driver
 * @property string $name
 * @property ?string $organization_id
 * @property string $resolution
 * @property-read ?Organization $organization
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ExternalSecretStore extends Model
{
    use HasUlids;

    public const DRIVER_VAULT = 'vault';

    public const DRIVER_AWS_SM = 'aws_sm';

    public const DRIVER_DOPPLER = 'doppler';

    public const DRIVERS = [self::DRIVER_VAULT, self::DRIVER_AWS_SM, self::DRIVER_DOPPLER];

    public const RESOLUTION_DPLY = 'dply';

    public const RESOLUTION_ONBOX = 'onbox';

    protected $table = 'external_secret_stores';

    protected $fillable = [
        'organization_id',
        'driver',
        'name',
        'config',
        'resolution',
    ];

    protected $casts = [
        'config' => 'encrypted:array',
    ];

    protected $hidden = [
        'config',
    ];

    public function resolvesOnBox(): bool
    {
        return $this->resolution === self::RESOLUTION_ONBOX;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
