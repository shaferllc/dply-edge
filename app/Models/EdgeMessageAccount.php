<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An organization's dply Messages account (EdgeMessages): its signing keys,
 * encrypted, and the last published total read from the Worker.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $current_signing_key
 * @property string $next_signing_key
 * @property int $published_counter
 */
class EdgeMessageAccount extends Model
{
    use HasUlids;

    protected $fillable = ['organization_id', 'current_signing_key', 'next_signing_key', 'published_counter'];

    protected $hidden = ['current_signing_key', 'next_signing_key'];

    protected function casts(): array
    {
        return ['current_signing_key' => 'encrypted', 'next_signing_key' => 'encrypted', 'published_counter' => 'integer'];
    }
}
