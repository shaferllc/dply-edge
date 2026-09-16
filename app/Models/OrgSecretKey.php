<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      An organization's `age` keypair for escrowed secrets (see the table migration
 *                      for the trust model). Encrypt with {@see $public_recipient}; decrypt with the
 *                      private identity — which dply holds ({@see $dply_identity}, APP_KEY-encrypted)
 *                      only when {@see HOLDER_DPLY}.
 *                      `dply_identity` IS an `encrypted` cast (APP_KEY-encrypted at rest) — it is the
 *                      exception that proves the rule: it is dply's OWN copy of the key, registered
 *                      in config/secret_vault.php so `secrets:reencrypt` rotates it like any other
 *                      platform secret. The SiteSecretResidency.ciphertext it opens is NOT.
 * @property string|null $dply_identity
 * @property string|null $fingerprint
 * @property string $identity_holder
 * @property ?string $organization_id
 * @property string $public_recipient
 * @property-read ?Organization $organization
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrgSecretKey extends Model
{
    use HasUlids;

    public const HOLDER_DPLY = 'dply';

    public const HOLDER_CUSTOMER = 'customer';

    protected $table = 'org_secret_keys';

    protected $fillable = [
        'organization_id',
        'public_recipient',
        'identity_holder',
        'dply_identity',
        'fingerprint',
    ];

    protected $casts = [
        'dply_identity' => 'encrypted',
    ];

    protected $hidden = [
        'dply_identity',
    ];

    /** Whether dply holds the private identity and can therefore decrypt. */
    public function dplyCanDecrypt(): bool
    {
        return $this->identity_holder === self::HOLDER_DPLY
            && $this->dply_identity !== '';
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
