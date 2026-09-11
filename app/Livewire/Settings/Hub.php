<?php

namespace App\Livewire\Settings;

use App\Http\Controllers\SessionController;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\InteractsWithUnsavedChangesBar;
use App\Livewire\Forms\ProfileGeneralForm;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class Hub extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use InteractsWithUnsavedChangesBar;

    /** @var 'profile'|'servers' */
    public string $section = 'profile';

    /**
     * Identity form (name / email / country / locale / timezone). Merged in
     * from the former /profile/edit page so /settings/profile is the single
     * personal-settings surface.
     */
    public ProfileGeneralForm $profileForm;

    public bool $verificationLinkSent = false;

    /** @var array<string, mixed> */
    public array $ui = [];

    /** @var array<string, mixed> */
    public array $organizationServerSite = [];

    /**
     * Replaced in mount() by defaultInsightsState()/insightsStateFromOrg(); the
     * literal here just has to satisfy the declared shape.
     *
     * @var array{digest_non_critical: bool, digest_frequency: string, quiet_hours_enabled: bool, quiet_hours_start: int, quiet_hours_end: int, allow_config_mutation: bool}
     */
    public array $organizationInsights = [
        'digest_non_critical' => false,
        'digest_frequency' => 'daily',
        'quiet_hours_enabled' => false,
        'quiet_hours_start' => 22,
        'quiet_hours_end' => 7,
        'allow_config_mutation' => true,
    ];

    /** @var array<string, mixed> */
    public array $teamServerSite = [];

    public ?string $selectedTeamId = null;

    public string $profileTimezone = 'UTC';

    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $this->ui = $user->mergedUiPreferences();
        $this->profileTimezone = $user->timezone ?? config('app.timezone');

        // Identity fields previously on /profile/edit. Merged here so
        // /settings/profile is the canonical personal-settings page.
        $this->profileForm->fill([
            'name' => $user->name,
            'email' => $user->email,
            'country_code' => $user->country_code ?? '',
            'locale' => $user->locale ?? config('app.locale'),
            'timezone' => $user->timezone ?? config('app.timezone'),
        ]);

        $this->section = 'profile';

        $org = $user->currentOrganization();
        $this->hydrateServerSiteState($org);
    }

    // -----------------------------------------------------------------
    // Identity (formerly Profile\Edit). These methods + computed props
    // were lifted from app/Livewire/Profile/Edit.php as part of the
    // /profile → /settings/profile merge; the old component is gone.
    // -----------------------------------------------------------------

    protected function authUser(): ?User
    {
        return Auth::user();
    }

    /**
     * @return list<array{id: string, device_label: string, ip_address: ?string, last_activity: int, is_current: bool}>
     */
    public function getSessionsProperty(): array
    {
        $user = $this->authUser();
        if (! $user) {
            return [];
        }

        return SessionController::listSessionsForUser($user->id, session()->getId());
    }

    public function getGravatarUrlProperty(): string
    {
        $email = strtolower(trim($this->profileForm->email ?? ''));
        if ($email === '') {
            $email = strtolower(trim((string) ($this->authUser()->email ?? '')));
        }

        return 'https://www.gravatar.com/avatar/'.md5($email).'?s=160&d=mp';
    }

    public function updateProfile(): void
    {
        $user = $this->authUser();
        if (! $user) {
            return;
        }

        $this->profileForm->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'country_code' => ['nullable', 'string', 'max:2'],
            'locale' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL))],
        ]);

        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'country_code' => $user->country_code,
            'locale' => $user->locale,
            'timezone' => $user->timezone,
        ];
        $after = [];
        $emailChanged = false;

        DB::transaction(function () use ($user, $before, &$after, &$emailChanged): void {
            $user->name = $this->profileForm->name;
            if ($this->profileForm->email !== $user->email) {
                $emailChanged = true;
                $user->email = $this->profileForm->email;
                $user->email_verified_at = null;
            }
            $user->country_code = $this->profileForm->country_code !== '' ? $this->profileForm->country_code : null;
            $user->locale = $this->profileForm->locale;
            $user->timezone = $this->profileForm->timezone;
            $user->save();

            foreach (['name', 'email', 'country_code', 'locale', 'timezone'] as $key) {
                if (($before[$key] ?? null) !== ($user->{$key} ?? null)) {
                    $after[$key] = $user->{$key};
                }
            }
        });

        if ($after !== [] && ($org = $user->currentOrganization())) {
            $action = $emailChanged && count($after) === 1 ? 'user.email_changed' : 'user.profile_updated';
            audit_log($org, $user, $action, $user, $before, $after);
        }

        // The Servers & Sites tab reads the user's timezone as a label —
        // keep its local string in sync after a profile save.
        $this->profileTimezone = $user->fresh()->timezone ?? config('app.timezone');

        $this->toastSuccess(__('Profile details saved.'));
        $this->dispatch('profile-updated');
    }

    public function discardProfileFormUnsaved(): void
    {
        $user = $this->authUser();
        if (! $user) {
            return;
        }
        $this->profileForm->fill([
            'name' => $user->name,
            'email' => $user->email,
            'country_code' => $user->country_code ?? '',
            'locale' => $user->locale ?? config('app.locale'),
            'timezone' => $user->timezone ?? config('app.timezone'),
        ]);
    }

    public function sendVerificationEmail(): void
    {
        $user = $this->authUser();
        if (! $user || $user->hasVerifiedEmail()) {
            return;
        }
        $user->sendEmailVerificationNotification();
        $this->verificationLinkSent = true;
    }

    public function revokeSession(int|string $sessionId): void
    {
        $userId = $this->authUser()?->id;
        if (! $userId) {
            return;
        }
        if ((string) $sessionId === session()->getId()) {
            $this->addError('session', __('You cannot revoke your current session.'));

            return;
        }
        SessionController::deleteSessionForUser($userId, (string) $sessionId);
        $this->dispatch('session-revoked');
    }

    public function revokeOtherSessions(): void
    {
        $userId = $this->authUser()?->id;
        if (! $userId) {
            return;
        }
        SessionController::deleteOtherSessionsForUser($userId, session()->getId());
        $this->dispatch('sessions-revoked');
    }

    // -----------------------------------------------------------------
    // End identity helpers.
    // -----------------------------------------------------------------

    public function updatedSelectedTeamId(mixed $value): void
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();
        $defaults = config('user_preferences.team_server_site_defaults', []);

        if (! $org || ! $value) {
            $this->teamServerSite = $defaults;
            $this->selectedTeamId = null;

            return;
        }

        $team = $org->teams()->whereKey($value)->first();
        if ($team instanceof Team) {
            $this->teamServerSite = $team->mergedTeamPreferences();
            $this->selectedTeamId = (string) $team->id;
        } else {
            $this->teamServerSite = $defaults;
            $this->selectedTeamId = null;
        }
    }

    protected function hydrateServerSiteState(?Organization $org): void
    {
        $teamDefaults = config('user_preferences.team_server_site_defaults', []);
        $orgDefaults = config('user_preferences.organization_server_site_defaults', []);

        if (! $org instanceof Organization) {
            $this->organizationServerSite = $orgDefaults;
            $this->organizationInsights = $this->defaultInsightsState();
            $this->teamServerSite = $teamDefaults;
            $this->selectedTeamId = null;

            return;
        }

        $this->organizationServerSite = $org->mergedServerSitePreferences();
        $this->organizationInsights = $this->insightsStateFromOrg($org);

        $teams = $org->teams()->orderBy('name')->get();
        if ($teams->isEmpty()) {
            $this->teamServerSite = $teamDefaults;
            $this->selectedTeamId = null;

            return;
        }

        $firstId = $teams->first()->id;
        $this->selectedTeamId = $teams->contains('id', $this->selectedTeamId)
            ? $this->selectedTeamId
            : (string) $firstId;

        $team = $teams->firstWhere('id', $this->selectedTeamId);
        $this->teamServerSite = $team instanceof Team
            ? $team->mergedTeamPreferences()
            : $teamDefaults;
    }

    /**
     * @return array{digest_non_critical: bool, digest_frequency: string, quiet_hours_enabled: bool, quiet_hours_start: int, quiet_hours_end: int, allow_config_mutation: bool}
     */
    protected function defaultInsightsState(): array
    {
        $d = config('insights.organization_defaults', []);
        $freq = ($d['digest_frequency'] ?? 'daily') === 'weekly' ? 'weekly' : 'daily';

        return [
            'digest_non_critical' => (bool) ($d['digest_non_critical'] ?? false),
            'digest_frequency' => $freq,
            'quiet_hours_enabled' => (bool) ($d['quiet_hours_enabled'] ?? false),
            'quiet_hours_start' => (int) ($d['quiet_hours_start'] ?? 22),
            'quiet_hours_end' => (int) ($d['quiet_hours_end'] ?? 7),
            'allow_config_mutation' => (bool) ($d['allow_config_mutation'] ?? true),
        ];
    }

    /**
     * @return array{digest_non_critical: bool, digest_frequency: string, quiet_hours_enabled: bool, quiet_hours_start: int, quiet_hours_end: int, allow_config_mutation: bool}
     */
    protected function insightsStateFromOrg(Organization $org): array
    {
        // config/insights.php left with the Insights module, so the merged array
        // is whatever the org has stored — possibly nothing. Layer it over the
        // defaults rather than indexing keys that may not be there.
        $m = array_replace($this->defaultInsightsState(), $org->mergedInsightsPreferences());
        $freq = $m['digest_frequency'] === 'weekly' ? 'weekly' : 'daily';

        return [
            'digest_non_critical' => (bool) $m['digest_non_critical'],
            'digest_frequency' => $freq,
            'quiet_hours_enabled' => (bool) $m['quiet_hours_enabled'],
            'quiet_hours_start' => (int) $m['quiet_hours_start'],
            'quiet_hours_end' => (int) $m['quiet_hours_end'],
            'allow_config_mutation' => (bool) $m['allow_config_mutation'],
        ];
    }

    public function saveProfile(): void
    {
        /** @var User $user */
        $user = Auth::user();

        $this->validate([
            'ui.newsletter' => ['boolean'],
            'ui.keyboard_shortcuts' => ['boolean'],
            'ui.redirect_home_to_app' => ['boolean'],
            'ui.subscription_invoice_emails' => ['boolean'],
            'ui.theme' => [Rule::in(config('user_preferences.theme_options', []))],
            'ui.navigation_layout' => [Rule::in(config('user_preferences.navigation_layout_options', []))],
            'ui.notification_position' => [Rule::in(array_keys(config('user_preferences.notification_positions', [])))],
        ]);

        $keys = array_keys(config('user_preferences.defaults', []));
        $filtered = array_intersect_key($this->ui, array_flip($keys));

        $user->update([
            'ui_preferences' => array_merge($user->ui_preferences ?? [], $filtered),
        ]);

        $this->ui = $user->fresh()->mergedUiPreferences();

        $this->toastSuccess(__('Profile settings saved.'));
    }

    public function saveOrganizationServersSites(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();

        if (! $org instanceof Organization) {
            $this->toastError(__('Select or create an organization to save these defaults.'));

            return;
        }

        $this->authorize('update', $org);

        $this->validate([
            'organizationServerSite.email_server_passwords' => ['boolean'],
            'organizationServerSite.set_timezone_on_new_servers' => ['boolean'],
        ]);

        $keys = array_keys(config('user_preferences.organization_server_site_defaults', []));
        $filtered = array_intersect_key($this->organizationServerSite, array_flip($keys));

        $org->update([
            'server_site_preferences' => array_merge($org->server_site_preferences ?? [], $filtered),
        ]);

        $this->organizationServerSite = $org->fresh()->mergedServerSitePreferences();

        $this->toastSuccess(__('Organization settings saved.'));
    }

    public function saveOrganizationInsights(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();

        if (! $org instanceof Organization) {
            $this->toastError(__('Select or create an organization to save Insights preferences.'));

            return;
        }

        $this->authorize('update', $org);

        $this->validate([
            'organizationInsights.digest_non_critical' => ['boolean'],
            'organizationInsights.digest_frequency' => ['required', 'string', Rule::in(['daily', 'weekly'])],
            'organizationInsights.quiet_hours_enabled' => ['boolean'],
            'organizationInsights.quiet_hours_start' => ['required', 'integer', 'min:0', 'max:23'],
            'organizationInsights.quiet_hours_end' => ['required', 'integer', 'min:0', 'max:23'],
            'organizationInsights.allow_config_mutation' => ['boolean'],
        ]);

        $stored = $org->insights_preferences ?? [];

        $stored['digest_non_critical'] = $this->organizationInsights['digest_non_critical'];
        $stored['digest_frequency'] = $this->organizationInsights['digest_frequency'] === 'weekly' ? 'weekly' : 'daily';
        $stored['quiet_hours_enabled'] = $this->organizationInsights['quiet_hours_enabled'];
        $stored['quiet_hours_start'] = $this->organizationInsights['quiet_hours_start'];
        $stored['quiet_hours_end'] = $this->organizationInsights['quiet_hours_end'];
        $stored['allow_config_mutation'] = $this->organizationInsights['allow_config_mutation'];

        $org->update(['insights_preferences' => $stored]);

        $this->organizationInsights = $this->insightsStateFromOrg($org->fresh());

        $this->toastSuccess(__('Insights preferences saved.'));
    }

    public function saveTeamServersSites(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();

        if (! $org instanceof Organization) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $team = $this->selectedTeamId
            ? $org->teams()->whereKey($this->selectedTeamId)->first()
            : null;

        if (! $team instanceof Team) {
            $this->toastError(__('Select a team to save team defaults.'));

            return;
        }

        if (! $team->userCanManageSshKeys($user)) {
            abort(403);
        }

        $this->validate([
            'teamServerSite.show_server_updates_in_list' => ['boolean'],
            'teamServerSite.isolate_new_sites' => ['boolean'],
            'teamServerSite.default_server_sort' => [Rule::in(array_keys(config('user_preferences.server_sort_options', [])))],
            'teamServerSite.default_site_sort' => [Rule::in(array_keys(config('user_preferences.site_sort_options', [])))],
        ]);

        $keys = array_keys(config('user_preferences.team_server_site_defaults', []));
        $filtered = array_intersect_key($this->teamServerSite, array_flip($keys));

        $team->update([
            'preferences' => array_merge($team->preferences ?? [], $filtered),
        ]);

        $this->teamServerSite = $team->fresh()->mergedTeamPreferences();

        $this->toastSuccess(__('Team settings saved.'));
    }

    public function persistTheme(string $theme): void
    {
        $options = config('user_preferences.theme_options', []);
        if (! in_array($theme, $options, true)) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $this->ui['theme'] = $theme;

        $keys = array_keys(config('user_preferences.defaults', []));
        $filtered = array_intersect_key($this->ui, array_flip($keys));

        $user->update([
            'ui_preferences' => array_merge($user->ui_preferences ?? [], $filtered),
        ]);

        $this->ui = $user->fresh()->mergedUiPreferences();
    }

    public function persistNavigationLayout(string $layout): void
    {
        $options = config('user_preferences.navigation_layout_options', []);
        if (! in_array($layout, $options, true)) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $this->ui['navigation_layout'] = $layout;

        $keys = array_keys(config('user_preferences.defaults', []));
        $filtered = array_intersect_key($this->ui, array_flip($keys));

        $user->update([
            'ui_preferences' => array_merge($user->ui_preferences ?? [], $filtered),
        ]);

        $this->ui = $user->fresh()->mergedUiPreferences();

        session()->flash('success', __('Navigation layout updated.'));

        $this->redirect(route('settings.profile'), navigate: false);
    }

    /**
     * @return array<int, string>
     */
    public function getTimezonesProperty(): array
    {
        return collect(DateTimeZone::listIdentifiers(DateTimeZone::ALL))
            ->sort()
            ->values()
            ->all();
    }

    public function saveProfileTimezone(): void
    {
        /** @var User $user */
        $user = Auth::user();

        $this->validate([
            'profileTimezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL))],
        ]);

        $user->update(['timezone' => $this->profileTimezone]);

        $this->profileTimezone = $user->fresh()->timezone ?? config('app.timezone');

        $this->toastSuccess(__('Timezone saved.'));
    }

    public function discardProfileUnsaved(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $this->ui = $user->mergedUiPreferences();
    }

    public function discardProfileTimezoneUnsaved(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $this->profileTimezone = $user->timezone ?? config('app.timezone');
    }

    public function discardOrganizationServersSitesUnsaved(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();
        if (! $org instanceof Organization) {
            return;
        }

        $this->organizationServerSite = $org->mergedServerSitePreferences();
    }

    public function discardOrganizationInsightsUnsaved(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();
        if (! $org instanceof Organization) {
            return;
        }

        $this->organizationInsights = $this->insightsStateFromOrg($org);
    }

    public function discardTeamServersSitesUnsaved(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();
        if (! $org instanceof Organization || ! $this->selectedTeamId) {
            return;
        }

        $team = $org->teams()->whereKey($this->selectedTeamId)->first();
        $defaults = config('user_preferences.team_server_site_defaults', []);

        $this->teamServerSite = $team instanceof Team
            ? $team->mergedTeamPreferences()
            : $defaults;
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $org = $user->currentOrganization();
        $teams = $org?->teams()->orderBy('name')->get() ?? collect();

        $selectedTeam = $this->selectedTeamId
            ? $teams->firstWhere('id', $this->selectedTeamId)
            : null;

        $canEditTeamPrefs = $selectedTeam instanceof Team
            && $selectedTeam->userCanManageSshKeys($user);

        return view('livewire.settings.hub', [
            'currentOrg' => $org,
            'teams' => $teams,
            'selectedTeam' => $selectedTeam,
            'canEditOrgPrefs' => $org?->hasAdminAccess($user) ?? false,
            'canEditTeamPrefs' => $canEditTeamPrefs,
            'userTimezoneLabel' => $user->timezone ?? 'UTC',
            'profileTimezoneUnsavedTargets' => 'profileTimezone',
            'profileUnsavedTargets' => 'ui.newsletter,ui.keyboard_shortcuts,ui.redirect_home_to_app,ui.subscription_invoice_emails,ui.theme,ui.navigation_layout,ui.notification_position',
            'organizationServerSiteUnsavedTargets' => 'organizationServerSite.email_server_passwords,organizationServerSite.set_timezone_on_new_servers',
            'organizationInsightsUnsavedTargets' => 'organizationInsights.digest_non_critical,organizationInsights.digest_frequency,organizationInsights.quiet_hours_enabled,organizationInsights.quiet_hours_start,organizationInsights.quiet_hours_end',
            'teamServersSitesUnsavedTargets' => 'teamServerSite.show_server_updates_in_list,teamServerSite.isolate_new_sites,teamServerSite.default_server_sort,teamServerSite.default_site_sort',
            'profileFormUnsavedTargets' => 'profileForm.name,profileForm.email,profileForm.country_code,profileForm.locale,profileForm.timezone',
        ]);
    }
}
