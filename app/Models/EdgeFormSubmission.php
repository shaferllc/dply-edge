<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Edge Forms submission, stored by EdgeFormIngestController.
 *
 * @property string $id
 * @property string $site_id
 * @property string $path
 * @property array<string, string> $fields
 * @property Carbon $created_at
 */
class EdgeFormSubmission extends Model
{
    use HasUlids;

    protected $fillable = ['site_id', 'path', 'fields'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fields' => 'array'];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
