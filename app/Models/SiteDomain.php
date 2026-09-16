<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?string $comment
 * @property string $hostname
 * @property bool $is_primary
 * @property ?string $site_id
 * @property bool $www_redirect
 * @property-read ?Site $site
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiteDomain extends Model
{
    use HasUlids;

    protected $table = 'site_domains';

    protected $fillable = [
        'site_id',
        'hostname',
        'is_primary',
        'www_redirect',
        'comment',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'www_redirect' => 'boolean',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
