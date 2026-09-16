<?php

declare(strict_types=1);

namespace Tests\Feature\LivewireAliasGuardTest;

use Livewire\Component as LivewireComponent;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;
use Symfony\Component\Finder\Finder;

/**
 * Guard for the modular-monolith migration (docs/adr/modular-monolith-structure.md).
 *
 * Moving a Livewire component class into app/Modules/<X>/Livewire stops Livewire's
 * auto-discovery, so its kebab alias must be re-registered by the module's
 * ServiceProvider. This test asserts that every alias referenced from Blade still
 * resolves to a registered component — turning a broken `<livewire:...>` reference
 * from a production 404 into a red bar. It must be green on the current layout and
 * stay green through every module move.
 *
 * It discovers aliases dynamically from the view tree, so new components are covered
 * automatically and no hand-maintained list can drift.
 */

/**
 * JS/Alpine events that share the `livewire:` prefix but are NOT components.
 * Defensive — these appear as `livewire:init` (no `<`), not as Blade tags, but we
 * exclude them so a stray match can never produce a false failure.
 */
const NON_COMPONENT_TOKENS = [
    'init', 'navigate', 'navigated', 'navigating', 'offline', 'online', 'load',
    'dynamic-component', // resolved at runtime via :is, not a static alias
];

/** @return array<int, array{alias: string, file: string}> */
function livewireAliasReferences(): array
{
    $refs = [];

    /*
     * Scan the shell's view tree AND every module-owned one
     * (app/Modules/<X>/resources/views, registered via loadViewsFrom).
     *
     * Modules were originally out of scope here, which defeated the guard for
     * exactly the case it exists to catch: a module component is the one whose
     * auto-discovery breaks. app/Modules/TaskRunner/resources/views referenced
     * `task-monitor` with no registration at all, and every route rendering it
     * 500'd in production unnoticed.
     */
    $roots = [resource_path('views')];

    foreach (glob(base_path('app/Modules/*/resources/views'), GLOB_ONLYDIR) ?: [] as $moduleViews) {
        $roots[] = $moduleViews;
    }

    $finder = (new Finder)
        ->files()
        ->in($roots)
        ->name('*.blade.php');

    foreach ($finder as $file) {
        $contents = $file->getContents();
        // Relative-to-base keeps module paths distinguishable in failure output.
        $relative = str_replace(base_path().'/', '', (string) $file->getRealPath());

        // <livewire:alias ...>  and  <livewire:alias />
        preg_match_all('/<livewire:([a-z0-9][a-z0-9._:-]*)/i', $contents, $tagMatches);
        // @livewire('alias')  and  @livewire("alias")  — literal string only
        preg_match_all('/@livewire\(\s*[\'"]([a-z0-9][a-z0-9._:-]*)[\'"]/i', $contents, $directiveMatches);

        foreach ([...$tagMatches[1], ...$directiveMatches[1]] as $alias) {
            if (in_array($alias, NON_COMPONENT_TOKENS, true)) {
                continue;
            }

            $refs[] = ['alias' => $alias, 'file' => $relative];
        }
    }

    return $refs;
}

test('every Livewire alias referenced in Blade resolves to a registered component', function () {
    $references = livewireAliasReferences();

    // Sanity: the view tree should contain plenty of references. If this drops to
    // zero the grep broke (e.g. Blade syntax changed), which would silently void
    // the guard — fail loudly instead.
    Assert::assertNotEmpty(
        $references,
        'No <livewire:...> / @livewire(...) references found — the alias guard regex likely needs updating.',
    );

    $broken = [];

    foreach ($references as $ref) {
        if (! Livewire::exists($ref['alias'])) {
            $broken[$ref['alias']][] = $ref['file'];
        }
    }

    $report = collect($broken)
        ->map(fn (array $files, string $alias): string => sprintf(
            '  - %s  (referenced in: %s)',
            $alias,
            implode(', ', array_unique($files)),
        ))
        ->implode("\n");

    Assert::assertSame(
        [],
        $broken,
        "These Livewire aliases are referenced in Blade but do not resolve to a registered component.\n"
            ."If you just moved a component into a module, register its alias in that module's ServiceProvider:\n\n"
            .$report,
    );
});

/**
 * Companion guard for full-page Livewire route components.
 *
 * Blade-embedded aliases (above) are not the only way a moved component breaks:
 * full-page route components (Route::livewire(...) or an invokable Component class
 * on a route) are resolved by *class* at render time, and Livewire derives a name
 * from that class. Once the class lives outside App\Livewire that derivation fails
 * with ComponentNotFoundException — but route:list still resolves the route, so
 * nothing catches it short of an HTTP render. This enumerates the full-page
 * components from the route table and asserts each resolves, the same way the
 * framework does when serving the page.
 *
 * Scoped to App\Modules\* components on purpose: this guards the *migration* risk
 * (a component we relocated into a module). It deliberately does NOT resolve
 * arbitrary app/Livewire components — some may carry unrelated compile-time fatals
 * (e.g. trait collisions) that are uncatchable and would crash the runner.
 *
 * @return array<string, string> component class => route uri
 */
function fullPageRouteComponents(): array
{
    $components = [];

    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        $action = $route->getAction();
        $uses = $action['uses'] ?? null;

        // Case 2: Route::livewire(...) stores the component class on the action.
        if (array_key_exists('livewire_component', $action)) {
            $component = $action['livewire_component'];

            if (is_string($component) && str_starts_with($component, 'App\\Modules\\')) {
                $components[$component] = $route->uri();
            }

            continue;
        }

        // Case 1: an invokable Livewire Component used directly as a route action.
        if (is_string($uses) && str_ends_with($uses, '@__invoke')) {
            $class = explode('@', $uses)[0];

            if (str_starts_with($class, 'App\\Modules\\')
                && class_exists($class)
                && is_subclass_of($class, LivewireComponent::class)) {
                $components[$class] = $route->uri();
            }
        }
    }

    return $components;
}

test('every full-page Livewire route component resolves to a registered component', function () {
    $components = fullPageRouteComponents();

    // Guard the guard: if this finds nothing, route enumeration or Livewire's
    // action shape changed and the check has quietly stopped protecting anything.
    Assert::assertNotEmpty(
        $components,
        'No full-page Livewire route components found — the route-enumeration logic likely needs updating.',
    );

    // Resolve the class the way the router does when binding the page (see
    // SupportPageComponents::routeActionIsAPageComponent) — WITHOUT instantiating,
    // so heavy page components (dashboards, etc.) aren't booted just to check them.
    $factory = app('livewire.factory');
    $broken = [];

    foreach ($components as $component => $uri) {
        try {
            $factory->resolveComponentClass($component);
        } catch (ComponentNotFoundException) {
            $broken[$component] = $uri;
        }
    }

    $report = collect($broken)
        ->map(fn (string $uri, string $component): string => sprintf('  - %s  (route: /%s)', $component, $uri))
        ->implode("\n");

    Assert::assertSame(
        [],
        $broken,
        "These full-page route components do not resolve at render time.\n"
            ."A component moved out of App\\Livewire must be registered in its module ServiceProvider\n"
            ."under its original name, e.g. Livewire::component('roadmap.index', RoadmapIndex::class):\n\n"
            .$report,
    );
});
