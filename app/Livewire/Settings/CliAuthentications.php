<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Support\Cli\DplyCliCommandCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class CliAuthentications extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

    public ?string $organization_id = null;

    public function mount(): void
    {
        $orgs = $this->adminOrganizations();
        if ($orgs->isEmpty()) {
            return;
        }

        $current = Auth::user()->currentOrganization();
        $pick = $current && $orgs->contains('id', $current->id)
            ? $current->id
            : $orgs->first()->id;

        $this->organization_id = (string) $pick;
    }

    public function revokeCliToken(int|string $apiTokenId): void
    {
        $org = $this->resolvedOrganization();
        if ($org === null) {
            return;
        }

        $this->authorize('update', $org);

        $token = ApiToken::query()
            ->where('organization_id', $org->id)
            ->where('name', (string) config('cli.token_name', 'dply CLI'))
            ->findOrFail($apiTokenId);

        $snapshot = [
            'token_id' => (string) $token->id,
            'token_name' => $token->name,
            'token_prefix' => $token->token_prefix,
            'abilities' => $token->abilities,
            'expires_at' => $token->expires_at?->toIso8601String(),
        ];
        $token->delete();

        audit_log($org, Auth::user(), 'api_token.revoked', null, $snapshot, null);

        $this->toastSuccess(__('CLI session revoked. Run `dply login` on that machine to reconnect.'));
    }

    protected function resolvedOrganization(): ?Organization
    {
        if ($this->organization_id === null) {
            return null;
        }

        $org = Organization::query()->find($this->organization_id);
        if (! $org || ! $org->hasAdminAccess(Auth::user())) {
            return null;
        }

        return $org;
    }

    /**
     * @return Collection<int, Organization>
     */
    protected function adminOrganizations(): Collection
    {
        return Auth::user()
            ->organizations()
            ->get()
            ->filter(fn (Organization $o) => $o->hasAdminAccess(Auth::user()))
            ->values();
    }

    public function render(): View
    {
        $org = $this->resolvedOrganization();
        $cliTokens = $org
            ? ApiToken::query()
                ->where('organization_id', $org->id)
                ->where('name', (string) config('cli.token_name', 'dply CLI'))
                ->whereNull('revoked_at')
                ->with('user:id,name,email')
                ->orderByDesc('last_used_at')
                ->orderByDesc('created_at')
                ->get()
            : collect();

        $catalog = DplyCliCommandCatalog::forServer();

        return view('livewire.settings.cli-authentications', [
            'organizations' => $this->adminOrganizations(),
            'cliTokens' => $cliTokens,
            'appUrl' => rtrim((string) config('app.url'), '/'),
            'cliTokenName' => (string) config('cli.token_name', 'dply CLI'),
            'cliGroups' => $catalog['groups'],
            'cliEntries' => $catalog['entries'],
            'cliTotal' => $catalog['total'],
        ]);
    }
}
