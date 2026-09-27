<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\ApiToken;
use App\Models\DeviceAuthorization;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Web approval page for the dply CLI's device-flow login. The CLI
 * prints a short `user_code` in the terminal and a verification URL;
 * the user opens that URL (or follows the deep link), confirms the
 * scopes the token will hold, and clicks Approve. That mints an
 * ApiToken in the user's current org and parks the plaintext on the
 * device_authorization row so the polling CLI can pick it up exactly
 * once.
 */
#[Layout('layouts.app')]
class DeviceApproval extends Component
{
    /** @var list<string> */
    public array $selectedAbilities = [];

    #[Url(as: 'user_code', except: '')]
    public string $userCode = '';

    public ?string $organizationId = null;

    public ?string $resolvedUserCode = null;

    /** Set to one of: approved | denied — gates the "all done" view. */
    public ?string $completedState = null;

    /**
     * Abilities offered on the device-approval page (before role caps).
     *
     * @return list<string>
     */
    public static function defaultAbilities(): array
    {
        $configured = array_values(config('cli.device_flow_abilities', []));

        if ($configured === [] || $configured === ['*']) {
            return ApiToken::catalogAbilities();
        }

        return array_values(array_intersect($configured, ApiToken::catalogAbilities()));
    }

    public function mount(): void
    {
        $org = Auth::user()?->currentOrganization();
        if ($org) {
            $this->organizationId = (string) $org->id;
        }

        $this->selectedAbilities = $this->grantableAbilities();

        if ($this->userCode !== '') {
            $this->lookup();
        }
    }

    public function updatedOrganizationId(): void
    {
        $this->selectedAbilities = $this->grantableAbilities();
    }

    /**
     * Edge scopes the current user may grant for the selected org, capped
     * by their org role. The device flow previously let any member mint
     * deploy/write tokens; deploy/write now require deployer (deploy) or
     * admin/owner (deploy + write) access, mirroring the admin gate on the
     * normal API-token UI.
     *
     * @return list<string>
     */
    protected function grantableAbilities(?Organization $org = null): array
    {
        $org ??= $this->resolvedOrganization();
        $user = Auth::user();

        if ($org === null || $user === null) {
            return [];
        }

        $catalog = self::defaultAbilities();
        $roleCap = array_values(match (true) {
            $org->hasAdminAccess($user) => config('cli.device_flow_role_caps.admin', []),
            $org->userIsDeployer($user) => config('cli.device_flow_role_caps.deployer', []),
            $org->userIsViewer($user) => config('cli.device_flow_role_caps.viewer', []),
            default => config('cli.device_flow_role_caps.member', []),
        });

        if (in_array('*', $roleCap, true)) {
            return $catalog;
        }

        return array_values(array_intersect($catalog, $roleCap));
    }

    /**
     * Group grantable scopes using the API-token category layout so the
     * device page stays in sync with Profile → API keys.
     *
     * @param  list<array{ability: string, label: string}>  $availableScopes
     * @return array<string, list<array{ability: string, label: string}>>
     */
    protected function groupedScopes(array $availableScopes): array
    {
        $byAbility = [];
        foreach ($availableScopes as $scope) {
            $byAbility[$scope['ability']] = $scope;
        }

        $groups = [];
        foreach (config('api_token_permissions.categories', []) as $category) {
            $label = __((string) ($category['label'] ?? 'Other'));
            $scopes = [];
            foreach ($category['permissions'] ?? [] as $permission) {
                $ability = (string) ($permission['ability'] ?? '');
                if ($ability !== '' && isset($byAbility[$ability])) {
                    $scopes[] = $byAbility[$ability];
                    unset($byAbility[$ability]);
                }
            }
            if ($scopes !== []) {
                $groups[$label] = $scopes;
            }
        }

        if ($byAbility !== []) {
            $groups[__('Other')] = array_values($byAbility);
        }

        return $groups;
    }

    public function lookup(): void
    {
        $this->resetErrorBag();
        $this->resolvedUserCode = null;

        $record = DeviceAuthorization::resolveUserCode($this->userCode);
        if ($record === null || ! $record->isPending()) {
            $this->addError('userCode', __('That code is invalid, already used, or expired. Re-run `dply login` to get a fresh code.'));

            return;
        }

        $this->resolvedUserCode = $record->user_code;
    }

