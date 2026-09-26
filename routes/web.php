<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\CliInstallController;
use App\Http\Controllers\Credentials\ProviderOAuthController;
use App\Http\Controllers\Notifications\DiscordOAuthController;
use App\Http\Controllers\Notifications\SlackOAuthController;
use App\Http\Controllers\Notifications\TelegramWebhookController;
use App\Http\Controllers\OrganizationComplianceExportController;
use App\Http\Controllers\SiteWorkspaceController;
use App\Livewire\Admin\AuditLog as AdminAuditLog;
use App\Livewire\Admin\BetaInvites as AdminBetaInvites;
use App\Livewire\Admin\ComingSoonAccess as AdminComingSoonAccess;
use App\Livewire\Admin\Connections as AdminConnections;
use App\Livewire\Admin\Operations as AdminOperations;
use App\Livewire\Admin\Organizations\Index as AdminOrganizationsIndex;
use App\Livewire\Admin\Organizations\Show as AdminOrganizationsShow;
use App\Livewire\Admin\Overview as AdminOverview;
use App\Livewire\Admin\Users\Index;
use App\Livewire\Auth\DeviceApproval as AuthDeviceApproval;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Invitations\Accept as InvitationsAccept;
use App\Livewire\Marketing\ComingSoonSignup as MarketingComingSoonSignup;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Livewire\Organizations\Activity as OrganizationsActivity;
use App\Livewire\Organizations\Create as OrganizationsCreate;
use App\Livewire\Organizations\Index as OrganizationsIndex;
use App\Livewire\Organizations\Members as OrganizationsMembers;
use App\Livewire\Organizations\NotificationChannels as OrganizationsNotificationChannels;
use App\Livewire\Organizations\Settings as OrganizationsSettings;
use App\Livewire\Organizations\Show as OrganizationsShow;
use App\Livewire\Organizations\Teams as OrganizationsTeams;
use App\Livewire\Profile\DeleteAccount as ProfileDeleteAccount;
use App\Livewire\Settings\ApiKeys as SettingsApiKeys;
use App\Livewire\Settings\BulkNotificationAssignments;
use App\Livewire\Settings\CliAuthentications as SettingsCliAuthentications;
use App\Livewire\Settings\Hub as SettingsHub;
use App\Livewire\Settings\NotificationChannels as SettingsNotificationChannels;
use App\Livewire\Settings\Security as SettingsSecurity;
use App\Livewire\Settings\SourceControl as SettingsSourceControl;
use App\Livewire\Sites\EdgeDeploymentDetail;
use App\Livewire\Sites\EdgePreviewComments;
use App\Livewire\Status\PublicPage as StatusPublicPage;
use App\Livewire\StatusPages\Index as StatusPagesIndex;
use App\Livewire\StatusPages\Manage as StatusPagesManage;
use App\Livewire\Teams\NotificationChannels as TeamsNotificationChannels;
use App\Livewire\TwoFactor\Page as TwoFactorPage;
use App\Modules\Billing\Livewire\Show as BillingShow;
use App\Modules\Edge\Http\Controllers\EdgeAuditLogExportController;
use App\Modules\Edge\Http\Controllers\EdgeDeliveryUsageHookController;
use App\Modules\Edge\Http\Controllers\EdgeDeployHookController;
use App\Modules\Edge\Http\Controllers\EdgeLiveAccessLogPollController;
use App\Modules\Edge\Http\Controllers\EdgeLogCsvDownloadController;
use App\Modules\Edge\Http\Controllers\EdgePreviewAccessController;
use App\Modules\Edge\Http\Controllers\EdgePreviewCommentsController;
use App\Modules\Edge\Http\Controllers\EdgeRepoConfigYamlDownloadController;
use App\Modules\Edge\Http\Controllers\GithubEdgeWebhookController;
use App\Modules\Edge\Livewire\Create as EdgeCreate;
use App\Modules\Edge\Livewire\Databases;
use App\Modules\Edge\Livewire\Import;
use App\Modules\Edge\Livewire\Index as EdgeIndex;
use App\Modules\Edge\Livewire\Queues;
use App\Modules\Edge\Livewire\Templates;
use App\Modules\Edge\Livewire\Usage;
use App\Modules\Secrets\Livewire\Secrets as OrganizationsSecrets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['web', 'auth']]);

