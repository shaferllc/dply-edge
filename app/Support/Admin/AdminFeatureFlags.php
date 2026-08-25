<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

final class AdminFeatureFlags
{
    /**
     * Every flag registered with Pennant, grouped by namespace.
     *
     * Enumerated straight from config/features.php (skipping the reserved
     * `beta_bundle` list), so this is the complete catalog — not the curated
     * product-line subset. Shape: namespace => [ fullKey => label ].
     *
     * @return array<string, array<string, string>>
     */
    public static function allRegisteredFlags(): array
    {
        $grouped = [];

        foreach (config('features', []) as $namespace => $flags) {
            if ($namespace === 'beta_bundle' || ! is_array($flags)) {
                continue;
            }

            foreach (array_keys($flags) as $leaf) {
                $key = "{$namespace}.{$leaf}";
                $grouped[$namespace][$key] = self::flagLabel($key);
            }
        }

        return $grouped;
    }

    /**
     * A human label for any flag key: prefer a curated label from the admin
     * config, otherwise derive one from the leaf name.
     */
    public static function flagLabel(string $key): string
    {
        $curated = self::orgFlagLabel($key) ?? self::globalFlagLabel($key);
        if (is_string($curated) && $curated !== '') {
            return $curated;
        }

        $leaf = str_contains($key, '.') ? explode('.', $key, 2)[1] : $key;

        return (string) Str::of($leaf)->replace('_', ' ')->headline();
    }

    /**
     * The platform-wide override for a flag, or null when none is set.
     */
    public static function platformOverride(string $key): ?bool
    {
        return PlatformFeatureDefaults::get($key);
    }

    /**
     * The effective platform default: an explicit platform override wins,
     * otherwise the config/env default. (Per-org rows can still override this
     * for a specific org.)
     */
    public static function platformState(string $key): bool
    {
        return self::platformOverride($key) ?? self::configDefault($key);
    }

    public static function isPlatformOverridden(string $key): bool
    {
        return self::platformOverride($key) !== null;
    }

    /**
     * Count of per-org override rows keyed by flag name, in one query. Any
     * flag with no org overrides is simply absent from the result.
     *
     * @return array<string, int>
     */
    public static function orgOverrideCountsByFlag(): array
    {
        $counts = DB::table('features')
            ->where('scope', 'like', self::orgScopeLikePrefixPublic().'%')
            ->selectRaw('name, count(*) as c')
            ->groupBy('name')
            ->pluck('c', 'name');

        $result = [];
        foreach ($counts as $name => $count) {
            $result[(string) $name] = (int) $count;
        }

        return $result;
    }

    /**
     * Remove every per-org override row for a flag, regardless of whether the
     * flag is in the curated product-line set. Falls back to the platform /
     * config default for each org.
     */
    public static function purgeOrgScopedOverridesForAnyFlag(string $flag): int
    {
        return self::purgeOrgScopedOverridesRaw($flag);
    }

    public static function orgScopeLikePrefixPublic(): string
    {
        return self::orgScopeLikePrefix();
    }

    /**
     * @return array<string, string>
     */
    public static function productLineSlugs(): array
    {
        $lines = [];
        foreach (self::productLines() as $slug => $line) {
            $lines[$slug] = is_string($line['title'] ?? null) ? $line['title'] : $slug;
        }

        return $lines;
    }

    public static function productLineTitle(string $slug): ?string
    {
        $line = self::productLines()[$slug] ?? null;

        return is_array($line) && is_string($line['title'] ?? null) ? $line['title'] : null;
    }

