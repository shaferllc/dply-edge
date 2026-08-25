<?php

namespace App\View\Components;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ErrorContext
{
    /**
     * Parse the current URL to extract resource context.
     *
     * @return array{
     *   server: Server|null,
     *   site: Site|null,
     *   organization: Organization|null,
     *   project: Project|null,
     *   referrer: string|null,
     *   suggestions: array<int, array{label: string, url: string, primary?: bool}>
     * }
     */
    public function parse(): array
    {
        $path = request()->path();
        $referrer = request()->header('referer');

        $context = [
            'server' => null,
            'site' => null,
            'organization' => null,
            'project' => null,
            'referrer' => $referrer,
            'suggestions' => [],
        ];

        // Extract server ID from URL patterns like /servers/{id} or /servers/{id}/...
        if (preg_match('#servers/([a-zA-Z0-9]+)#', $path, $matches)) {
            $serverId = $matches[1];
            $server = Server::find($serverId);

            if ($server && $this->canViewServer($server)) {
                $context['server'] = $server;
                $context['suggestions'][] = [
                    'label' => 'Edge sites',
                    'url' => route('edge.index'),
                    'primary' => true,
                ];
            } elseif ($server) {
                // Server exists but user can't view it
                $context['suggestions'][] = [
                    'label' => 'Edge sites',
                    'url' => route('edge.index'),
                ];
            }
        }

        // Extract site ID from URL patterns like /servers/{serverId}/sites/{siteId} or /sites/{id}
        if (preg_match('#sites/([a-zA-Z0-9]+)#', $path, $matches)) {
            $siteId = $matches[1];
            $site = Site::with('server')->find($siteId);

            if ($site && $this->canViewSite($site)) {
                $context['site'] = $site;

                if ($site->server) {
                    $context['server'] = $site->server;
                    $context['suggestions'][] = [
                        'label' => 'Back to site: '.($site->primaryDomain()->hostname ?? $site->name),
                        'url' => route('sites.show', [$site->server, $site]),
                        'primary' => true,
                    ];
                }
            } elseif ($site) {
                // Site exists but user can't view it
                $context['suggestions'][] = [
                    'label' => 'Edge sites',
                    'url' => route('edge.index'),
                ];
            }
        }

        // Extract organization ID from URL patterns
        if (preg_match('#organizations/([a-zA-Z0-9]+)#', $path, $matches)) {
            $orgId = $matches[1];
            $organization = Organization::find($orgId);

            if ($organization && $this->canViewOrganization($organization)) {
                $context['organization'] = $organization;
                $context['suggestions'][] = [
                    'label' => "Back to {$organization->name}",
                    'url' => route('organizations.show', $organization),
                    'primary' => true,
                ];
            } elseif ($organization) {
                $context['suggestions'][] = [
                    'label' => 'My organizations',
                    'url' => route('organizations.index'),
                ];
            }
        }

        // Extract project ID from URL patterns
        if (preg_match('#projects/([a-zA-Z0-9]+)#', $path, $matches)) {
            $projectId = $matches[1];
            $project = Project::find($projectId);

            if ($project && $this->canViewProject($project)) {
                $context['project'] = $project;
            }
        }

        // Add fallback suggestions if no specific context found
        if (empty($context['suggestions'])) {
            $context['suggestions'] = $this->getDefaultSuggestions();
        }

        return $context;
    }

    /**
     * Get default navigation suggestions when no specific context is found.
     *
     * @return array<int, array{label: string, url: string, primary?: bool}>
     */
    protected function getDefaultSuggestions(): array
    {
        $suggestions = [];

        if (Auth::check()) {
            $suggestions[] = [
                'label' => 'Dashboard',
                'url' => route('dashboard'),
                'primary' => true,
            ];
            $suggestions[] = [
                'label' => 'Edge sites',
                'url' => route('edge.index'),
            ];
        } else {
            $suggestions[] = [
                'label' => 'Home',
                'url' => url('/'),
                'primary' => true,
            ];
        }

        return $suggestions;
    }

    protected function canViewServer(Server $server): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return Gate::allows('view', $server);
    }

    protected function canViewSite(Site $site): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return Gate::allows('view', $site);
    }

    protected function canViewOrganization(Organization $organization): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return Gate::allows('view', $organization);
    }

    protected function canViewProject(Project $project): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return $project->user_id === $user->id ||
            ($project->organization_id && $project->organization?->hasMember($user));
    }
}
