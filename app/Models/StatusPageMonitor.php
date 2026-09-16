<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?string $label
 * @property ?string $monitorable_id
 * @property string $monitorable_type
 * @property string $sort_order
 * @property ?string $status_page_id
 * @property-read ?StatusPage $statusPage
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StatusPageMonitor extends Model
{
    use HasUlids;

    protected $fillable = [
        'status_page_id',
        'monitorable_type',
        'monitorable_id',
        'label',
        'sort_order',
    ];

    /** @return BelongsTo<StatusPage, $this> */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    /** @return MorphTo<Model, $this> */
    public function monitorable(): MorphTo
    {
        return $this->morphTo();
    }

    public function displayLabel(): string
    {
        if ($this->label) {
            return $this->label;
        }

        $m = $this->monitorable;
        if ($m instanceof Server) {
            return $m->name;
        }
        if ($m instanceof Site) {
            return $m->name;
        }
        if ($m instanceof SiteUptimeMonitor) {
            return $m->label;
        }

        return __('Monitor');
    }
}