    public static function productLineDescription(string $slug): ?string
    {
        $line = self::productLines()[$slug] ?? null;

        return is_array($line) && is_string($line['description'] ?? null) ? $line['description'] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function productLine(string $slug): ?array
    {
        $line = self::productLines()[$slug] ?? null;

        return is_array($line) ? $line : null;
    }

    /**
     * @return array<string, string>
     */
    public static function emergencyFlagsForProductLine(string $slug): array
    {
        $line = self::productLine($slug);

        return is_array($line['emergency'] ?? null) ? $line['emergency'] : [];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function groupsForProductLine(string $slug): array
    {
        $line = self::productLine($slug);

        return is_array($line['groups'] ?? null) ? $line['groups'] : [];
    }

    /**
     * @return array<string, string>
     */
    public static function flagsForProductLine(string $slug): array
    {
        $flags = [];
        foreach (self::groupsForProductLine($slug) as $groupFlags) {
            foreach ($groupFlags as $key => $label) {
                $flags[$key] = $label;
            }
        }

        return $flags;
    }

    public static function productLineRoute(string $slug): ?string
    {
        // Only the two surfaces that still have routes. The VM / Cloud /
        // Serverless flag pages left with those product lines; returning their
        // dead route names here threw RouteNotFound from the admin nav.
        return match ($slug) {
            'edge' => 'admin.flags.edge',
            'platform' => 'admin.flags.platform',
            default => null,
        };
    }

    public static function legacyDefaultGroupRedirectTarget(string $group): ?string
    {
        $map = config('admin_feature_flags.legacy_default_group_redirects', []);

        return is_string($map[$group] ?? null) ? $map[$group] : null;
    }

    public static function legacyOrgTabRedirectTarget(string $tab): ?string
    {
        $map = config('admin_feature_flags.legacy_org_tab_redirects', []);

        return is_string($map[$tab] ?? null) ? $map[$tab] : null;
    }

    public static function resolveOrgTab(string $tab): string
    {
        $legacy = self::legacyOrgTabRedirectTarget($tab);
        if ($legacy !== null) {
            return $legacy;
        }

        return self::productLineTitle($tab) !== null ? $tab : 'vm-servers';
    }

    /**
     * @return array<string, array{title?: string, description?: string, emergency?: array<string, string>, groups?: array<string, array<string, string>>}>
     */
    public static function productLines(): array
    {
        return config('admin_feature_flags.product_lines', []);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function orgGroups(): array
    {
        $groups = [];
        foreach (self::productLines() as $line) {
            if (! isset($line['groups'])) {
                continue;
            }
            foreach ($line['groups'] as $title => $flags) {
                if (! isset($groups[$title])) {
                    $groups[$title] = [];
                }
                $groups[$title] = array_merge($groups[$title], $flags);
            }
        }

        return $groups;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function globalGroups(): array
    {
        return config('admin_feature_flags.global_groups', []);
    }

    /**
     * @return list<string>
     */
    public static function orgFlagKeys(): array
    {
        $keys = [];
        foreach (self::productLines() as $line) {
            if (! isset($line['groups'])) {
                continue;
            }
            foreach ($line['groups'] as $flags) {
                foreach (array_keys($flags) as $key) {
                    if (! self::isGlobalNamespace($key)) {
                        $keys[] = $key;
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string>
     */
    public static function globalFlagKeys(): array
    {
        $keys = [];
        foreach (self::globalGroups() as $flags) {
            foreach (array_keys($flags) as $key) {
                $keys[] = $key;
            }
        }

        foreach (self::productLines() as $line) {
            if (isset($line['emergency'])) {
                foreach (array_keys($line['emergency']) as $key) {
                    $keys[] = $key;
                }
            }
            if (isset($line['groups'])) {
                foreach ($line['groups'] as $flags) {
                    foreach (array_keys($flags) as $key) {
                        if (self::isGlobalNamespace($key)) {
                            $keys[] = $key;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    public static function orgOverrideCount(Organization $org): int
    {
        return (int) DB::table('features')
            ->where('scope', Feature::serializeScope($org))
            ->whereIn('name', self::orgFlagKeys())
            ->count();
    }

    public static function orgOverrideCountForFlag(string $flag): int
    {
        if (! in_array($flag, self::orgFlagKeys(), true)) {
            return 0;
        }

        return (int) DB::table('features')
            ->where('name', $flag)
            ->where('scope', 'like', self::orgScopeLikePrefix().'%')
            ->count();
    }

    /**
     * Remove stored Pennant values for every org so resolution falls back to
     * the config default.
     */
    public static function purgeOrgScopedOverrides(string $flag): int
    {
        if (! in_array($flag, self::orgFlagKeys(), true)) {
            return 0;
        }

        return self::purgeOrgScopedOverridesRaw($flag);
    }

    public static function purgeOrgScopedOverridesRaw(string $flag): int
    {
        return DB::table('features')
            ->where('name', $flag)
            ->where('scope', 'like', self::orgScopeLikePrefix().'%')
            ->delete();
    }

    /**
     * @return array<string, int>
     */
    public static function bulkOrgOverrideCounts(): array
    {
        $prefix = self::orgScopeLikePrefix();

        $counts = DB::table('features')
            ->where('scope', 'like', $prefix.'%')
            ->whereIn('name', self::orgFlagKeys())
            ->selectRaw('scope, count(*) as c')
            ->groupBy('scope')
            ->pluck('c', 'scope');

        $result = [];
        $orgPrefix = Organization::class.'|';
        foreach ($counts as $scope => $count) {
            $id = str_replace($orgPrefix, '', (string) $scope);
            $result[$id] = (int) $count;
        }

        return $result;
    }

    public static function orgFlagLabel(string $key): ?string
    {
        foreach (self::orgGroups() as $flags) {
            if (isset($flags[$key])) {
                return $flags[$key];
            }
        }

        foreach (self::productLines() as $line) {
            if (isset($line['emergency'][$key])) {
                return $line['emergency'][$key];
            }
        }

        return null;
    }

    public static function globalFlagLabel(string $key): ?string
    {
        foreach (self::globalGroups() as $flags) {
            if (isset($flags[$key])) {
                return $flags[$key];
            }
        }

        foreach (self::productLines() as $line) {
            if (isset($line['emergency'][$key])) {
                return $line['emergency'][$key];
            }
            if (isset($line['groups'])) {
                foreach ($line['groups'] as $flags) {
                    if (isset($flags[$key])) {
                        return $flags[$key];
                    }
                }
            }
        }

        return null;
    }

    public static function configDefault(string $key): bool
    {
        [$namespace, $leaf] = explode('.', $key, 2);

        return (bool) (config("features.{$namespace}.{$leaf}") ?? false);
    }

    public static function isGlobalNamespace(string $key): bool
    {
        return str_starts_with($key, 'global.');
    }

    /**
     * @return array<string, string> parent flag key => preview flag key
     */
    public static function featurePreviewPairs(): array
    {
        $pairs = config('admin_feature_flags.feature_preview_pairs', []);

        return is_array($pairs) ? $pairs : [];
    }

    public static function previewFlagFor(string $parentKey): ?string
    {
        $preview = self::featurePreviewPairs()[$parentKey] ?? null;

        return is_string($preview) && $preview !== '' ? $preview : null;
    }

    public static function isPreviewFlag(string $key): bool
    {
        return in_array($key, self::featurePreviewPairs(), true);
    }

    /**
     * Global state is the config default; there is no null-scope override.
     */
    public static function platformOrgFlagActive(string $key): bool
    {
        return self::configDefault($key);
    }

    public static function platformDefault(string $key): bool
    {
        return self::configDefault($key);
    }

    private static function orgScopeLikePrefix(): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Organization::class).'|';
    }

    /**
     * @deprecated Use {@see productLineSlugs()} — kept for redirect helpers.
     *
     * @return array<string, string>
     */
    public static function defaultGroupSlugs(): array
    {
        return config('admin_feature_flags.legacy_default_group_redirects', []);
    }
}
