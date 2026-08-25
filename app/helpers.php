<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Laravel\Pennant\Feature;


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

if (! function_exists('workspace_cli_active')) {
    /**
     * True when the server workspace CLI reference surface is enabled for the org.
     */
    function workspace_cli_active(?Organization $organization = null): bool
    {
        return $organization === null
            ? Feature::active('workspace.cli')
            : Feature::for($organization)->active('workspace.cli');
    }
}

if (! function_exists('workspace_cli_preview_active')) {
    /**
     * True when CLI is off but the coming-soon teaser should surface in nav
     * and the preview workspace page.
     */
    function workspace_cli_preview_active(?Organization $organization = null): bool
    {
        if (workspace_cli_active($organization)) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.cli_preview')
            : Feature::for($organization)->active('workspace.cli_preview');
    }
}

if (! function_exists('workspace_insights_preview_active')) {
    /**
     * True when insights is off but the coming-soon teaser should surface in
     * nav and the preview workspace page.
     */
    function workspace_insights_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.insights')
            : Feature::for($organization)->active('workspace.insights')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.insights_preview')
            : Feature::for($organization)->active('workspace.insights_preview');
    }
}

if (! function_exists('workspace_server_blueprint_preview_active')) {
    /**
     * True when server blueprint is off but the coming-soon teaser should
     * surface in nav and the preview workspace page.
     */
    function workspace_server_blueprint_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.server_blueprint')
            : Feature::for($organization)->active('workspace.server_blueprint')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.server_blueprint_preview')
            : Feature::for($organization)->active('workspace.server_blueprint_preview');
    }
}

if (! function_exists('workspace_surface_coming_soon')) {
    /**
     * Generic coming-soon check for a `workspace.<feature>` surface: true when the
     * real feature is off but its `<feature>_preview` teaser flag is on. Lets a
     * blade swap real content for the shared <x-workspace-coming-soon> teaser
     * without a per-surface helper. Mirrors {@see SiteSettingsSidebar::markPreviewOnly()}.
     */
    function workspace_surface_coming_soon(string $feature, ?Organization $organization = null): bool
    {
        $active = static fn (string $flag): bool => $organization === null
            ? Feature::active($flag)
            : Feature::for($organization)->active($flag);

        return ! $active('workspace.'.$feature) && $active('workspace.'.$feature.'_preview');
    }
}

if (! function_exists('workspace_files_preview_active')) {
    /**
     * True when remote files is off but the coming-soon teaser should
     * surface in nav and the preview workspace page.
     */
    function workspace_files_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.files')
            : Feature::for($organization)->active('workspace.files')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.files_preview')
            : Feature::for($organization)->active('workspace.files_preview');
    }
}

if (! function_exists('workspace_ssh_access_graph_preview_active')) {
    /**
     * True when the SSH access graph workspace is off but the coming-soon
     * teaser should surface in nav and the preview workspace page.
     */
    function workspace_ssh_access_graph_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.ssh_access_graph')
            : Feature::for($organization)->active('workspace.ssh_access_graph')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.ssh_access_graph_preview')
            : Feature::for($organization)->active('workspace.ssh_access_graph_preview');
    }
}

if (! function_exists('workspace_backups_preview_active')) {
    /**
     * True when the Backups workspace is off but the coming-soon teaser should
     * surface in nav and the preview workspace page.
     */
    function workspace_backups_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.backups')
            : Feature::for($organization)->active('workspace.backups')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.backups_preview')
            : Feature::for($organization)->active('workspace.backups_preview');
    }
}

if (! function_exists('workspace_site_cdn_preview_active')) {
    /**
     * True when the site CDN workspace is off but the coming-soon teaser should
     * surface in nav and the preview page.
     */
    function workspace_site_cdn_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.site_cdn')
            : Feature::for($organization)->active('workspace.site_cdn')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.site_cdn_preview')
            : Feature::for($organization)->active('workspace.site_cdn_preview');
    }
}

if (! function_exists('workspace_site_caching_preview_active')) {
    /**
     * True when the site caching workspace is off but the coming-soon teaser should
     * surface in nav and the preview page.
     */
    function workspace_site_caching_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.site_caching')
            : Feature::for($organization)->active('workspace.site_caching')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.site_caching_preview')
            : Feature::for($organization)->active('workspace.site_caching_preview');
    }
}