// Standalone diagnostic page for Redis-backend failures. Lives outside the
// `web` middleware group on purpose — StartSession/CSRF all touch
// Cache, so if Redis is down a normal route would recurse on the very error
// it tries to render. Reads only the config repository, which is an in-memory
// array by this point — no Cache, no Redis, no DB.
Route::get('/_redis-unreachable', function () {
    return response()->view('errors.redis-unreachable', [
        'message' => __('Connection timed out — see config block below.'),
        'host' => (string) config('database.redis.default.host', '127.0.0.1'),
        'port' => (string) config('database.redis.default.port', '6379'),
        'cacheStore' => (string) config('cache.default', 'database'),
        'queueConnection' => (string) config('queue.default', 'sync'),
        'timeout' => (string) config('database.redis.default.timeout', '2.0'),
    ], 503);
})->withoutMiddleware(['web']);

Route::post('/hooks/edge/{site}/delivery', EdgeDeliveryUsageHookController::class)
    ->middleware(['throttle:site-webhook'])
    ->name('hooks.edge.delivery');

Route::match(['post', 'options'], '/hooks/edge/{site}/github', GithubEdgeWebhookController::class)
    ->middleware(['throttle:site-webhook'])
    ->name('hooks.edge.github');

// Telegram bot updates. Under /hooks/* so MachineCallbackPaths exempts it from
// CSRF and the guest gates in one place. Authenticated by the secret-token
// header Telegram echoes, checked inside the controller.
Route::post('/hooks/telegram', TelegramWebhookController::class)
    ->middleware(['throttle:site-webhook'])
    ->name('hooks.telegram');

// Per-site deploy hooks (P10b). Match POST + GET so CMSes that only
// emit GET pings (Sanity, some Webflow integrations) still work.
// Rate-limit by IP via the cheap default throttle to slow brute-force.
Route::match(['get', 'post'], '/hooks/edge/deploy/{token}', EdgeDeployHookController::class)
    ->middleware(['throttle:60,1'])
    ->where('token', '[A-Za-z0-9]{16,64}')
    ->name('hooks.edge.deploy');

// Preview-comment widget REST endpoints. Public (no Laravel session);
// auth is per-parent widget token in X-Dply-Preview-Widget. CORS is
// echoed for testing-domain origins.
Route::match(['options'], '/api/edge/preview-comments/{site}', [EdgePreviewCommentsController::class, 'options']);
Route::get('/api/edge/preview-comments/{site}', [EdgePreviewCommentsController::class, 'index'])
    ->middleware(['throttle:function-log-ingest'])
    ->name('api.edge.preview-comments.index');
Route::post('/api/edge/preview-comments/{site}', [EdgePreviewCommentsController::class, 'store'])
    ->middleware(['throttle:function-log-ingest'])
    ->name('api.edge.preview-comments.store');

Route::get('/', function () {
    // The animated homepage is THE homepage — no classic/animated switching.
    return view('welcome-v2');
});

Route::get('/pricing', function () {
    return view('pricing');
})->name('pricing');

Route::get('/features', function () {
    return view('features');
})->name('features');

Route::get('/deploy', function (Request $request) {
    $allowed = ['repo', 'branch', 'name', 'runtime_mode', 'build_command', 'output_dir'];
    $query = array_filter(
        $request->only($allowed),
        static fn ($v): bool => is_string($v) && $v !== '',
    );

    return redirect()->route('edge.create', $query);
})->name('deploy.shortlink');

Route::livewire('/coming-soon', MarketingComingSoonSignup::class)
    ->name('coming-soon');

