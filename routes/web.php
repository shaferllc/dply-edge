<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\CliInstallController;
use App\Http\Controllers\Credentials\ProviderOAuthController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\EdgeDeployPillController;
use App\Http\Controllers\Notifications\DiscordOAuthController;
use App\Http\Controllers\Notifications\SlackOAuthController;
use App\Http\Controllers\Notifications\TelegramWebhookController;
use App\Http\Controllers\OrganizationComplianceExportController;
use App\Http\Controllers\SiteWorkspaceController;
use App\Livewire\Admin\AuditLog as AdminAuditLog;
use App\Livewire\Admin\BetaInvites as AdminBetaInvites;
use App\Livewire\Admin\ComingSoonAccess as AdminComingSoonAccess;
use App\Livewire\Admin\Connections as AdminConnections;
use App\Livewire\Admin\FeatureFlags as AdminFeatureFlags;
use App\Livewire\Admin\Operations as AdminOperations;
use App\Livewire\Admin\Organizations\Index as AdminOrganizationsIndex;
use App\Livewire\Admin\Organizations\Show as AdminOrganizationsShow;
use App\Livewire\Admin\Overview as AdminOverview;
use App\Livewire\Admin\Users\Index;
use App\Livewire\Auth\DeviceApproval as AuthDeviceApproval;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Invitations\Accept as InvitationsAccept;
use App\Livewire\Legal\AcceptTerms;
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
use App\Modules\Edge\Http\Controllers\EdgeDeployHookController;
use App\Modules\Edge\Http\Controllers\EdgeFormIngestController;
use App\Modules\Edge\Http\Controllers\EdgeLiveAccessLogPollController;
use App\Modules\Edge\Http\Controllers\EdgeLogCsvDownloadController;
use App\Modules\Edge\Http\Controllers\EdgeMeterIngestController;
use App\Modules\Edge\Http\Controllers\EdgePreviewAccessController;
use App\Modules\Edge\Http\Controllers\EdgePreviewCommentsController;
use App\Modules\Edge\Http\Controllers\EdgeRepoConfigYamlDownloadController;
use App\Modules\Edge\Http\Controllers\GithubEdgeWebhookController;
use App\Modules\Edge\Livewire\Buckets;
use App\Modules\Edge\Livewire\Create as EdgeCreate;
use App\Modules\Edge\Livewire\Databases;
use App\Modules\Edge\Livewire\Import;
use App\Modules\Edge\Livewire\Index as EdgeIndex;
use App\Modules\Edge\Livewire\Messages;
use App\Modules\Edge\Livewire\Queues;
use App\Modules\Edge\Livewire\Templates;
use App\Modules\Edge\Livewire\Usage;
use App\Modules\Secrets\Livewire\Secrets as OrganizationsSecrets;
use App\Support\Docs\DocsSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Head\Facades\Head;
use Laravel\Head\Facades\Schema;

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

// Logos and org icons. On R2 (a container has no durable disk) the
// framework's `serve` route does not exist — it is local-only — so stream
// the same /site-assets URLs from the bucket. Public files; no session.
if (config('filesystems.disks.site_assets.driver') !== 'local') {
    Route::get('/site-assets/{path}', function (string $path) {
        // The s3 adapter's url() includes the disk root; the disk adds it again.
        $root = trim((string) config('filesystems.disks.site_assets.root'), '/');
        $path = $root !== '' && str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : $path;
        abort_if(str_contains($path, '..') || ! Storage::disk('site_assets')->exists($path), 404);

        return Storage::disk('site_assets')->response($path, null, ['Cache-Control' => 'public, max-age=86400']);
    })->where('path', '[A-Za-z0-9._/-]+')->withoutMiddleware(['web'])->name('site-assets.stream');
}

Route::match(['post', 'options'], '/hooks/edge/{site}/github', GithubEdgeWebhookController::class)
    ->middleware(['throttle:site-webhook'])
    ->name('hooks.edge.github');

