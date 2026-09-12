<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

if (! function_exists('server_workspace_nav_item_url')) {
    /**
     * URL for a server workspace sidebar item.
     *
     * @param  array<string, mixed>  $item
     */
    function server_workspace_nav_item_url(Server $server, array $item): string
    {
        $routeName = $item['route'] ?? '';

        if (! empty($item['preview_only']) && is_string($item['preview_route'] ?? null) && $item['preview_route'] !== '') {
            $routeName = $item['preview_route'];
        }

        return route($routeName, $server);
    }
}

if (! function_exists('audit_log')) {
    /**
     * Log an action to the organization audit log.
     *
     * @param  ?array<string, mixed>  $oldValues
     * @param  ?array<string, mixed>  $newValues
     */
    function audit_log(
        Organization $organization,
        ?User $user,
        string $action,
        ?Model $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): AuditLog {
        return AuditLog::log($organization, $user, $action, $subject, $oldValues, $newValues);
    }
}
