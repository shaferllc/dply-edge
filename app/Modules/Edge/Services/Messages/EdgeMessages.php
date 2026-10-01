<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Messages;

use App\Models\EdgeMessageAccount;
use App\Models\EdgeMessageToken;
use App\Models\EdgeMessageUsage;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * dply Messages: an HTTP message queue, run by
 * packages/messages-worker on Cloudflare (delivery on Queues, state in a
 * Durable Object per organization). Behind the `resource-messages` flag.
 *
 * This is the only code that talks to the Worker's operator API: an
 * organization's account (enabled + signing keys), its tokens (stored as a
 * SHA-256 only, shown once), pause/resume with billing, deletion, and the
 * published-message totals billed per 100K (EdgeMessagesCost).
 */
final class EdgeMessages
{
    public const FLAG = 'messages';

    /** The Worker is deployed and dply can manage it. */
    public static function configured(): bool
    {
        return (string) config('edge.messages.url') !== '' && (string) config('edge.messages.operator_token') !== '';
    }

    public static function enabledFor(?Organization $organization): bool
    {
        return $organization !== null && Feature::for($organization)->active(EdgeContainerConnections::flag(self::FLAG));
    }

    /** MESSAGES_URL for the SDKs. */
    public static function url(): string
    {
        return (string) config('edge.messages.url');
    }

    public static function account(Organization $organization): ?EdgeMessageAccount
    {
        return EdgeMessageAccount::query()->where('organization_id', $organization->id)->first();
    }

    /** Turn Messages on: new signing keys, and the account on the Worker. */
    public function enable(Organization $organization): EdgeMessageAccount
    {
        $account = self::account($organization) ?? new EdgeMessageAccount([
            'organization_id' => $organization->id,
            'current_signing_key' => self::newKey(),
            'next_signing_key' => self::newKey(),
        ]);
        $this->pushAccount($organization, $account);
        $account->save();

        return $account;
    }

    /**
     * A token for MESSAGES_TOKEN. Returned once; only its hash is kept, except
     * for an app's token, whose secret is kept encrypted for its deploys.
     *
     * @return array{token: EdgeMessageToken, secret: string}
     */
    public function createToken(Organization $organization, string $label, ?User $by, ?Site $site = null): array
    {
        $secret = 'dmq_'.Str::random(40);
        $hash = hash('sha256', $secret);
        $this->operator()->put('/_operator/tokens/'.$hash, ['org' => $organization->id])->throw();

        return ['token' => EdgeMessageToken::query()->create([
            'organization_id' => $organization->id,
            'site_id' => $site?->id,
            'label' => $label,
            'token_hash' => $hash,
            'last4' => substr($secret, -4),
            'secret' => $site !== null ? $secret : null,
            'created_by' => $by?->id,
        ]), 'secret' => $secret];
    }

    /** The app's own token, made when Messages was attached to it. */
    public static function appToken(Site $site): ?EdgeMessageToken
    {
        return EdgeMessageToken::query()->where('site_id', $site->id)->first();
    }

    /**
     * Attach to an app: Messages on for the organization if it is not yet,
     * and a token for this app. Attaching again keeps the existing token.
     */
    public function connectApp(Site $site, ?User $by): EdgeMessageToken
    {
        $organization = $site->organization ?? throw new \RuntimeException('The app has no organization.');
        if (self::account($organization) === null) {
            $this->enable($organization);
        }

        return self::appToken($site) ?? $this->createToken($organization, $site->name, $by, $site)['token'];
    }

    /** Revoke the app's token: it stops publishing now, not on the next deploy. */
    public function disconnectApp(Site $site): void
    {
        foreach (EdgeMessageToken::query()->where('site_id', $site->id)->get() as $token) {
            self::configured() ? $this->revokeToken($token) : $token->delete();
        }
    }

    /**
     * MESSAGES_* for the app's deploy. Signing
     * keys are the organization's, so a key rotation needs a redeploy.
     *
     * @return array<string, string>
     */
    public static function appEnv(Site $site): array
    {
        $token = self::appToken($site);
        $account = $site->organization ? self::account($site->organization) : null;
        if ($token === null || $account === null || (string) $token->secret === '') {
            return [];
        }

        return [
            'MESSAGES_URL' => self::url(),
            'MESSAGES_TOKEN' => (string) $token->secret,
            'MESSAGES_SIGNING_KEY' => $account->current_signing_key,
            'MESSAGES_NEXT_SIGNING_KEY' => $account->next_signing_key,
        ];
    }

