<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?list<string> $allowed_emails
 * @property string $cookie_secret
 * @property string $mode
 * @property ?string $password_hash
 * @property ?string $password_salt
 * @property ?string $password_verifier
 * @property ?string $site_id
 * @property-read ?Site $site
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EdgeSiteAccessRule extends Model
{
    use HasUlids;

    public const MODE_OFF = 'off';

    public const MODE_PASSWORD = 'password';

    public const MODE_DPLY_ACCOUNT = 'dply_account';

    protected $fillable = [
        'site_id',
        'mode',
        'password_hash',
        'password_salt',
        'password_verifier',
        'cookie_secret',
        'allowed_emails',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allowed_emails' => 'array',
            'password_hash' => 'hashed',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isEnabled(): bool
    {
        return $this->mode !== self::MODE_OFF;
    }

    /**
     * @return list<string>
     */
    public function normalizedAllowedEmails(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            $this->allowed_emails ?? [],
        ))));
    }
}