// Telegram bot updates. Under /hooks/* so MachineCallbackPaths exempts it from
// CSRF and the guest gates in one place. Authenticated by the secret-token
// header Telegram echoes, checked inside the controller.
Route::post('/hooks/telegram', TelegramWebhookController::class)
    ->middleware(['throttle:site-webhook'])
    ->name('hooks.telegram');

// Edge Forms: the Worker forwards screened submissions here, HMAC-signed with
// a per-app key (EdgeFormIngestController::keyFor).
Route::post('/hooks/edge/{site}/forms', EdgeFormIngestController::class)
    ->middleware(['throttle:function-log-ingest'])
    ->name('hooks.edge.forms');

// AI / browser / vector search proxy (EdgeMeter): usage reports and the
// cap / kill-switch verdict, HMAC-signed with a per-app key.
Route::post('/hooks/edge/{site}/meter', EdgeMeterIngestController::class)
    ->middleware(['throttle:edge-meter'])
    ->name('hooks.edge.meter');

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
    // Google needs a raster logo (no SVG), at least 112px: the 512px app icon.
    Head::schema(Schema::organization()->name('dply')->url(url('/'))->logo(asset('android-chrome-512x512.png'))->email(config('dply.support_email')))
        ->schema(Schema::webSite()->name('dply')->url(url('/')));

    // The animated homepage is THE homepage — no classic/animated switching.
    return view('welcome-v2');
})->withHead(
    title: ['value' => 'dply · Deploy Laravel, Rails & Node apps from Git', 'exact' => true],
    description: 'Deploy Laravel, Rails and Node apps and static sites from a Git push, with managed Postgres, MySQL, Valkey and queue workers alongside. One bill, no servers.',
);

Route::get('/pricing', function () {
    return view('pricing');
})->name('pricing'); // title, description + schemas: pricing.blade.php

Route::get('/features', function () {
    return view('features');
})->name('features')->withHead(
    title: 'Features: Laravel, Rails & Node hosting',
    description: 'Run Laravel, Rails and Node apps from Git with managed Postgres, MySQL, Valkey and autoscaling queue workers, previews per branch, and apps that sleep when idle.',
);

Route::view('/compliance', 'compliance')->name('compliance')->withHead(
    title: 'Security & compliance',
    description: 'Where dply runs your apps and data, how it encrypts and isolates them, which subprocessors it uses, and how to report a vulnerability.',
);
Route::redirect('/security', '/compliance', 301);

// Legal documents (resources/views/legal, config/legal.php). Accepting them is required at sign-up
// and again when legal.version changes (EnsureCurrentTermsAccepted).
Route::view('/terms', 'legal.terms')->name('legal.terms')->withHead(title: 'Terms of Service', description: 'The terms for using dply, operated by Shafer LLC.');
Route::view('/privacy', 'legal.privacy')->name('legal.privacy')->withHead(title: 'Privacy Policy', description: 'What personal data dply collects, why, who it is shared with, and your rights.');
Route::view('/acceptable-use', 'legal.acceptable-use')->name('legal.acceptable-use')->withHead(title: 'Acceptable Use Policy', description: 'What you may not host or do on dply, how copyright (DMCA) notices work, and how we enforce.');
Route::view('/dpa', 'legal.dpa')->name('legal.dpa')->withHead(title: 'Data Processing Addendum', description: 'dply\'s GDPR Article 28 data processing terms, standard contractual clauses and sub-processors.');
Route::livewire('/legal/accept', AcceptTerms::class)->middleware('auth')->name('legal.accept')->withHead(robots: 'noindex, nofollow');

Route::get('/vs/{competitor}', fn (string $competitor) => view('compare', ['competitor' => $competitor]))
    ->whereIn('competitor', ['forge', 'laravel-cloud', 'heroku'])
    ->name('compare');