    public function revokeToken(EdgeMessageToken $token): void
    {
        $this->operator()->delete('/_operator/tokens/'.$token->token_hash)->throw();
        $token->delete();
    }

    /**
     * Rotate: the next key becomes current and a new next is made. Receivers
     * that check both (MESSAGES_ / MESSAGES_NEXT_SIGNING_KEY) keep working
     * once they have the new pair.
     */
    public function rotateKeys(Organization $organization): EdgeMessageAccount
    {
        $account = self::account($organization) ?? throw new \RuntimeException('Messages is not on for this organization.');
        $account->forceFill(['current_signing_key' => $account->next_signing_key, 'next_signing_key' => self::newKey()]);
        $this->pushAccount($organization, $account);
        $account->save();

        return $account;
    }

    /** Billing pause and resume: a paused organization cannot publish, and its schedules do not fire. */
    public function setOrganizationEnabled(Organization $organization, bool $enabled): void
    {
        $account = self::account($organization);
        if ($account !== null && self::configured()) {
            $this->pushAccount($organization, $account, $enabled);
        }
    }

    /**
     * Write every organization's account and every token to the Worker again,
     * e.g. after it moves to a new (KV Instant) ACCOUNTS namespace.
     *
     * @return array{accounts: int, tokens: int}
     */
    public function resyncAll(): array
    {
        $accounts = 0;
        $tokens = 0;
        foreach (EdgeMessageAccount::query()->get() as $account) {
            $organization = Organization::query()->find($account->organization_id);
            if ($organization === null) {
                continue;
            }
            $this->pushAccount($organization, $account);
            $accounts++;
        }
        foreach (EdgeMessageToken::query()->get() as $token) {
            $this->operator()->put('/_operator/tokens/'.$token->token_hash, ['org' => $token->organization_id])->throw();
            $tokens++;
        }

        return ['accounts' => $accounts, 'tokens' => $tokens];
    }

    /** Delete everything: messages, schedules, the DLQ, tokens and keys. */
    public function destroy(Organization $organization): void
    {
        if (self::configured()) {
            foreach (EdgeMessageToken::query()->where('organization_id', $organization->id)->get() as $token) {
                $this->operator()->delete('/_operator/tokens/'.$token->token_hash)->throw();
            }
            $this->operator()->delete('/_operator/orgs/'.$organization->id)->throw();
        }
        EdgeMessageToken::query()->where('organization_id', $organization->id)->delete();
        EdgeMessageAccount::query()->where('organization_id', $organization->id)->delete();
    }

    /**
     * Add each organization's newly published messages to today's usage row.
     * The Worker's totals only go up; the last one seen is on the account.
     *
     * @return int organizations updated
     */
    public function collectUsage(): int
    {
        if (! self::configured()) {
            return 0;
        }
        $updated = 0;
        foreach (EdgeMessageAccount::query()->get() as $account) {
            try {
                $total = (int) ($this->operator()->get('/_operator/orgs/'.$account->organization_id.'/usage')->throw()->json('published') ?? 0);
            } catch (Throwable $e) {
                Log::warning('Messages usage: could not read an organization', ['organization_id' => $account->organization_id, 'error' => $e->getMessage()]);

                continue;
            }
            $added = $total >= $account->published_counter ? $total - $account->published_counter : $total;
            if ($added === 0) {
                continue;
            }
            DB::transaction(function () use ($account, $added, $total): void {
                $row = EdgeMessageUsage::query()->firstOrCreate(
                    ['organization_id' => $account->organization_id, 'date' => now()->utc()->toDateString()],
                    ['messages' => 0],
                );
                $row->increment('messages', $added);
                $account->forceFill(['published_counter' => $total])->save();
            });
            $updated++;
        }

        return $updated;
    }

    private function pushAccount(Organization $organization, EdgeMessageAccount $account, ?bool $enabled = null): void
    {
        $this->operator()->put('/_operator/orgs/'.$organization->id, [
            'enabled' => $enabled ?? $organization->billing_paused_at === null,
            'currentSigningKey' => $account->current_signing_key,
            'nextSigningKey' => $account->next_signing_key,
        ])->throw();
    }

    private static function newKey(): string
    {
        return 'sig_'.Str::random(40);
    }

    private function operator(): PendingRequest
    {
        return Http::baseUrl(self::url())->withToken((string) config('edge.messages.operator_token'))->acceptJson()->timeout(15);
    }
}
