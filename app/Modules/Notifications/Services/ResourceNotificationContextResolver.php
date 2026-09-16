<?php

namespace App\Modules\Notifications\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

class ResourceNotificationContextResolver
{
    /**
     * @return array{
     *     organization_id: string|null,
     *     team_id: string|null,
     *     resource_type: string|null,
     *     resource_id: string|null,
     *     url: string|null,
     *     stakeholder_user_ids: list<string>
     * }
     */
    public function resolve(?Model $subject): array
    {
        if ($subject instanceof Server) {
            return [
                'organization_id' => $subject->organization_id,
                'team_id' => $subject->team_id,
                'resource_type' => Server::class,
                'resource_id' => (string) $subject->getKey(),
                'url' => null,
                'stakeholder_user_ids' => $this->serverStakeholders($subject),
            ];
        }

        if ($subject instanceof Site) {
            return [
                'organization_id' => $subject->organization_id,
                'team_id' => null,
                'resource_type' => Site::class,
                'resource_id' => (string) $subject->getKey(),
                'url' => route('sites.show', [$subject->server, $subject], absolute: true),
                'stakeholder_user_ids' => $this->siteStakeholders($subject),
            ];
        }

        if ($subject instanceof Workspace) {
            return [
                'organization_id' => $subject->organization_id,
                'team_id' => null,
                'resource_type' => Workspace::class,
                'resource_id' => (string) $subject->getKey(),
                'url' => null,
                'stakeholder_user_ids' => $this->workspaceStakeholders($subject),
            ];
        }

        $organizationId = null;
        if (isset($subject->organization_id) && $subject->organization_id !== '') {
            $organizationId = (string) $subject->organization_id;
        }

        return [
            'organization_id' => $organizationId,
            'team_id' => isset($subject->team_id) ? (string) $subject->team_id : null,
            'resource_type' => $subject ? $subject::class : null,
            'resource_id' => $subject ? (string) $subject->getKey() : null,
            'url' => null,
            'stakeholder_user_ids' => $this->organizationStakeholderIds($organizationId),
        ];
    }

    /**
     * @return list<string>
     */
    private function serverStakeholders(Server $server): array
    {
        return $this->mergeUserIds(
            [$server->user_id],
            $this->organizationStakeholderIds($server->organization_id)
        );
    }

    /**
     * @return list<string>
     */
    private function siteStakeholders(Site $site): array
    {
        return $this->mergeUserIds(
            [$site->user_id],
            $this->organizationStakeholderIds($site->organization_id)
        );
    }

    /**
     * @return list<string>
     */
    private function workspaceStakeholders(Workspace $workspace): array
    {
        return $this->mergeUserIds(
            [$workspace->user_id],
            $workspace->members()->pluck('user_id')->all(),
            $this->organizationStakeholderIds($workspace->organization_id)
        );
    }

    /**
     * @return list<string>
     */
    private function organizationStakeholderIds(?string $organizationId): array
    {
        if ($organizationId === null || $organizationId === '') {
            return [];
        }

        return Organization::query()
            ->whereKey($organizationId)
            ->first()
            ?->users()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->pluck('users.id')
            ->all() ?? [];
    }

    /**
     * @param  array<int, mixed>  ...$groups
     * @return list<string>
     */
    private function mergeUserIds(...$groups): array
    {
        return array_values(array_unique(array_filter(
            collect($groups)->flatten()->map(fn ($id) => $id ? (string) $id : null)->all()
        )));
    }
}
