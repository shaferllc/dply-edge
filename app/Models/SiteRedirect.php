<?php

namespace App\Models;

use App\Enums\SiteRedirectKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?string $comment
 * @property string $from_path
 * @property SiteRedirectKind $kind
 * @property ?array<string, mixed> $response_headers
 * @property ?string $site_id
 * @property string $sort_order
 * @property int $status_code
 * @property string $to_url
 * @property-read ?Site $site
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiteRedirect extends Model
{
    use HasUlids;

    protected $fillable = [
        'site_id',
        'kind',
        'from_path',
        'to_url',
        'status_code',
        'response_headers',
        'comment',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => SiteRedirectKind::class,
            'status_code' => 'integer',
            'response_headers' => 'array',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
