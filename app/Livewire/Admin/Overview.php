<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Admin\AdminPlatformMetrics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Overview extends Component
{
    use AuthorizesPlatformAdmin;

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
    }

    public function render(): View
    {
        $this->authorizePlatformAdmin();

        $counts = AdminPlatformMetrics::counts();
        $system = AdminPlatformMetrics::system();

        return view('livewire.admin.overview', [
            'counts' => $counts,
            'system' => $system,
            'serverByStatus' => AdminPlatformMetrics::serverByStatus(),
            'siteByStatus' => AdminPlatformMetrics::siteByStatus(),
            'topOrganizations' => Organization::query()
                ->withCount('servers')
                ->orderByDesc('servers_count')
                ->limit(6)
                ->get(['id', 'name', 'servers_count']),
            'recentAuditLogs' => AuditLog::query()
                ->with(['subject', 'user:id,name,email', 'organization:id,name'])
                ->latest('created_at')
                ->limit(8)
                ->get(),
            'recentUsers' => User::query()
                ->latest('created_at')
                ->limit(6)
                ->get(['id', 'name', 'email', 'created_at']),
            'healthIssues' => $this->healthIssues($counts, $system),
        ]);
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $system
     * @return list<string>
     */
    protected function healthIssues(array $counts, array $system): array
    {
        $issues = [];

        if (! $system['db_ok']) {
            $issues[] = __('Database unreachable');
        }

        if ($system['redis_ok'] === false) {
            $issues[] = __('Redis ping failed');
        }

        if ($system['maintenance']) {
            $issues[] = __('Laravel maintenance mode is on');
        }

        if ($counts['failed_jobs'] > 0) {
            $issues[] = __(':count failed queue jobs', ['count' => number_format($counts['failed_jobs'])]);
        }

        return $issues;
    }
}
