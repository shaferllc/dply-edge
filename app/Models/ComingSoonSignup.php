<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $email
 * @property ?string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ComingSoonSignup extends Model
{
    use HasUlids;

    protected $fillable = [
        'email',
        'source',
    ];

    public static function subscribe(string $email, ?string $source = null): self
    {
        return self::query()->firstOrCreate(
            ['email' => Str::lower(trim($email))],
            ['source' => $source]
        );
    }
}