    public function toggleAbility(string $ability): void
    {
        if (! in_array($ability, $this->grantableAbilities(), true)) {
            return;
        }

        if (in_array($ability, $this->selectedAbilities, true)) {
            $this->selectedAbilities = array_values(array_filter(
                $this->selectedAbilities,
                fn (string $a): bool => $a !== $ability
            ));

            return;
        }

        $this->selectedAbilities[] = $ability;
    }

    public function approve(): void
    {
        $record = $this->lockedPendingRecord();
        if ($record === null) {
            return;
        }

        $user = Auth::user();
        $org = $this->resolvedOrganization();
        if ($user === null || $org === null) {
            $this->addError('userCode', __('Pick an organization to authorize this device against.'));

            return;
        }

        $selected = array_values(array_intersect($this->selectedAbilities, self::defaultAbilities()));
        if ($selected === []) {
            $this->addError('selectedAbilities', __('Pick at least one scope.'));

            return;
        }

        // Cap the requested scopes to what this user's org role may grant.
        // This is the security boundary — the UI hides ungrantable scopes,
        // but a tampered request must never escalate past the role cap.
        $abilities = array_values(array_intersect($selected, $this->grantableAbilities($org)));
        if ($abilities === []) {
            $this->addError('selectedAbilities', __('Your organization role does not allow the selected scopes. Ask an org admin to authorize deploy or write access.'));

            return;
        }

        try {
            ApiToken::assertAbilitiesValidForStorage($abilities);
        } catch (\InvalidArgumentException $e) {
            $this->addError('selectedAbilities', $e->getMessage());

            return;
        }

        $created = ApiToken::createToken(
            $user,
            $org,
            (string) config('cli.token_name', 'dply CLI'),
            null,
            $abilities,
            null,
        );

        $record->forceFill([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'api_token_id' => $created['token']->id,
            'token_plaintext' => $created['plaintext'],
            'status' => DeviceAuthorization::STATUS_AUTHORIZED,
            'authorized_at' => Carbon::now(),
        ])->save();

        audit_log($org, $user, 'api_token.device_authorized', $created['token'], null, [
            'device_authorization_id' => (string) $record->id,
            'token_id' => (string) $created['token']->id,
            'token_name' => 'dply CLI',
            'abilities' => $abilities,
        ]);

        $this->completedState = 'approved';
    }

    public function deny(): void
    {
        $record = $this->lockedPendingRecord();
        if ($record === null) {
            return;
        }

        $record->forceFill([
            'user_id' => Auth::id(),
            'status' => DeviceAuthorization::STATUS_DENIED,
        ])->save();

        $this->completedState = 'denied';
    }

    protected function lockedPendingRecord(): ?DeviceAuthorization
    {
        if ($this->resolvedUserCode === null) {
            $this->addError('userCode', __('Enter the code from your terminal first.'));

            return null;
        }

        $record = DeviceAuthorization::resolveUserCode($this->resolvedUserCode);
        if ($record === null || ! $record->isPending()) {
            $this->addError('userCode', __('That code is invalid, already used, or expired. Re-run `dply login` to get a fresh code.'));

            return null;
        }

        return $record;
    }

    protected function resolvedOrganization(): ?Organization
    {
        if ($this->organizationId === null) {
            return null;
        }

        $org = Organization::query()->find($this->organizationId);
        if (! $org) {
            return null;
        }

        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        // Token belongs to the user + org; require user to belong to the org.
        if (! $user->organizations()->where('organizations.id', $org->id)->exists()) {
            return null;
        }

        return $org;
    }

    public function render(): View
    {
        $user = Auth::user();
        $organizations = $user
            ? $user->organizations()->orderBy('name')->get()
            : collect();

        $grantable = $this->grantableAbilities();
        $labels = (array) config('cli.device_flow_scope_labels', []);
        $catalogLabels = [];
        foreach (config('api_token_permissions.categories', []) as $category) {
            foreach ($category['permissions'] ?? [] as $permission) {
                $ability = (string) ($permission['ability'] ?? '');
                if ($ability !== '' && ! empty($permission['label'])) {
                    $catalogLabels[$ability] = (string) $permission['label'];
                }
            }
        }

        $availableScopes = array_map(
            fn (string $ability): array => [
                'ability' => $ability,
                'label' => __((string) ($labels[$ability] ?? $catalogLabels[$ability] ?? $ability)),
            ],
            $grantable,
        );

        return view('livewire.auth.device-approval', [
            'organizations' => $organizations,
            'availableScopes' => $availableScopes,
            'scopeGroups' => $this->groupedScopes($availableScopes),
        ]);
    }
}