// RFC 9116. A route, not a file in public/, so the contact comes from
// config('dply.security_email'). Bump Expires at least annually.
$securityTxt = fn () => response(implode("\n", [
    'Contact: mailto:'.config('dply.security_email'),
    'Expires: 2027-09-27T00:00:00.000Z',
    'Preferred-Languages: en',
    'Canonical: '.url('/.well-known/security.txt'),
    'Policy: '.route('compliance').'#disclosure',
]).
"\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
Route::get('/.well-known/security.txt', $securityTxt)->name('security-txt');
Route::get('/security.txt', $securityTxt);

// Public docs, rendered from docs/site/*.md (nav.json is the allow-list).
// Slugs are [a-z0-9/-] only, so `..`, `.md` and `.txt` never reach `show`.
Route::redirect('/docs', '/docs/introduction')->name('docs.index');
Route::get('/docs/llms.txt', [DocsController::class, 'llms'])->name('docs.llms');
Route::get('/llms.txt', [DocsController::class, 'llms'])->name('llms');
Route::get('/docs/search.json', [DocsController::class, 'search'])->name('docs.search');
Route::get('/docs/{slug}.md', [DocsController::class, 'markdown'])
    ->where('slug', '[a-z0-9/-]+')
    ->name('docs.markdown');
Route::get('/docs/{slug}', [DocsController::class, 'show'])
    ->where('slug', '[a-z0-9/-]+')
    ->name('docs.show');

Route::get('/sitemap.xml', function (DocsSite $docs) {
    return response()->view('sitemap', ['docs' => array_filter($docs->pages(), fn ($p) => $p['exists'])])
        ->header('Content-Type', 'application/xml');
})->name('sitemap');

Route::get('/deploy', function (Request $request) {
    $allowed = ['repo', 'branch', 'name', 'runtime_mode', 'build_command', 'output_dir'];
    $query = array_filter(
        $request->only($allowed),
        static fn ($v): bool => is_string($v) && $v !== '',
    );

    return redirect()->route('edge.create', $query);
})->name('deploy.shortlink');

Route::livewire('/coming-soon', MarketingComingSoonSignup::class)
    ->name('coming-soon')
    ->withHead(
        title: 'Early access',
        description: 'Join the dply edge waitlist. Deploy Laravel, Rails and Node apps, with their databases and queue workers, straight from Git.',
    );

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
    ->name('invitations.accept')
    ->withHead(title: 'Invitation', robots: 'noindex, nofollow');

// Signed-in app: never indexed. Pages name themselves — static titles here,
// runtime Head::title() in the component when the title names a record.
Route::middleware(['auth', 'verified', 'org'])->withHead(robots: 'noindex, nofollow')->group(function () {
    // One product surface: the app list lives at /dashboard (route name
    // dashboard). /projects redirects there. Create, import, and the other
    // project tools stay under /projects/* with edge.* names. Not /apps or
    // /applications — nginx `^~ /app` on the public vhost swallows those.
    // OAuth-style device-flow approval page for the dply CLI. The CLI
    // prints a short code; user lands here (deep link or paste),
    // confirms scopes + org, and we mint an ApiToken that the polling
    // CLI picks up exactly once via /api/v1/auth/device/poll.
    Route::livewire('/auth/device', AuthDeviceApproval::class)->name('auth.device.show')->withHead(title: 'Authorize the CLI');
    Route::get('/projects/sites/{site}/preview-access', EdgePreviewAccessController::class)
        ->name('edge.preview-access');
    Route::permanentRedirect('/apps/sites/{site}/preview-access', '/projects/sites/{site}/preview-access');
    Route::permanentRedirect('/applications/sites/{site}/preview-access', '/projects/sites/{site}/preview-access');

    Route::prefix('admin')
        ->middleware('can:viewPlatformAdmin')
        ->name('admin.')
        ->group(function (): void {
            Route::livewire('/', AdminOverview::class)->name('overview')->withHead(title: 'Admin · Overview');
            Route::livewire('/operations', AdminOperations::class)->name('operations')->withHead(title: 'Admin · Operations');
            Route::livewire('/audit', AdminAuditLog::class)->name('audit')->withHead(title: 'Admin · Audit log');
            Route::livewire('/users', Index::class)->name('users.index')->withHead(title: 'Admin · Users');
            Route::post('/impersonate/{user}', [ImpersonationController::class, 'start'])->name('impersonate.start');
            Route::livewire('/organizations', AdminOrganizationsIndex::class)->name('organizations.index')->withHead(title: 'Admin · Organizations');
            Route::livewire('/organizations/{organization}', AdminOrganizationsShow::class)->name('organizations.show');
            Route::livewire('/beta-invites', AdminBetaInvites::class)->name('beta-invites')->withHead(title: 'Admin · Beta invites');
            Route::livewire('/coming-soon-access', AdminComingSoonAccess::class)->name('coming-soon-access')->withHead(title: 'Admin · Coming-soon access');
            Route::livewire('/connections', AdminConnections::class)->name('connections')->withHead(title: 'Admin · Connections');
            Route::livewire('/feature-flags', AdminFeatureFlags::class)->name('feature-flags')->withHead(title: 'Admin · Feature flags');
        });
    Route::redirect('/admin/dashboard', '/admin')->middleware('can:viewPlatformAdmin')->name('admin.dashboard');

    Route::redirect('/settings', '/settings/profile')->name('settings.index');
    Route::livewire('/settings/profile', SettingsHub::class)->name('settings.profile')->withHead(title: 'Profile');
    Route::livewire('/notifications', NotificationsIndex::class)->name('notifications.index')->withHead(title: 'Notifications');

    Route::livewire('/profile/security', SettingsSecurity::class)->name('profile.security')->withHead(title: 'Security');
    Route::livewire('/profile/source-control', SettingsSourceControl::class)->name('profile.source-control')->withHead(title: 'Source control');
    Route::livewire('/profile/api-keys', SettingsApiKeys::class)->name('profile.api-keys')->withHead(title: 'API keys');
    Route::livewire('/profile/cli', SettingsCliAuthentications::class)->name('profile.cli')->withHead(title: 'CLI sessions');
    Route::livewire('/profile/notification-channels', SettingsNotificationChannels::class)->name('profile.notification-channels')->withHead(title: 'Notification channels');
    Route::livewire('/profile/notification-channels/bulk-assign', BulkNotificationAssignments::class)->name('profile.notification-channels.bulk-assign')->withHead(title: 'Bulk notification assignments');
    Route::livewire('/profile/delete-account', ProfileDeleteAccount::class)->name('profile.delete-account')->withHead(title: 'Delete account');

    Route::livewire('/profile/two-factor', TwoFactorPage::class)->name('two-factor.setup')->withHead(title: 'Two-factor authentication');

    Route::livewire('organizations', OrganizationsIndex::class)->name('organizations.index')->withHead(title: 'Organizations');
    Route::livewire('organizations/create', OrganizationsCreate::class)->name('organizations.create')->withHead(title: 'New organization');
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
    Route::livewire('dashboard', EdgeIndex::class)->name('dashboard')->withHead(title: 'Dashboard');
    Route::livewire('projects/create', EdgeCreate::class)->name('edge.create')->withHead(title: 'New project');
    Route::livewire('projects/import', Import::class)->name('edge.import')->withHead(title: 'Import a project');
    Route::livewire('projects/templates', Templates::class)->name('edge.templates')->withHead(title: 'Templates');
    Route::livewire('projects/usage', Usage::class)->name('edge.usage')->withHead(title: 'Usage');
    Route::livewire('projects/databases', Databases::class)->name('edge.databases')->withHead(title: 'Databases');
    Route::livewire('projects/queues', Queues::class)->name('edge.queues')->withHead(title: 'Queues');
    Route::livewire('projects/storage', Buckets::class)->name('edge.buckets')->withHead(title: 'Storage');
    // Behind the resource-messages flag (Messages::mount 404s without it).
    Route::livewire('projects/messages', Messages::class)->name('edge.messages')->withHead(title: 'Messages');

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

    Route::livewire('status-pages', StatusPagesIndex::class)->name('status-pages.index')->withHead(title: 'Status pages');
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

    // The deploy pill on every page (resources/js/deploy-pill.js).
    Route::prefix('deploy-pill')->name('deploy-pill.')->controller(EdgeDeployPillController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('{deployment}/tail', 'tail')->name('tail');
        Route::post('{deployment}/cancel', 'cancel')->name('cancel');
        Route::post('{deployment}/redeploy', 'redeploy')->name('redeploy');
    });

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
