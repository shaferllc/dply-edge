<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A dply Messages API token (MESSAGES_TOKEN). A token made on the Messages page
 * keeps only its SHA-256 and last four characters (shown once). An app's
 * token (site_id set) also keeps the secret, encrypted, for its deploys.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $site_id
 * @property string $label
 * @property string $token_hash
 * @property string $last4
 * @property ?string $secret
 * @property ?string $created_by
 */
class EdgeMessageToken extends Model
{
    use HasUlids;

    protected $fillable = ['organization_id', 'site_id', 'label', 'token_hash', 'last4', 'secret', 'created_by'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted'];
    }
}
