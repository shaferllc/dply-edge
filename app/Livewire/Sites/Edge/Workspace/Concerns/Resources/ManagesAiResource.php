<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace\Concerns\Resources;

use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Resources sheet: AI (Workers AI) and Images. Mixed into
 * {@see Resources}; the open connection
 * is $this->openResourceConnection() (set by openResource()).
 */
trait ManagesAiResource
{
    /**
     * A few well-known models, as examples. Any Workers AI model id works
     * from the app; the demo only runs these.
     *
     * @var array<string, array{label: string, kind: string}>
     */
    public const AI_MODELS = [
        '@cf/meta/llama-3.1-8b-instruct' => ['label' => 'Llama 3.1 8B Instruct', 'kind' => 'text'],
        '@cf/meta/llama-3.2-3b-instruct' => ['label' => 'Llama 3.2 3B Instruct', 'kind' => 'text'],
        '@cf/mistral/mistral-7b-instruct-v0.1' => ['label' => 'Mistral 7B Instruct', 'kind' => 'text'],
        '@cf/baai/bge-base-en-v1.5' => ['label' => 'BGE base (embeddings)', 'kind' => 'embedding'],
    ];

    public string $aiModel = '@cf/meta/llama-3.1-8b-instruct';

    public string $aiPrompt = 'Say hello in five words.';

    public string $aiDemoResult = '';

    /** @var list<string> */
    public array $aiDemoLog = [];

    public function runAiDemo(): void
    {
        $this->authorize('update', $this->site);
        $this->aiDemoResult = '';
        $this->aiDemoLog = [];
        $this->resetErrorBag('aiDemo');

        $connection = $this->openResourceConnection();
        $model = self::AI_MODELS[$this->aiModel] ?? null;
        $prompt = trim($this->aiPrompt);
        if ($connection === null || $connection['kind'] !== 'ai' || $model === null) {
            return;
        }
        if ($prompt === '' || mb_strlen($prompt) > 2000) {
            $this->addError('aiDemo', __('Write a prompt under 2,000 characters.'));

            return;
        }
        // The demo runs on the platform account, so keep it to a few calls.
        if (! RateLimiter::attempt('edge-ai-demo:'.$this->site->id, 10, static fn (): bool => true, 60)) {
            $this->addError('aiDemo', __('Too many tries. Wait a minute and run it again.'));

            return;
        }

        $input = $model['kind'] === 'embedding' ? ['text' => [$prompt]] : ['prompt' => $prompt, 'max_tokens' => 256];
        $this->aiDemoLog = [
            __('This demo runs the model from here. It does not call this app.'),
            __('The app sends POST http://:host/run with {"model":":model","input":…}.', ['host' => $connection['host'], 'model' => $this->aiModel]),
        ];

        try {
            $result = EdgeCloudflareClient::fromConfig()->runAi($this->aiModel, $input);
        } catch (\Throwable $e) {
            $this->aiDemoLog[] = __('Stopped: :message', ['message' => $e->getMessage()]);
            $this->addError('aiDemo', __('The model did not answer.'));

            return;
        }

        if ($model['kind'] === 'embedding') {
            $vector = is_array($result['data'][0] ?? null) ? $result['data'][0] : [];
            $this->aiDemoLog[] = __('The model returned a vector of :count numbers.', ['count' => count($vector)]);
            $this->aiDemoResult = '['.implode(', ', array_map(static fn ($n): string => (string) round((float) $n, 4), array_slice($vector, 0, 8))).(count($vector) > 8 ? ', …]' : ']');

            return;
        }

        $this->aiDemoLog[] = __('The model answered.');
        $this->aiDemoResult = mb_substr(is_string($result['response'] ?? null) ? $result['response'] : (string) json_encode($result), 0, 3000);
    }
}
