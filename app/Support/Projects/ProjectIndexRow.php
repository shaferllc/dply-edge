<?php

declare(strict_types=1);

namespace App\Support\Projects;

use App\Models\User;
use App\Models\Workspace;

/**
 * View-model for the shared Projects index list UI — built from a local
 * {@see Workspace} or a Production API row so both surfaces reuse the same Blade.
 */
final readonly class ProjectIndexRow
{
    /**
     * @param  list<string>  $labels
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public ?string $manageHref,
        public bool $manageEnabled,
        public int $serversCount,
        public int $sitesCount,
        public int $membersCount,
        public ?string $roleLabel,
        public array $labels,
        public string $initials,
    ) {}

    public static function fromWorkspace(Workspace $workspace, ?User $user = null): self
    {
        $membership = $user
            ? $workspace->members->firstWhere('user_id', $user->id)
            : null;

        return new self(
            id: (string) $workspace->id,
            name: (string) $workspace->name,
            description: filled($workspace->description) ? (string) $workspace->description : null,
            manageHref: null,
            manageEnabled: false,
            serversCount: (int) ($workspace->servers_count ?? $workspace->servers()->count()),
            sitesCount: (int) ($workspace->sites_count ?? $workspace->sites()->count()),
            membersCount: $workspace->relationLoaded('members')
                ? $workspace->members->count()
                : (int) $workspace->members()->count(),
            roleLabel: $membership?->role ? ucfirst((string) $membership->role) : null,
            labels: [],
            initials: self::initials((string) $workspace->name),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromProductionApi(array $row): self
    {
        $name = (string) ($row['name'] ?? $row['slug'] ?? '—');
        $role = $row['role'] ?? null;

        return new self(
            id: (string) ($row['id'] ?? ''),
            name: $name,
            description: null,
            manageHref: null,
            manageEnabled: false,
            serversCount: (int) ($row['servers_count'] ?? 0),
            sitesCount: (int) ($row['sites_count'] ?? 0),
            membersCount: 0,
            roleLabel: is_string($role) && $role !== '' ? ucfirst($role) : null,
            labels: [],
            initials: self::initials($name),
        );
    }

    private static function initials(string $name): string
    {
        $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn ($word) => mb_strtoupper(mb_substr((string) $word, 0, 1)))
            ->implode('');

        if ($initials === '') {
            $initials = mb_strtoupper(mb_substr($name, 0, 2));
        }

        return $initials !== '' ? $initials : '?';
    }
}
