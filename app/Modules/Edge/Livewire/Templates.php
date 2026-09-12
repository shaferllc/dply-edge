<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire;

use App\Modules\Edge\Services\EdgeTemplateRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Read-only gallery of "Deploy with dply" starter templates. Click a
 * card → hand-off to /edge/create with the template's repo +
 * framework + runtime mode pre-filled. Nothing is created on dply
 * until the user confirms in the Create form.
 *
 * The gallery itself is a public marketing surface — anyone who can
 * reach /edge can browse it. Persistence happens later (in Create),
 * so unauthenticated visitors who click a "Deploy" button get the
 * normal sign-up wall before anything touches the database.
 */
class Templates extends Component
{
    public string $filterTag = '';

    public function mount(): void
    {
    }

    public function setFilter(string $tag): void
    {
        $this->filterTag = $tag === $this->filterTag ? '' : $tag;
    }

    public function render(): View
    {
        $templates = collect(EdgeTemplateRegistry::all());
        if ($this->filterTag !== '') {
            $templates = $templates->filter(
                fn (array $template): bool => in_array($this->filterTag, (array) ($template['tags'] ?? []), true),
            );
        }

        return view('livewire.edge.templates', [
            'templates' => $templates->values()->all(),
            'tags' => EdgeTemplateRegistry::allTags(),
        ]);
    }
}