if (! function_exists('workspace_docker_preview_active')) {
    /**
     * True when the Docker workspace is off but the coming-soon teaser should
     * surface in nav and the preview workspace page.
     */
    function workspace_docker_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.docker')
            : Feature::for($organization)->active('workspace.docker')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.docker_preview')
            : Feature::for($organization)->active('workspace.docker_preview');
    }
}

if (! function_exists('workspace_server_maintenance_preview_active')) {
    /**
     * True when the server maintenance surface is off but the coming-soon
     * teaser should surface in nav and the preview workspace page.
     */
    function workspace_server_maintenance_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.server_maintenance')
            : Feature::for($organization)->active('workspace.server_maintenance')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.server_maintenance_preview')
            : Feature::for($organization)->active('workspace.server_maintenance_preview');
    }
}

if (! function_exists('workspace_security_digest_preview_active')) {
    /**
     * True when the security digest surface is off but the coming-soon teaser
     * should surface in nav and the preview workspace page.
     */
    function workspace_security_digest_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.security_digest')
            : Feature::for($organization)->active('workspace.security_digest')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.security_digest_preview')
            : Feature::for($organization)->active('workspace.security_digest_preview');
    }
}

if (! function_exists('workspace_release_hygiene_preview_active')) {
    /**
     * True when the release hygiene surface is off but the coming-soon teaser
     * should surface in nav and the preview workspace page.
     */
    function workspace_release_hygiene_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.release_hygiene')
            : Feature::for($organization)->active('workspace.release_hygiene')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.release_hygiene_preview')
            : Feature::for($organization)->active('workspace.release_hygiene_preview');
    }
}

if (! function_exists('workspace_run_preview_active')) {
    /**
     * True when the Run workspace surface is off but the coming-soon teaser
     * should surface in nav and the preview workspace page.
     */
    function workspace_run_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.run')
            : Feature::for($organization)->active('workspace.run')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.run_preview')
            : Feature::for($organization)->active('workspace.run_preview');
    }
}

if (! function_exists('workspace_shared_host_preview_active')) {
    /**
     * True when the Shared Host Radar surface is off but the coming-soon teaser
     * should surface in nav and the preview workspace page.
     */
    function workspace_shared_host_preview_active(?Organization $organization = null): bool
    {
        if ($organization === null
            ? Feature::active('workspace.shared_host')
            : Feature::for($organization)->active('workspace.shared_host')) {
            return false;
        }

        return $organization === null
            ? Feature::active('workspace.shared_host_preview')
            : Feature::for($organization)->active('workspace.shared_host_preview');
    }
}

if (! function_exists('workspace_shared_host_active')) {
    function workspace_shared_host_active(?Organization $organization = null): bool
    {
        return $organization === null
            ? Feature::active('workspace.shared_host')
            : Feature::for($organization)->active('workspace.shared_host');
    }
}

if (! function_exists('multi_surface_active')) {
    /**
     * True when the current org has at least one non-VM product surface
     * enabled (Cloud / Edge / Serverless). Used to gate the Infrastructure
     * dashboard and the Launchpad — those screens are designed to triage
     * across multiple surfaces and become noise when only Servers exist.
     *
     * Optional $organization scopes the check to a specific org (admin
     * tooling); omit to use Pennant's default scope (current org).
     */
    function multi_surface_active(?Organization $organization = null): bool
    {
        foreach (['surface.cloud', 'surface.edge', 'surface.serverless'] as $flag) {
            $active = $organization === null
                ? Feature::active($flag)
                : Feature::for($organization)->active($flag);
            if ($active) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('ephemeral_deploy_credentials_active')) {
    /**
     * True when per-deploy ephemeral SSH credentials are enabled for the org.
     */
    function ephemeral_deploy_credentials_active(?Organization $organization = null): bool
    {
        return $organization === null
            ? Feature::active('workspace.ephemeral_credentials')
            : Feature::for($organization)->active('workspace.ephemeral_credentials');
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

