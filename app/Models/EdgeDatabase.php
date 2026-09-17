<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's Cloudflare D1 database.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $cloudflare_id
 * @property ?string $location_hint
 * @property ?string $created_by
 * @property-read ?Organization $organization
 */
class EdgeDatabase extends Model
{
    use HasUlids;

    protected $fillable = ['organization_id', 'name', 'cloudflare_id', 'location_hint', 'created_by'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Name in dply's Cloudflare account — prefixed so orgs never collide. */
    public static function cloudflareName(Organization $organization, string $name): string
    {
        return 'dply-'.strtolower((string) $organization->id).'-'.$name;
    }
}
