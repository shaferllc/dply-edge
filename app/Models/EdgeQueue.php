<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's Cloudflare Queue.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $cloudflare_id
 * @property string $cloudflare_name
 * @property ?string $created_by
 * @property-read ?Organization $organization
 */
class EdgeQueue extends Model
{
    use HasUlids;

    protected $fillable = ['organization_id', 'name', 'cloudflare_id', 'cloudflare_name', 'created_by'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function cloudflareName(Organization $organization, string $name): string
    {
        return 'dply-'.strtolower((string) $organization->id).'-'.$name;
    }
}
