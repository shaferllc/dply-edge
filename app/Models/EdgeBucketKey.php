<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An S3 key for object storage (EdgeBucketKeys): a Cloudflare account token
 * scoped to R2 buckets. token_id is the S3 Access Key ID. An app key
 * (site_id set) keeps its secret, encrypted, for the deploy's AWS_* env; an
 * external key keeps only the last four characters.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $site_id
 * @property string $label
 * @property list<string> $buckets
 * @property string $access write|read
 * @property string $token_id
 * @property ?string $secret
 * @property string $secret_last4
 * @property string $status active|disabled
 * @property ?string $created_by
 */
class EdgeBucketKey extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = ['organization_id', 'site_id', 'label', 'buckets', 'access', 'token_id', 'secret', 'secret_last4', 'status', 'created_by'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['buckets' => 'array', 'secret' => 'encrypted'];
    }
}
