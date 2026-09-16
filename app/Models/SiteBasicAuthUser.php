<?php

namespace App\Models;

use Database\Factories\SiteBasicAuthUserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $password_hash
 * @property string $path
 * @property ?Carbon $pending_removal_at
 * @property ?string $site_id
 * @property string $sort_order
 * @property ?string $source_file_path
 * @property string $username
 * @property-read ?Site $site
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiteBasicAuthUser extends Model
{
    /** @use HasFactory<SiteBasicAuthUserFactory> */
    use HasFactory, HasUlids;

    protected $table = 'site_basic_auth_users';

    protected $fillable = [
        'site_id',
        'username',
        'password_hash',
        'path',
        'source_file_path',
        'sort_order',
        'pending_removal_at',
    ];

    protected $hidden = [
        'password_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pending_removal_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @param  Builder<SiteBasicAuthUser>  $query
     * @return Builder<SiteBasicAuthUser>
     */
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotPendingRemoval(Builder $query): Builder
    {
        return $query->whereNull('pending_removal_at');
    }

    /**
     * True while the row is awaiting hard-deletion by ApplySiteWebserverConfigJob,
     * after the operator clicked remove and the htpasswd-rewrite apply hasn't
     * finished yet.
     */
    public function isPendingRemoval(): bool
    {
        return $this->pending_removal_at !== null;
    }

    /**
     * Canonical path for routing: `/` is site-wide; otherwise `/segment` without trailing slash (except root).
     */
    public static function normalizePath(?string $path): string
    {
        $p = trim((string) $path);
        if ($p === '' || $p === '/') {
            return '/';
        }

        $p = '/'.ltrim($p, '/');

        return rtrim($p, '/') ?: '/';
    }

    public function normalizedPath(): string
    {
        return self::normalizePath($this->path);
    }

    /**
     * True for entries created by syncBasicAuthFromServer — i.e. imported from
     * a .htpasswd file Dply found on the server but didn't itself author. These
     * rows carry the file's absolute path so the apply flow can edit/unlink it
     * on removal instead of rewriting Dply's managed group file.
     */
    public function isDiscoveredFromServer(): bool
    {
        return filled($this->source_file_path);
    }

    /**
     * Caddy v2's `basic_auth` directive only accepts bcrypt hashes inline. Other
     * htpasswd formats (apr1, sha) can land here via Sync from server but Caddy
     * will refuse to parse them — the operator needs to rotate the password to
     * regenerate a bcrypt hash before Caddy can enforce the credential.
     */
    public function passwordHashIsBcrypt(): bool
    {
        $hash = (string) $this->password_hash;

        return str_starts_with($hash, '$2y$')
            || str_starts_with($hash, '$2a$')
            || str_starts_with($hash, '$2b$');
    }

    /**
     * Apache-style apr1 hash for htpasswd files consumed by nginx, Apache, Traefik, and OLS.
     */
    public static function apr1Hash(string $password): string
    {
        $salt = substr(str_replace(['+', '/'], ['.', '_'], base64_encode(random_bytes(9))), 0, 8);

        return crypt($password, '$apr1$'.$salt);
    }
}
