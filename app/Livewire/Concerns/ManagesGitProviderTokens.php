<?php

namespace App\Livewire\Concerns;

use App\Models\GitProviderToken;
use App\Modules\SourceControl\Services\GitProviderTokenHealth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait ManagesGitProviderTokens
{
    public ?string $addingPatProvider = null;

    public string $patLabel = '';

    public string $patToken = '';

    public string $patApiBaseUrl = '';

    public function startAddPat(string $provider): void
    {
        if (! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            return;
        }

        $this->cancelEdit();
        $this->cancelEditPat();

        $this->addingPatProvider = $provider;
        $this->patLabel = '';
        $this->patToken = '';
        $this->patApiBaseUrl = '';
        $this->resetErrorBag(['patLabel', 'patToken', 'patApiBaseUrl']);
    }

    public function cancelAddPat(): void
    {
        $this->addingPatProvider = null;
        $this->patLabel = '';
        $this->patToken = '';
        $this->patApiBaseUrl = '';
    }

    public function savePat(): void
    {
        $provider = $this->addingPatProvider;
        if (! is_string($provider) || ! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            return;
        }

        $this->validate([
            'patLabel' => ['nullable', 'string', 'max:255'],
            'patToken' => ['required', 'string', 'min:8', 'max:1024'],
            'patApiBaseUrl' => ['nullable', 'url', 'max:255'],
        ]);

        $token = trim($this->patToken);
        $base = $this->resolveGitProviderBaseUrl($provider, $this->patApiBaseUrl);
        $result = $this->fetchGitProviderProfile($provider, $base, $token);
        if ($result['profile'] === null) {
            $this->addError('patToken', $this->describePatRejection($provider, $result));

            return;
        }

        $created = GitProviderToken::create([
            'user_id' => auth()->id(),
            'provider' => $provider,
            'provider_id' => $result['profile']['id'],
            'label' => $this->patLabel === '' ? null : $this->patLabel,
            'nickname' => $result['profile']['nickname'],
            'access_token' => $token,
            'api_base_url' => trim($this->patApiBaseUrl) !== '' ? rtrim(trim($this->patApiBaseUrl), '/') : null,
            'last_validated_at' => now(),
        ]);

        // Capture the provider's REAL expiry right away (GitHub reports it in
        // a response header) so the expiring-soon warning works from day one.
        app(GitProviderTokenHealth::class)->refresh($created);

        $this->cancelAddPat();
        $this->afterGitProviderTokenSaved($provider);
    }

    /**
     * Turn the captured HTTP error into a message that tells the operator
     * what to fix. The biggest footgun is a fine-grained GitHub PAT without
     * "Read access to profile information" — the most common shape.
     *
     * @param  array{profile: array<string, mixed>|null, status: int|null, message: string|null}  $result
     */
    private function describePatRejection(string $provider, array $result): string
    {
        $providerLabel = ucfirst($provider);
        $status = $result['status'];
        $message = $result['message'];

        if ($status === 401) {
            return __(':provider returned 401 Unauthorized — the token is invalid or expired. Generate a new one and paste it here.', ['provider' => $providerLabel]);
        }

        if ($provider === 'github' && in_array($status, [403, 404], true)) {
            return __(':provider rejected the token (HTTP :status). If this is a fine-grained PAT, enable Account permissions → "Read access to profile information" on the token, or use a classic PAT with the "repo" scope.', [
                'provider' => $providerLabel,
                'status' => (string) $status,
            ]);
        }

        if ($status !== null) {
            $detail = $message ? ' — '.Str::limit($message, 140) : '';

            return __(':provider rejected the token (HTTP :status):detail', [
                'provider' => $providerLabel,
                'status' => (string) $status,
                'detail' => $detail,
            ]);
        }

        if ($message !== null && $message !== '') {
            return __(':provider could not validate the token: :detail', [
                'provider' => $providerLabel,
                'detail' => Str::limit($message, 160),
            ]);
        }

        return __('The :provider rejected the token. Check the value and the scopes/permissions, then try again.', ['provider' => $providerLabel]);
    }

    protected function afterGitProviderTokenSaved(string $provider): void
    {
        //
    }

    protected function cancelEdit(): void
    {
        //
    }

    protected function cancelEditPat(): void
    {
        //
    }

    private function resolveGitProviderBaseUrl(string $provider, string $userInput): string
    {
        $custom = trim($userInput);
        if ($custom !== '') {
            return rtrim($custom, '/');
        }

        return match ($provider) {
            'github' => 'https://api.github.com',
            'gitlab' => 'https://gitlab.com',
            'bitbucket' => 'https://api.bitbucket.org',
            default => '',
        };
    }

    /**
     * Fetch the authenticated user profile for a PAT. Returns the profile if
     * the token is valid, plus the captured HTTP status + provider message so
     * the caller can describe the failure.
     *
     * For GitHub specifically, when /user returns 403/404 (the canonical
     * fine-grained PAT failure mode — token doesn't have profile-read), we
     * fall back to /user/repos. A 200 there confirms the token is valid AND
     * has at least repository read access, which is what dply actually needs.
     *
     * @return array{
     *   profile: array{id: string, nickname: string}|null,
     *   status: int|null,
     *   message: string|null
     * }
     */
    private function fetchGitProviderProfile(string $provider, string $base, string $token): array
    {
        try {
            $url = match ($provider) {
                'github' => $base.'/user',
                'gitlab' => $base.'/api/v4/user',
                'bitbucket' => $base.'/2.0/user',
                default => null,
            };
            if ($url === null) {
                return ['profile' => null, 'status' => null, 'message' => null];
            }

            $request = Http::withToken($token)->acceptJson();
            if ($provider === 'github') {
                $request = $request->withHeaders([
                    'User-Agent' => 'Dply (pat-validate)',
                    'Accept' => 'application/vnd.github+json',
                ]);
            }

            $response = $request->get($url);

            if (! $response->successful()) {
                // Fine-grained GitHub PATs that lack "Read access to profile
                // information" (the default!) return 403 here. Fall back to
                // /user/repos — a token that can list repos is good enough.
                if ($provider === 'github' && in_array($response->status(), [403, 404], true)) {
                    $reposResponse = $request->get($base.'/user/repos', ['per_page' => 1]);
                    if ($reposResponse->successful()) {
                        $repos = is_array($reposResponse->json()) ? $reposResponse->json() : [];
                        $owner = is_array($repos[0]['owner'] ?? null) ? $repos[0]['owner'] : null;

                        return [
                            'profile' => [
                                'id' => $owner !== null && isset($owner['id']) ? (string) $owner['id'] : 'fg-'.substr(hash('sha256', $token), 0, 16),
                                'nickname' => (string) ($owner['login'] ?? __('fine-grained PAT')),
                            ],
                            'status' => $reposResponse->status(),
                            'message' => null,
                        ];
                    }
                }

                $body = $response->json();
                $message = is_array($body) ? (string) ($body['message'] ?? $body['error'] ?? '') : '';

                return [
                    'profile' => null,
                    'status' => $response->status(),
                    'message' => $message !== '' ? $message : null,
                ];
            }

            $body = is_array($response->json()) ? $response->json() : [];

            $profile = match ($provider) {
                'github' => [
                    'id' => isset($body['id']) ? (string) $body['id'] : '',
                    'nickname' => (string) ($body['login'] ?? $body['name'] ?? ''),
                ],
                'gitlab' => [
                    'id' => isset($body['id']) ? (string) $body['id'] : '',
                    'nickname' => (string) ($body['username'] ?? $body['name'] ?? ''),
                ],
                'bitbucket' => [
                    'id' => (string) ($body['account_id'] ?? $body['uuid'] ?? ''),
                    'nickname' => (string) ($body['username'] ?? $body['display_name'] ?? ''),
                ],
                default => null,
            };

            return ['profile' => $profile, 'status' => $response->status(), 'message' => null];
        } catch (\Throwable $e) {
            return ['profile' => null, 'status' => null, 'message' => $e->getMessage()];
        }
    }
}
