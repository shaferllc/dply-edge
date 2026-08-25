<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\BuildsCommandPaletteGroups;
use App\Livewire\Concerns\ManagesCommandPaletteStack;
use App\Livewire\Concerns\ResolvesCommandPaletteItems;
use App\Livewire\Concerns\RunsCommandPaletteActions;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Global Cmd/Ctrl+K command palette.
 *
 * Mounted once on every authenticated page (see layouts/app.blade.php). The
 * palette is *nestable*: a server-side {@see $stack} of contexts lets the
 * operator drill from a category (Sites, Servers, Settings…) into its members,
 * and from a single site/server into that resource's own sub-pages. Leaf rows
 * carry a `url` and navigate; nestable rows carry an `into` and push a context.
 *
 * The Alpine view owns open/close, keyboard navigation and the final click;
 * Livewire owns the stack, the query and the org-scoped lookups.
 */
class CommandPalette extends Component
{
    use BuildsCommandPaletteGroups;
    use ManagesCommandPaletteStack;
    use ResolvesCommandPaletteItems;
    use RunsCommandPaletteActions;

    /** The live search query, bound from the palette input. */
    public string $query = '';

    /**
     * The drill-down context stack. Empty = root. Each entry is the context
     * the operator navigated into.
     *
     * @var list<array{type: string, id: ?string, label: string}>
     */
    public array $stack = [];

    /**
     * The resource the current page is *about*, captured at mount from the
     * route. The palette opens drilled into it (so "Deploy" is one keystroke
     * from any site page) and returns to it on close. Null on pages that aren't
     * a single site/server.
     *
     * @var array{type: string, id: string, label: string}|null
     */
    public ?array $contextSeed = null;

    /**
     * The documentation slug most relevant to the page the palette opened on,
     * resolved at mount from the route + section. Lets the palette offer "the
     * guide for *this* page" no matter how deep you've drilled. Null off any
     * documented page.
     */

    /**
     * Capture the current page's resource so the palette opens in context.
     * Runs once per page render (the palette re-mounts on each wire:navigate, so
     * this stays in step with navigation). Route-model binding means the `site`
     * / `server` route parameters are already-resolved models. The seed is a
     * starting point only — every action re-resolves org-scoped and re-authorizes.
     *
     * NB: this reads the live route, so the component must NOT be #[Lazy] — a
     * lazy-load request carries the Livewire endpoint route, not the page route,
     * which would drop the ⌘K page context. (It's also a global overlay that
     * registers its own keydown/open listeners in Alpine init, so it must be
     * present on initial render, not deferred.)
     */
    public function mount(): void
    {
        $route = request()->route();
        if ($route === null) {
            return;
        }

        $site = $route->parameter('site');
        $server = $route->parameter('server');

        if ($site instanceof Site) {
            $this->contextSeed = ['type' => 'site', 'id' => (string) $site->getKey(), 'label' => (string) $site->name];
        } elseif ($server instanceof Server) {
            $this->contextSeed = ['type' => 'server', 'id' => (string) $server->getKey(), 'label' => (string) $server->name];
        }

        if ($this->contextSeed !== null) {
            $this->stack = [$this->contextSeed];
        }
    }

    public function render(): View
    {
        $context = $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
        $placeholder = $context !== null
            ? __('Search :context…', ['context' => $context['label']])
            : __('Search sites, servers, projects…');

        return view('livewire.command-palette', [
            'groups' => $this->groups($context),
            'stack' => $this->stack,
            'placeholder' => $placeholder,
        ]);
    }
}
