<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves the platform-admin allow list (config/admin.php → PLATFORM_ADMIN_EMAILS)
 * to real User records so they can be notified.
 *
 * The `viewPlatformAdmin` gate treats every user as an admin in local/testing,
 * but for notification fan-out we only ever target the explicit allow list —
 * we never blast every local user. Allow-listed emails without an account are
 * silently skipped.
 *
 * @see AppServiceProvider Gate::define('viewPlatformAdmin')
 */
final class PlatformAdmins
{
    /**
     * @return list<string>
     */
    public static function emails(): array
    {
        $raw = (string) config('admin.allowed_emails', '');

        return collect(explode(',', $raw))
            ->map(fn (string $email): string => Str::lower(trim($email)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, User>
     */
    public static function users(): Collection
    {
        $emails = self::emails();

        if ($emails === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn(DB::raw('LOWER(email)'), $emails)
            ->get();
    }
}
