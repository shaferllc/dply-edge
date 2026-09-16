<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 *                      URL-bearing token that triggers a redeploy of the parent Edge site
 *                      when POSTed to `/hooks/edge/deploy/{plaintext}` (P10b). The
 *                      plaintext token is shown to the operator once at create-time; only
 *                      the sha256 hash + first-8-char prefix are persisted, so leaked hook
 *                      URLs can be revoked without leaking the original credential.
 * @property ?string $created_by_user_id
 * @property ?string $last_triggered_deployment_id
 * @property ?Carbon $last_used_at
 * @property string $name
 * @property ?string $site_id
 * @property string $token_hash
 * @property string $token_prefix
 * @property-read ?Site $site
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EdgeDeployHook extends Model
{
    use HasUlids;

    protected $fillable = [
        'site_id',
        'name',
        'token_hash',
        'token_prefix',
        'created_by_user_id',
        'last_used_at',
        'last_triggered_deployment_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Mint a new hook, returning the plaintext token (shown to the
     * operator exactly once) plus the persisted model row.
     *
     * @return array{hook: self, plaintext_token: string, hook_url: string}
     */
    public static function mintFor(Site $site, string $name, ?string $userId = null): array
    {
        $plaintext = Str::random(48);
        $hook = self::query()->create([
            'site_id' => $site->id,
            'name' => trim($name) !== '' ? trim($name) : __('Deploy hook'),
            'token_hash' => hash('sha256', $plaintext),
            'token_prefix' => substr($plaintext, 0, 8),
            'created_by_user_id' => $userId,
        ]);

        return [
            'hook' => $hook,
            'plaintext_token' => $plaintext,
            'hook_url' => self::publicUrl($plaintext),
        ];
    }

    public static function resolvePlaintext(string $plaintext): ?self
    {
        $plaintext = trim($plaintext);
        if ($plaintext === '') {
            return null;
        }

        return self::query()
            ->where('token_hash', hash('sha256', $plaintext))
            ->first();
    }

    public static function publicUrl(string $plaintext): string
    {
        return rtrim((string) config('app.url'), '/').'/hooks/edge/deploy/'.$plaintext;
    }
}
