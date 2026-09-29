<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A dply-managed database (Postgres, MySQL or MongoDB on dply's gateway),
 * owned by an organization and attached to apps (DplyDatabases). Each app
 * has at most one primary, whose connection is DB_* / DATABASE_URL and which
 * is mirrored at the app's meta.edge.database; the others get prefixed env.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $engine postgres|mysql|mongodb
 * @property string $remote_id the gateway tenant id
 * @property string $region
 * @property string $host
 * @property string $size
 * @property int $suspend
 * @property int $disk_gb
 * @property string $password
 * @property array<string, mixed>|null $state
 * @property ?string $created_by
 */
class DplyDatabase extends Model
{
    use HasUlids;

    protected $fillable = ['organization_id', 'name', 'engine', 'remote_id', 'region', 'host', 'size', 'suspend', 'disk_gb', 'password', 'state', 'created_by'];

    protected $hidden = ['password'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'encrypted', 'state' => 'array', 'suspend' => 'integer', 'disk_gb' => 'integer'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<Site, $this> */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'dply_database_site')->withPivot(['env_name', 'primary'])->withTimestamps();
    }
}
