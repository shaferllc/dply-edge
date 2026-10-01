<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire;

use App\Models\EdgeMessageToken;
use App\Models\Organization;
use App\Modules\Billing\Services\EdgeMessagesCost;
use App\Modules\Billing\Support\UsagePrice;
use App\Modules\Edge\Services\Messages\EdgeMessages;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Throwable;

/**
 * Projects → Messages: dply's HTTP message queue for the
 * current organization, behind the resource-messages flag. Turn it on,
 * make tokens (shown once), see and rotate the signing keys, and copy the
 * MESSAGES_* env for publishing and verifying.
 */
#[Layout('layouts.app')]
class Messages extends Component
{
    public string $label = '';

    public function mount(): void
    {
        abort_unless(EdgeMessages::enabledFor($this->organization()), 404);
    }

    public function enable(EdgeMessages $messages): void
    {
        $org = $this->organization();
        $this->authorize('update', $org);
        try {
            $messages->enable($org);
            audit_log($org, auth()->user(), 'messages.enabled', null, null, []);
        } catch (Throwable $e) {
            report($e);
            $this->addError('messages', __('Messages could not be turned on. Try again in a minute.'));
        }
    }

    /**
     * A new token, returned once to Alpine; only its hash is kept.
     *
     * @return array{id?: string, secret?: string, error?: string}
     */
    #[Renderless]
    public function createToken(EdgeMessages $messages): array
    {
        $org = $this->organization();
        $this->authorize('update', $org);
        $label = trim($this->label);
        if ($label === '' || mb_strlen($label) > 60) {
            return ['error' => __('Name the token, like “Production app”.')];
        }
        try {
            ['token' => $token, 'secret' => $secret] = $messages->createToken($org, $label, auth()->user());
        } catch (Throwable $e) {
            report($e);

            return ['error' => __('The token could not be made. Try again in a minute.')];
        }
        audit_log($org, auth()->user(), 'messages.token_created', null, null, ['label' => $label, 'last4' => $token->last4]);

        return ['id' => $token->id, 'secret' => $secret];
    }

    public function revokeToken(string $id, EdgeMessages $messages): void
    {
        $org = $this->organization();
        $this->authorize('update', $org);
        $token = EdgeMessageToken::query()->where('organization_id', $org->id)->findOrFail($id);
        try {
            $messages->revokeToken($token);
        } catch (Throwable $e) {
            report($e);
            $this->addError('messages', __('The token could not be revoked. Try again in a minute.'));

            return;
        }
        audit_log($org, auth()->user(), 'messages.token_revoked', null, null, ['label' => $token->label, 'last4' => $token->last4]);
    }

    /**
     * The signing keys, for MESSAGES_ / MESSAGES_NEXT_SIGNING_KEY. Straight to Alpine.
     *
     * @return array{current: string, next: string}
     */
    #[Renderless]
    public function signingKeys(): array
    {
        $org = $this->organization();
        $this->authorize('update', $org);
        $account = EdgeMessages::account($org);

        return ['current' => (string) $account?->current_signing_key, 'next' => (string) $account?->next_signing_key];
    }

    public function rotateKeys(EdgeMessages $messages): void
    {
        $org = $this->organization();
        $this->authorize('update', $org);
        try {
            $messages->rotateKeys($org);
            audit_log($org, auth()->user(), 'messages.keys_rotated', null, null, []);
        } catch (Throwable $e) {
            report($e);
            $this->addError('messages', __('The keys could not be rotated. Try again in a minute.'));
        }
    }

    public function render(): View
    {
        $org = $this->organization();
        $usage = app(EdgeMessagesCost::class)->forOrganization($org, now()->startOfMonth(), now()->endOfMonth());

        return view('livewire.edge.messages', [
            'configured' => EdgeMessages::configured(),
            'account' => EdgeMessages::account($org),
            'url' => EdgeMessages::url(),
            'tokens' => EdgeMessageToken::query()->where('organization_id', $org->id)->latest()->get(),
            'usage' => $usage,
            'price' => UsagePrice::dollars(UsagePrice::rate('messages_millicents_per_hundred_thousand')),
        ]);
    }

    private function organization(): Organization
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        return $org;
    }
}
