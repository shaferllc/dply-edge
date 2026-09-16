<?php

namespace App\Livewire\Settings;

use App\Actions\Auth\UnlinkSocialAccount;
use App\Http\Controllers\Auth\OAuthController;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\ManagesGitProviderTokens;
use App\Models\GitProviderToken;
use App\Models\SocialAccount;
use App\Modules\SourceControl\Services\GitProviderTokenHealth;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class SourceControl extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesGitProviderTokens;

    public ?string $editingId = null;

    public string $editLabel = '';

    public ?string $editingPatId = null;

    public string $editPatLabel = '';

    /** New token value to swap in for the one being edited (optional). */
    public string $editPatToken = '';

    /** @return list<array<string, mixed>> */
    public function getProvidersProperty(): array
    {
        $enabled = OAuthController::getEnabledProviders();
        $hostFor = fn (string $id): string => match ($id) {
            'github' => 'github.com',
            'gitlab' => 'gitlab.com',
            'bitbucket' => 'bitbucket.org',
            default => '',
        };

        // Even if a provider's OAuth app isn't configured, the operator can
        // still add a PAT — surface all three core hosts when at least one
        // PAT exists for them, otherwise show only enabled OAuth providers.
        $providerIds = array_unique(array_merge(
            array_map(fn ($p) => $p['id'], $enabled),
            ['github', 'gitlab', 'bitbucket'],
        ));

        $names = [
            'github' => 'GitHub',
            'gitlab' => 'GitLab',
            'bitbucket' => 'Bitbucket',
        ];

        $out = [];
        foreach ($providerIds as $id) {
            $accounts = auth()->user()->socialAccounts()->where('provider', $id)->orderBy('id')->get();
            $pats = auth()->user()->gitProviderTokens()->where('provider', $id)->orderBy('id')->get();
            $oauthEnabled = collect($enabled)->contains(fn ($p) => $p['id'] === $id);

            // Skip providers with no OAuth config AND no existing PATs — they
            // shouldn't clutter the page until the operator opts in.
            if (! $oauthEnabled && $pats->isEmpty()) {
                continue;
            }

            $out[] = [
                'id' => $id,
                'name' => $names[$id] ?? ucfirst($id),
                'accounts' => $accounts,
                'pats' => $pats,
                'oauth_enabled' => $oauthEnabled,
                'host' => $hostFor($id),
            ];
        }

        return $out;
    }

    public function startEdit(int|string $accountId): void
    {
        $account = SocialAccount::query()
            ->where('user_id', auth()->id())
            ->findOrFail($accountId);
        $this->editingId = (string) $account->getKey();
        $this->editLabel = $account->label ?? '';
        $this->cancelEditPat();
        $this->cancelAddPat();
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editLabel' => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->editingId === null) {
            return;
        }

        $account = SocialAccount::query()
            ->where('user_id', auth()->id())
            ->findOrFail($this->editingId);

        $account->update([
            'label' => $this->editLabel === '' ? null : $this->editLabel,
        ]);

        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editLabel = '';
    }

    public function unlinkAccount(int|string $accountId): void
    {
        $user = auth()->user();
        $account = SocialAccount::query()
            ->where('user_id', $user->id)
            ->findOrFail($accountId);

        if (! UnlinkSocialAccount::allowed($user)) {
            $this->addError('unlink', UnlinkSocialAccount::denyMessage());

            return;
        }

        $account->delete();
        $this->cancelEdit();
    }

    public function startEditPat(string $patId): void
    {
        $pat = GitProviderToken::query()
            ->where('user_id', auth()->id())
            ->findOrFail($patId);
        $this->editingPatId = $pat->getKey();
        $this->editPatLabel = (string) ($pat->label ?? '');
        $this->editPatToken = '';
        $this->resetErrorBag('editPatToken');
        $this->cancelEdit();
        $this->cancelAddPat();
    }

    public function saveEditPat(): void
    {
        $this->validate([
            'editPatLabel' => ['nullable', 'string', 'max:255'],
            'editPatToken' => ['nullable', 'string', 'min:8', 'max:1024'],
        ]);

        if ($this->editingPatId === null) {
            return;
        }

        $pat = GitProviderToken::query()
            ->where('user_id', auth()->id())
            ->findOrFail($this->editingPatId);

        $data = [
            'label' => $this->editPatLabel === '' ? null : $this->editPatLabel,
        ];

        // Replace-in-place: swapping the token value on the existing row keeps
        // every site pointing at this token working (sites reference it by id
        // via git_source_control_account_id) — unlike remove + re-add, which
        // breaks that linkage. Validate against the provider first so a typo'd
        // token never silently replaces a working one.
        $newToken = trim($this->editPatToken);
        if ($newToken !== '') {
            $base = $this->resolveGitProviderBaseUrl($pat->provider, (string) ($pat->api_base_url ?? ''));
            $result = $this->fetchGitProviderProfile($pat->provider, $base, $newToken);
            if ($result['profile'] === null) {
                $this->addError('editPatToken', $this->describePatRejection($pat->provider, $result));

                return;
            }

            $data['access_token'] = $newToken;
            $data['provider_id'] = $result['profile']['id'] !== '' ? $result['profile']['id'] : $pat->provider_id;
            $data['nickname'] = $result['profile']['nickname'] !== '' ? $result['profile']['nickname'] : $pat->nickname;
            $data['last_validated_at'] = now();
            // The old token's expiry/health no longer applies to the new value.
            $data['expires_at'] = null;
            $data['validation_error'] = null;
        }

        $pat->update($data);

        if ($newToken !== '') {
            // Capture the replacement's real expiry immediately (GitHub sends
            // it in a response header) so the expiring-soon warning stays live.
            app(GitProviderTokenHealth::class)->refresh($pat);
        }

        $this->cancelEditPat();
    }

    public function cancelEditPat(): void
    {
        $this->editingPatId = null;
        $this->editPatLabel = '';
        $this->editPatToken = '';
    }

    /**
     * On-demand re-check of a stored PAT against its provider — the same probe
     * the daily token health check runs, so it stamps last_validated_at,
     * expires_at, and validation_error on the row and the list re-renders
     * with the fresh state.
     */
    public function validatePat(string $patId, GitProviderTokenHealth $health): void
    {
        $pat = GitProviderToken::query()
            ->where('user_id', auth()->id())
            ->findOrFail($patId);

        $result = $health->refresh($pat);

        if ($result === true) {
            $this->toastSuccess(__('Token is valid — the provider accepted it.'));
        } elseif ($result === false) {
            $this->toastError(__('The provider rejected this token — replace it.'));
        } else {
            $this->toastError(__('Could not reach the provider to validate right now — try again shortly.'));
        }
    }

    public function unlinkPat(string $patId): void
    {
        $pat = GitProviderToken::query()
            ->where('user_id', auth()->id())
            ->findOrFail($patId);

        $pat->delete();
        $this->cancelEditPat();
    }

    public function repositoryCount(string $host): int
    {
        if ($host === '') {
            return 0;
        }

        return auth()->user()->gitHostRepositoryCount($host);
    }

    public function render(): View
    {
        return view('livewire.settings.source-control');
    }
}
