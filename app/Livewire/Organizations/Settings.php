<?php

namespace App\Livewire\Organizations;

use App\Actions\Organizations\DeleteOrganizationAction;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ApiToken;
use App\Models\Organization;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Head\Facades\Head;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

/**
 * Every organization-level setting an admin can change. Each one saves on
 * its own when it changes (redesign 2026-09-27) — there is no Save button.
 *
 * The old "Automation & API" tab (email defaults, Cloud alert destinations,
 * Edge data region, API tokens) folded in here in 2026-08: none of it was
 * automation the org *ran*, it was all settings that happened to be about
 * automated things, and splitting them across two admin pages meant no single
 * place answered "what is configured for this org".
 */
#[Layout('layouts.app')]
class Settings extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use WithFileUploads;

    public Organization $organization;

    public string $name = '';

    public string $slug = '';

    public string $email = '';

    public string $description = '';

    public string $timezone = '';

    public mixed $org_icon_upload = null;

    public string $delete_confirm = '';

    public bool $deploy_email_notifications_enabled = true;

    public string $edge_data_region = 'default';

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        abort_unless($organization->hasAdminAccess(auth()->user()), 403);
        Head::title($organization->name.' · '.__('Settings'));

        // The route-bound model is already fresh — hydrate directly off it.
        $this->organization = $organization;
        $this->name = (string) $organization->name;
        $this->slug = (string) $organization->slug;
        $this->email = (string) ($organization->email ?? '');
        $this->description = (string) ($organization->description ?? '');
        $this->timezone = (string) ($organization->timezone ?? '');
        $this->loadOrganizationRelations();
    }

    /**
     * Post-mutation reload: re-fetch from the DB to pick up the change.
     *
     * NB: do not rename loadOrganizationRelations() to hydrateOrganization().
     * Livewire treats `hydrate{Property}` as a lifecycle hook for the public
     * $organization property and invokes it from outside the class — which
     * lands in __call for a protected method and throws BadMethodCallException.
     */
    protected function refreshOrganization(): void
    {
        $this->organization = $this->organization->fresh();
        $this->loadOrganizationRelations();
    }

    protected function loadOrganizationRelations(): void
    {
        $this->organization->load(['apiTokens']);

        $this->deploy_email_notifications_enabled = (bool) $this->organization->deploy_email_notifications_enabled;
        $this->edge_data_region = (string) ($this->organization->edge_data_region ?: 'default');
    }

    /*
     * Autosave: each profile field saves on its own when it changes (text on
     * blur, selects live). Per-field hooks rather than a catch-all updated()
     * so delete_confirm keystrokes and the icon upload never reach saveField.
     */
    public function updatedName(): void
    {
        $this->saveField('name');
    }

    public function updatedSlug(): void
    {
        $this->saveField('slug');
    }

    public function updatedEmail(): void
    {
        $this->saveField('email');
    }

    public function updatedDescription(): void
    {
        $this->saveField('description');
    }

    public function updatedTimezone(): void
    {
        $this->saveField('timezone');
    }

    /** @param  'name'|'slug'|'email'|'description'|'timezone'  $field */
    private function saveField(string $field): void
    {
        $this->authorize('update', $this->organization);

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255', Rule::unique('organizations', 'slug')->ignore($this->organization->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL))],
        ];
        $this->validateOnly($field, [$field => $rules[$field]]);

        $value = trim((string) $this->{$field});
        $value = match ($field) {
            'slug' => Str::lower($value),
            'name' => $value,
            default => $value !== '' ? $value : null,
        };
        $this->{$field} = (string) $value;

        $old = $this->organization->{$field};
        // Blur fires on every tab-through: an unchanged value is not a save.
        if ($old === $value) {
            return;
        }

        $this->organization->update([$field => $value]);

        audit_log(
            $this->organization,
            auth()->user(),
            'organization.updated',
            $this->organization,
            [$field => $old],
            [$field => $value],
        );

        $this->dispatch('org-setting-saved', field: $field);
    }

    /** Fires when an icon file is chosen — validate, store, set it immediately. */
    public function updatedOrgIconUpload(): void
    {
        $this->authorize('update', $this->organization);

        $this->validate([
            'org_icon_upload' => [
                'required',
                'file',
                'mimetypes:image/png,image/jpeg,image/webp,image/gif,image/x-icon,image/vnd.microsoft.icon',
                'max:1024', // KB
            ],
        ], attributes: ['org_icon_upload' => __('icon')]);

        $old = $this->organization->icon_path;
        $ext = $this->extensionFor($this->org_icon_upload->getMimeType());
        $path = 'org-logos/'.$this->organization->id.'-'.Str::lower(Str::random(8)).'.'.$ext;

        Storage::disk('site_assets')->put($path, $this->org_icon_upload->get()); // get(): the upload may be staged on R2
        $this->organization->forceFill(['icon_path' => $path])->save();

        if (is_string($old) && $old !== '' && $old !== $path) {
            Storage::disk('site_assets')->delete($old);
        }

        $this->reset('org_icon_upload');
        $this->recordIconChange($old, $path);
        $this->dispatch('org-setting-saved', field: 'icon');
    }

    public function removeOrgIcon(): void
    {
        $this->authorize('update', $this->organization);

        $old = $this->organization->icon_path;
        if (is_string($old) && $old !== '') {
            Storage::disk('site_assets')->delete($old);
            $this->organization->forceFill(['icon_path' => null])->save();
            $this->recordIconChange($old, null);
        }

        $this->dispatch('org-setting-saved', field: 'icon');
    }

    public function updatedDeployEmailNotificationsEnabled(): void
    {
        $this->authorize('update', $this->organization);

        $this->organization->update([
            'deploy_email_notifications_enabled' => $this->deploy_email_notifications_enabled,
        ]);
        audit_log($this->organization, auth()->user(), 'organization.deploy_email_notifications_updated', null, null, [
            'enabled' => $this->deploy_email_notifications_enabled,
        ]);
        $this->refreshOrganization();
        $this->dispatch('org-setting-saved', field: 'deploy_email_notifications_enabled');
    }

    public function updatedEdgeDataRegion(): void
    {
        $this->authorize('update', $this->organization);

        $allowed = ['default', 'eu', 'weur', 'eeur', 'wnam', 'enam', 'apac', 'oc'];
        if (! in_array($this->edge_data_region, $allowed, true)) {
            $this->edge_data_region = 'default';
        }

        $previous = (string) ($this->organization->edge_data_region ?: 'default');
        $this->organization->update(['edge_data_region' => $this->edge_data_region]);

        audit_log(
            $this->organization,
            auth()->user(),
            'organization.edge_data_region_updated',
            null,
            ['edge_data_region' => $previous],
            ['edge_data_region' => $this->edge_data_region],
        );

        $this->refreshOrganization();
        $this->dispatch('org-setting-saved', field: 'edge_data_region');
    }

    /**
     * Opens the confirm modal without embedding JSON in wire:click (which breaks HTML attributes).
     */
    public function promptRevokeApiToken(string $apiTokenId): void
    {
        $this->authorize('update', $this->organization);

        $apiToken = ApiToken::query()
            ->where('organization_id', $this->organization->id)
            ->whereKey($apiTokenId)
            ->first();

        if ($apiToken === null) {
            return;
        }

        $this->openConfirmActionModal(
            'revokeApiToken',
            [$apiToken->id],
            __('Revoke API token'),
            $apiToken->revoked_at
                ? __('Delete :name? It was already revoked when its owner left the organization.', ['name' => $apiToken->name])
                : __('Revoke :name? Integrations using this token will stop working immediately. This cannot be undone.', ['name' => $apiToken->name]),
            $apiToken->revoked_at ? __('Delete token') : __('Revoke token'),
            true
        );
    }

    public function revokeApiToken(int|string $apiTokenId): void
    {
        $this->authorize('update', $this->organization);

        $apiToken = ApiToken::where('organization_id', $this->organization->id)->findOrFail($apiTokenId);

        // Same audit shape the API keys settings page writes — an admin
        // revoking another member's token is exactly the event you want a
        // trail for, and this path had none.
        $snapshot = [
            'token_id' => (string) $apiToken->id,
            'token_name' => $apiToken->name,
            'token_prefix' => $apiToken->token_prefix,
            'abilities' => $apiToken->abilities,
            'expires_at' => $apiToken->expires_at?->toIso8601String(),
        ];
        $apiToken->delete();

        audit_log($this->organization, auth()->user(), 'api_token.revoked', null, $snapshot, null);

        $this->refreshOrganization();
        $this->toastSuccess(__('API token revoked.'));
    }

    public function deleteOrganization(DeleteOrganizationAction $action): mixed
    {
        $this->authorize('delete', $this->organization);

        $this->validate([
            // Against the saved name, not $this->name: a rejected autosave can
            // leave the Name field holding something the org is not called.
            'delete_confirm' => ['required', Rule::in([$this->organization->name])],
        ], [
            'delete_confirm.in' => __('Type the organization name exactly to confirm.'),
        ]);

        $action->handle($this->organization, auth()->user());

        // Land the user on another organization they belong to.
        $next = auth()->user()->organizations()->first();
        if ($next) {
            session(['current_organization_id' => $next->id]);
        } else {
            Session::forget('current_organization_id');
        }
        Session::forget('current_team_id');

        Session::flash('success', __('Organization deleted.'));

        return $this->redirect(route('organizations.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.organizations.settings', [
            'timezones' => DateTimeZone::listIdentifiers(DateTimeZone::ALL),
        ]);
    }

    private function extensionFor(?string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => 'png',
        };
    }

    private function recordIconChange(?string $old, ?string $new): void
    {
        audit_log(
            $this->organization,
            auth()->user(),
            'organization.icon.updated',
            $this->organization,
            ['icon_path' => $old],
            ['icon_path' => $new],
        );
    }
}
