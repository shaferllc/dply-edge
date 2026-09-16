<?php

namespace App\Http\Controllers\Credentials;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Modules\Providers\Services\DigitalOceanService;
use App\Support\ServerProviderGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProviderOAuthController extends Controller
{
    public function redirectDigitalOcean(Request $request): RedirectResponse
    {
        if (! ServerProviderGate::enabled('digitalocean')) {
            return redirect()
                ->route('credentials.index')
                ->with('error', __('This provider is not available yet.'));
        }

        if (! $this->digitalOceanOAuthConfigured()) {
            return redirect()
                ->route('credentials.index', ['provider' => 'digitalocean'])
                ->with('error', __('DigitalOcean sign-in is not available. Add an API token instead, or ask your administrator to configure OAuth.'));
        }

        $this->authorize('create', ProviderCredential::class);

        $user = $request->user();
        $org = $user->currentOrganization();
        if (! $org instanceof Organization) {
            return redirect()
                ->route('organizations.index')
                ->with('error', __('Select or create an organization before connecting a provider.'));
        }

        if ($org->userIsDeployer($user)) {
            abort(403);
        }

        $label = $request->string('label')->trim()->toString();
        if (strlen($label) > 255) {
            $label = substr($label, 0, 255);
        }

        $nonce = Str::random(40);
        $request->session()->put($this->digitalOceanStateSessionKey($nonce), [
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'label' => $label !== '' ? $label : null,
            'issued_at' => now()->timestamp,
        ]);

        $query = http_build_query([
            'client_id' => config('services.digitalocean_oauth.client_id'),
            'redirect_uri' => $this->digitalOceanRedirectUri(),
            'response_type' => 'code',
            'scope' => 'read write',
            'state' => $nonce,
        ]);

        return redirect('https://cloud.digitalocean.com/v1/oauth/authorize?'.$query);
    }

    public function callbackDigitalOcean(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            $message = $request->string('error_description')->toString()
                ?: $request->string('error')->toString()
                ?: __('Authorization was denied or failed.');

            return redirect()
                ->route('credentials.index', ['provider' => 'digitalocean'])
                ->with('error', $message);
        }

        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string|size:40',
        ]);

        $nonce = $request->string('state')->toString();
        $payload = $request->session()->pull($this->digitalOceanStateSessionKey($nonce));

        if (! is_array($payload)) {
            return redirect()
                ->route('credentials.index', ['provider' => 'digitalocean'])
                ->with('error', __('Invalid or expired OAuth state. Please try again.'));
        }

        $userId = $payload['user_id'] ?? null;
        $organizationId = $payload['organization_id'] ?? null;
        $issuedAt = $payload['issued_at'] ?? 0;

        if ((! is_int($userId) && ! (is_string($userId) && $userId !== ''))
            || ! is_string($organizationId) || $organizationId === ''
            || (! is_int($issuedAt) && ! (is_string($issuedAt) && ctype_digit($issuedAt)))) {
            return redirect()
                ->route('credentials.index', ['provider' => 'digitalocean'])
                ->with('error', __('Invalid or expired OAuth state. Please try again.'));
        }

        $issuedAt = (int) $issuedAt;

        if (now()->timestamp - $issuedAt > 900) {
            return redirect()
                ->route('credentials.index', ['provider' => 'digitalocean'])
                ->with('error', __('That sign-in link expired. Please try again.'));
        }

        $user = $request->user();
        if (! $user || (string) $user->id !== (string) $userId) {
            return redirect()
                ->route('login')
                ->with('error', __('Your session changed during sign-in. Please sign in and connect again.'));
        }

        $org = Organization::query()->find($organizationId);
        if (! $org || ! $org->hasMember($user) || $org->userIsDeployer($user)) {
            return redirect()
                ->route('organizations.credentials', ['organization' => $organizationId, 'provider' => 'digitalocean'])
                ->with('error', __('You cannot add credentials for that organization.'));
        }

        $this->authorize('create', ProviderCredential::class);

        if (! $this->digitalOceanOAuthConfigured()) {
            return redirect()
                ->route('organizations.credentials', ['organization' => $org, 'provider' => 'digitalocean'])
                ->with('error', __('DigitalOcean OAuth is not configured.'));
        }

        $tokenResponse = Http::asForm()
            ->acceptJson()
            ->post('https://cloud.digitalocean.com/v1/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $request->string('code'),
                'client_id' => config('services.digitalocean_oauth.client_id'),
                'client_secret' => config('services.digitalocean_oauth.client_secret'),
                'redirect_uri' => $this->digitalOceanRedirectUri(),
            ]);

        if (! $tokenResponse->successful()) {
            $detail = $tokenResponse->json('error_description')
                ?? $tokenResponse->json('message')
                ?? $tokenResponse->body();

            return redirect()
                ->route('organizations.credentials', ['organization' => $org, 'provider' => 'digitalocean'])
                ->with('error', __('Could not complete DigitalOcean sign-in: :detail', ['detail' => is_string($detail) ? $detail : __('unknown error')]));
        }

        $accessToken = $tokenResponse->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            return redirect()
                ->route('organizations.credentials', ['organization' => $org, 'provider' => 'digitalocean'])
                ->with('error', __('DigitalOcean did not return an access token.'));
        }

        $refreshToken = $tokenResponse->json('refresh_token');
        $expiresIn = (int) $tokenResponse->json('expires_in', 3600);

        $label = $payload['label'] ?? null;
        $name = is_string($label) && $label !== '' ? $label : 'DigitalOcean';

        $credentials = [
            'auth' => 'oauth',
            'access_token' => $accessToken,
            'refresh_token' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            'expires_at' => now()->addSeconds(max(60, $expiresIn))->toIso8601String(),
        ];

        $credential = $user->providerCredentials()->create([
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'name' => $name,
            'credentials' => $credentials,
        ]);

        audit_log($org, $user, 'credential.created', $credential, null, [
            'provider' => 'digitalocean',
            'name' => $name,
            'auth' => 'oauth',
        ]);

        try {
            (new DigitalOceanService($credential))->getDroplets();
        } catch (\Throwable $e) {
            audit_log($org, $user, 'credential.verify_failed', $credential, null, [
                'provider' => 'digitalocean',
                'error' => $e->getMessage(),
            ]);
            $credential->delete();

            return redirect()
                ->route('organizations.credentials', ['organization' => $org, 'provider' => 'digitalocean'])
                ->with('error', __('Connected account could not use the API: :message', ['message' => $e->getMessage()]));
        }

        audit_log($org, $user, 'credential.verified', $credential, null, [
            'provider' => 'digitalocean',
            'auth' => 'oauth',
        ]);

        return redirect()
            ->route('organizations.credentials', ['organization' => $org, 'provider' => 'digitalocean'])
            ->with('success', __('DigitalOcean connected.'));
    }

    protected function digitalOceanOAuthConfigured(): bool
    {
        $id = config('services.digitalocean_oauth.client_id');
        $secret = config('services.digitalocean_oauth.client_secret');

        return is_string($id) && $id !== '' && is_string($secret) && $secret !== '';
    }

    protected function digitalOceanRedirectUri(): string
    {
        $configured = config('services.digitalocean_oauth.redirect');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return route('credentials.oauth.digitalocean.callback', [], true);
    }

    protected function digitalOceanStateSessionKey(string $nonce): string
    {
        return 'credentials_oauth_digitalocean_'.$nonce;
    }
}