Route::livewire('/status/{statusPage}', StatusPublicPage::class)
    ->middleware(['throttle:120,1'])
    ->name('status.public');

Route::prefix('cli')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/install.sh', [CliInstallController::class, 'installScript'])->name('cli.install');
    Route::get('/dply-cli.tgz', [CliInstallController::class, 'packageTarball'])->name('cli.package');
    Route::get('/version.json', [CliInstallController::class, 'packageVersion'])->name('cli.version');
});

// Public on purpose: an invited person usually has no account yet. The page
// shows who invited them and routes to register/login, and only joins the org
// once they're signed in as the invited address (see Invitations\Accept).
Route::livewire('invitations/accept/{token}', InvitationsAccept::class)
    ->middleware('throttle:30,1')
    ->name('invitations.accept');

Route::middleware(['auth', 'verified', 'org'])->group(function () {
    // One product surface: the app list lives at /dashboard (route name
    // dashboard). /projects redirects there. Create, import, and the other
    // project tools stay under /projects/* with edge.* names. Not /apps or
    // /applications — nginx `^~ /app` on the public vhost swallows those.
    // OAuth-style device-flow approval page for the dply CLI. The CLI
    // prints a short code; user lands here (deep link or paste),
    // confirms scopes + org, and we mint an ApiToken that the polling
    // CLI picks up exactly once via /api/v1/auth/device/poll.
    Route::livewire('/auth/device', AuthDeviceApproval::class)->name('auth.device.show');
    Route::get('/projects/sites/{site}/preview-access', EdgePreviewAccessController::class)
        ->name('edge.preview-access');
    Route::permanentRedirect('/apps/sites/{site}/preview-access', '/projects/sites/{site}/preview-access');
    Route::permanentRedirect('/applications/sites/{site}/preview-access', '/projects/sites/{site}/preview-access');

    Route::prefix('admin')
        ->middleware('can:viewPlatformAdmin')
        ->name('admin.')
        ->group(function (): void {
            Route::livewire('/', AdminOverview::class)->name('overview');
            Route::livewire('/operations', AdminOperations::class)->name('operations');
            Route::livewire('/audit', AdminAuditLog::class)->name('audit');
            Route::livewire('/users', Index::class)->name('users.index');
            Route::post('/impersonate/{user}', [ImpersonationController::class, 'start'])->name('impersonate.start');
            Route::livewire('/organizations', AdminOrganizationsIndex::class)->name('organizations.index');
            Route::livewire('/organizations/{organization}', AdminOrganizationsShow::class)->name('organizations.show');
            Route::livewire('/beta-invites', AdminBetaInvites::class)->name('beta-invites');
            Route::livewire('/coming-soon-access', AdminComingSoonAccess::class)->name('coming-soon-access');
            Route::livewire('/connections', AdminConnections::class)->name('connections');
        });
    Route::redirect('/admin/dashboard', '/admin')->middleware('can:viewPlatformAdmin')->name('admin.dashboard');

    Route::redirect('/settings', '/settings/profile')->name('settings.index');
    Route::livewire('/settings/profile', SettingsHub::class)->name('settings.profile');
    Route::livewire('/notifications', NotificationsIndex::class)->name('notifications.index');

    Route::livewire('/profile/security', SettingsSecurity::class)->name('profile.security');
    Route::livewire('/profile/source-control', SettingsSourceControl::class)->name('profile.source-control');
    Route::livewire('/profile/api-keys', SettingsApiKeys::class)->name('profile.api-keys');
    Route::livewire('/profile/cli', SettingsCliAuthentications::class)->name('profile.cli');
    Route::livewire('/profile/notification-channels', SettingsNotificationChannels::class)->name('profile.notification-channels');
    Route::livewire('/profile/notification-channels/bulk-assign', BulkNotificationAssignments::class)->name('profile.notification-channels.bulk-assign');
    Route::livewire('/profile/delete-account', ProfileDeleteAccount::class)->name('profile.delete-account');

    Route::livewire('/profile/two-factor', TwoFactorPage::class)->name('two-factor.setup');

    Route::livewire('organizations', OrganizationsIndex::class)->name('organizations.index');
    Route::livewire('organizations/create', OrganizationsCreate::class)->name('organizations.create');
    Route::livewire('organizations/{organization}', OrganizationsShow::class)->name('organizations.show');
    Route::livewire('organizations/{organization}/settings', OrganizationsSettings::class)->name('organizations.settings');
    Route::livewire('organizations/{organization}/members', OrganizationsMembers::class)->name('organizations.members');
    Route::livewire('organizations/{organization}/teams', OrganizationsTeams::class)->name('organizations.teams');
    Route::livewire('organizations/{organization}/activity', OrganizationsActivity::class)->name('organizations.activity');
    Route::get('organizations/{organization}/compliance-export', OrganizationComplianceExportController::class)->name('organizations.compliance-export');
    // The Automation & API tab folded into organization settings (2026-08).
    // Keeps bookmarks and any missed route() call alive; the settings page
    // carries the same sections under the same anchors.
    Route::redirect('organizations/{organization}/automation', 'organizations/{organization}/settings')
        ->name('organizations.automation');

    Route::livewire('organizations/{organization}/notification-channels', OrganizationsNotificationChannels::class)->name('organizations.notification-channels');
    Route::livewire('organizations/{organization}/teams/{team}/notification-channels', TeamsNotificationChannels::class)->name('teams.notification-channels');
    Route::livewire('organizations/{organization}/billing', BillingShow::class)->name('billing.show');
    Route::redirect('organizations/{organization}/billing/analytics', 'organizations/{organization}/billing')
        ->name('billing.analytics');
    Route::livewire('organizations/{organization}/subscription', BillingShow::class)->name('subscription.show');
    Route::redirect('organizations/{organization}/invoices', 'organizations/{organization}/billing')
        ->name('billing.invoices');

    Route::livewire('organizations/{organization}/credentials', CredentialsIndex::class)->name('organizations.credentials');
    // Session-scoped shortcut into the current org's credentials page. Kept as
    // its own name because the OAuth callbacks and the CLI land here without an
    // organization in hand.
    Route::get('credentials', function () {
        $organization = auth()->user()?->currentOrganization();
        abort_unless($organization !== null, 404);

        return redirect()->route('organizations.credentials', ['organization' => $organization] + request()->query());
    })->name('credentials.index');
    Route::livewire('organizations/{organization}/secrets', OrganizationsSecrets::class)->name('organizations.secrets');

    Route::permanentRedirect('projects', '/dashboard');
    Route::livewire('dashboard', EdgeIndex::class)->name('dashboard');
    Route::livewire('projects/create', EdgeCreate::class)->name('edge.create');
    Route::livewire('projects/import', Import::class)->name('edge.import');
    Route::livewire('projects/templates', Templates::class)->name('edge.templates');
    Route::livewire('projects/usage', Usage::class)->name('edge.usage');
    Route::livewire('projects/databases', Databases::class)->name('edge.databases');
    Route::livewire('projects/queues', Queues::class)->name('edge.queues');

    /*
     * Legacy /edge/*, /apps/*, /applications/* URLs. The list moved to
     * /dashboard. Nested tools stay under /projects/*. /apps and
     * /applications collide with Reverb (`^~ /app`).
     */
    Route::permanentRedirect('/edge', '/dashboard');
    Route::permanentRedirect('/edge/{path}', '/projects/{path}')->where('path', '.*');
    Route::permanentRedirect('/apps', '/dashboard');
    Route::permanentRedirect('/apps/{path}', '/projects/{path}')->where('path', '.*');
    Route::permanentRedirect('/applications', '/dashboard');
    Route::permanentRedirect('/applications/{path}', '/projects/{path}')->where('path', '.*');

    Route::livewire('status-pages', StatusPagesIndex::class)->name('status-pages.index');
    Route::livewire('status-pages/{statusPage}', StatusPagesManage::class)->name('status-pages.manage');

    // Edge site workspace. Every edge site hangs off a placeholder `Server`
    // row (host_kind=dply_edge) created with it. The public path is
    // /projects/{site}; /servers/… redirects here.
    Route::livewire('projects/{site}/edge/deployments/{deployment}', EdgeDeploymentDetail::class)->name('sites.edge.deployments.show');
    Route::livewire('projects/{site}/preview-comments', EdgePreviewComments::class)->name('sites.preview-comments');

    // Edge access log CSV download — session-authed (Gate view-checked
    // inside the controller) so the dashboard "Download CSV" button
    // works without minting an API token. Stays out of the section
    // dispatcher because the .csv extension wouldn't match.
    Route::get('projects/{site}/edge/logs.csv', EdgeLogCsvDownloadController::class)
        ->name('sites.edge.logs.csv');

    Route::get('projects/{site}/edge/logs/live.json', EdgeLiveAccessLogPollController::class)
        ->name('sites.edge.logs.live');

    // Per-site audit-log export (CSV/JSON) — session-authed, no row cap,
    // mirrors the on-screen Audit log panel filters.
    Route::get('projects/{site}/edge/audit.export', EdgeAuditLogExportController::class)
        ->name('sites.edge.audit.export');

    // Generate dply.yaml from the site's current declarative config
    // (redirects / rewrites / headers / crons). Lets a user export
    // dashboard-managed state to a repo-checked file.
    Route::get('projects/{site}/edge/dply.yaml', EdgeRepoConfigYamlDownloadController::class)
        ->name('sites.edge.dply-yaml');

    Route::get('projects/{site}/{section?}', SiteWorkspaceController::class)
        ->where('section', '[a-z0-9-]+')
        ->defaults('section', 'general')
        ->missing(fn () => redirect()->route('dashboard'))
        ->name('sites.show');

    Route::get('credentials/oauth/digitalocean', [ProviderOAuthController::class, 'redirectDigitalOcean'])
        ->name('credentials.oauth.digitalocean.redirect');
    Route::get('credentials/oauth/digitalocean/callback', [ProviderOAuthController::class, 'callbackDigitalOcean'])
        ->name('credentials.oauth.digitalocean.callback');

    // "Add to Slack" — one bot token per workspace, backing many notification channels.
    Route::get('notifications/oauth/slack', [SlackOAuthController::class, 'redirect'])
        ->name('notifications.oauth.slack.redirect');
    Route::get('notifications/oauth/slack/callback', [SlackOAuthController::class, 'callback'])
        ->name('notifications.oauth.slack.callback');

    // "Add to Discord" — the dply bot joined to a guild, backing many channels.
    Route::get('notifications/oauth/discord', [DiscordOAuthController::class, 'redirect'])
        ->name('notifications.oauth.discord.redirect');
    Route::get('notifications/oauth/discord/callback', [DiscordOAuthController::class, 'callback'])
        ->name('notifications.oauth.discord.callback');
});

Route::get('projects/{project}/sites/{site}/{path?}', function (string $project, string $site, ?string $path = null) {
    $target = '/projects/'.$site.(($path ?? '') !== '' ? '/'.$path : '');
    $query = request()->getQueryString();
    if (is_string($query) && $query !== '') {
        $target .= '?'.$query;
    }

    return redirect($target, 301);
})->where('path', '.*');

Route::get('servers/{path}', function (string $path) {
    if (preg_match('#^[^/]+/sites/([^/]+)(?:/(.*))?$#', $path, $matches) === 1) {
        $target = '/projects/'.$matches[1].((isset($matches[2]) && $matches[2] !== '') ? '/'.$matches[2] : '');
    } else {
        $target = '/projects/'.$path;
    }
    $query = request()->getQueryString();
    if (is_string($query) && $query !== '') {
        $target .= '?'.$query;
    }

    return redirect($target, 301);
})->where('path', '.*');

require __DIR__.'/auth.php';
