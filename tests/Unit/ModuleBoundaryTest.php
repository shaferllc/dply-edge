<?php

declare(strict_types=1);

namespace Tests\Unit\ModuleBoundaryTest;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\UseItem;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/**
 * Enforces the one architectural invariant from
 * docs/adr/modular-monolith-structure.md:
 *
 *     app/Modules/* must never depend on the presentation SHELL
 *     (concrete app/Livewire/* components + app/Http/Controllers/*).
 *
 * The arrow points UI -> engine -> kernel, never the reverse.
 *
 * This replaces Deptrac, removed 2026-08-15. It resolves names via the same
 * mechanism Deptrac used (nikic/php-parser + NameResolver) rather than
 * grepping `use` lines, so aliased imports, inline `\App\Livewire\Foo`
 * references, extends/implements, attributes, param/return types, static
 * calls, and instantiations are all caught.
 *
 * Layer definitions are a faithful port of the old deptrac.yaml:
 *
 *   Presentation = app/Livewire/**  EXCEPT app/Livewire/Concerns/**
 *                                   and    app/Livewire/Forms/**
 *                + app/Http/Controllers/** EXCEPT the base Controller
 *   Modules      = app/Modules/**
 *   Shared       = everything else under app/ (intentionally unconstrained;
 *                  this codebase's shared infra calls into modules by design)
 *
 * Paying down a BASELINE entry? Delete the line. The "baseline has no stale
 * entries" test fails if you leave a paid-off exemption behind.
 */

/**
 * Known-debt exemptions, ported verbatim from deptrac-baseline.yaml.
 * Module class => shell classes it is (still) allowed to reach into.
 *
 * @var array<class-string, list<class-string>>
 */
const BASELINE = [
    // Empty since the dply-edge cut (2026-08-25): both entries were Feedback /
    // Roadmap module components, and those modules left with the VM platform.
];

/**
 * Map a fully-qualified class name onto its architectural layer.
 * Returns null for anything outside App\ (vendor, PHP built-ins).
 */
function layerOf(string $fqcn): ?string
{
    $n = ltrim($fqcn, '\\');

    if (! str_starts_with($n, 'App\\')) {
        return null;
    }

    if (str_starts_with($n, 'App\\Modules\\')) {
        return 'Modules';
    }

    if (str_starts_with($n, 'App\\Livewire\\')) {
        // Generic concerns + form objects are shared building blocks that
        // modules may legitimately use — they are NOT the shell.
        if (str_starts_with($n, 'App\\Livewire\\Concerns\\') || str_starts_with($n, 'App\\Livewire\\Forms\\')) {
            return 'Shared';
        }

        return 'Presentation';
    }

    if (str_starts_with($n, 'App\\Http\\Controllers\\')) {
        // The abstract base Controller is a shared building block.
        if ($n === 'App\\Http\\Controllers\\Controller') {
            return 'Shared';
        }

        return 'Presentation';
    }

    return 'Shared';
}

/** @return list<string> every .php file under app/Modules */
function moduleFiles(): array
{
    $root = dirname(__DIR__, 2).'/app/Modules';
    $files = [];

    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Parse one file and return [declaringClass, list of referenced FQCNs].
 * Uses NameResolver so every Name node is fully qualified before we read it.
 *
 * Names appearing in `use` statements are deliberately NOT counted on their
 * own — only real code references are. This matches Deptrac's default
 * emitters, and it matters: several modules carry an import that exists
 * solely so a `{@see Foo}` docblock tag resolves (e.g. Secrets'
 * SiteEnvBundleSource importing ManagesSiteEnvironment). A doc cross-
 * reference is not an architectural dependency — nothing is called, typed,
 * extended, or constructed. An import that IS used in code still resolves
 * through the alias map and is caught normally.
 *
 * @return array{0: string|null, 1: list<string>}
 */
function referencesIn(string $path): array
{
    // Memoized: both tests walk the same ~1k module files, and re-parsing
    // them per baseline entry turned a 6s check into 17s.
    static $cache = [];

    if (isset($cache[$path])) {
        return $cache[$path];
    }

    static $parser = null;
    $parser ??= (new ParserFactory)->createForVersion(PhpVersion::fromComponents(8, 4));

    $ast = $parser->parse((string) file_get_contents($path));

    if ($ast === null) {
        return $cache[$path] = [null, []];
    }

    $collector = new class extends NodeVisitorAbstract
    {
        public ?string $declared = null;

        /** @var list<string> */
        public array $names = [];

        public function enterNode(Node $node): null
        {
            if (($node instanceof Class_ || $node instanceof Interface_ || $node instanceof Trait_ || $node instanceof Enum_)
                && $node->namespacedName !== null
                && $this->declared === null
            ) {
                $this->declared = $node->namespacedName->toString();
            }

            // NameResolver has already rewritten every Name to its FQ form,
            // including `use X as Y` aliases and inline \Fully\Qualified refs.
            // Skip the ones that ARE the import statement (parent is UseItem)
            // so a docblock-only import doesn't read as a dependency.
            if ($node instanceof Name && ! $node->getAttribute('parent') instanceof UseItem) {
                $this->names[] = $node->toString();
            }

            return null;
        }
    };

    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor(new ParentConnectingVisitor);
    $traverser->addVisitor($collector);
    $traverser->traverse($ast);

    return $cache[$path] = [$collector->declared, array_values(array_unique($collector->names))];
}

test('modules never depend on the presentation shell', function (): void {
    $violations = [];

    foreach (moduleFiles() as $path) {
        [$declared, $names] = referencesIn($path);

        foreach ($names as $name) {
            if (layerOf($name) !== 'Presentation') {
                continue;
            }

            // Self-reference is impossible here (Modules never resolve to
            // Presentation), so any hit is a real Modules -> Presentation edge.
            $allowed = $declared !== null ? (BASELINE[$declared] ?? []) : [];

            if (in_array($name, $allowed, true)) {
                continue;
            }

            $rel = str_replace(dirname(__DIR__, 2).'/', '', $path);
            $violations[] = "  {$rel}\n      -> {$name}";
        }
    }

    $violations = array_values(array_unique($violations));

    expect($violations)->toBe([], sprintf(
        "Modules must not depend on the presentation shell (app/Livewire/*, app/Http/Controllers/*).\n".
        "The arrow points UI -> engine -> kernel, never the reverse.\n".
        "See docs/adr/modular-monolith-structure.md.\n\n%d violation(s):\n%s\n\n".
        "Fix by moving the shared piece into the kernel (app/Support, app/Services,\n".
        "app/Livewire/Concerns) and depending on that from both sides.",
        count($violations),
        implode("\n", $violations)
    ));
});

test('baseline has no stale entries', function (): void {
    $stale = [];

    foreach (BASELINE as $moduleClass => $shellClasses) {
        $found = false;

        foreach (moduleFiles() as $path) {
            [$declared, $names] = referencesIn($path);

            if ($declared !== $moduleClass) {
                continue;
            }

            $found = true;

            foreach ($shellClasses as $shellClass) {
                if (! in_array($shellClass, $names, true)) {
                    $stale[] = "{$moduleClass} no longer references {$shellClass}";
                }
            }
        }

        if (! $found) {
            $stale[] = "{$moduleClass} no longer exists";
        }
    }

    expect($stale)->toBe([], sprintf(
        "Known-debt exemptions in BASELINE have been paid off — delete them:\n  %s",
        implode("\n  ", $stale)
    ));
});

/**
 * Guards the guard. A path typo or a directory rename would otherwise make
 * the scan silently match nothing and the boundary test pass vacuously.
 */
test('the scanner actually sees both layers', function (): void {
    $files = moduleFiles();

    expect($files)->not->toBeEmpty('No files found under app/Modules — the scan path is wrong.');
    expect(count($files))->toBeGreaterThan(250, 'Suspiciously few module files — is the scan path right?');

    // Layer classification must still work in both directions.
    expect(layerOf('App\Modules\Deploy\Services\Foo'))->toBe('Modules');
    expect(layerOf('App\Livewire\Servers\Index'))->toBe('Presentation');
    expect(layerOf('App\Http\Controllers\Api\ServerController'))->toBe('Presentation');
    expect(layerOf('App\Livewire\Concerns\RequiresFeature'))->toBe('Shared');
    expect(layerOf('App\Livewire\Forms\SiteForm'))->toBe('Shared');
    expect(layerOf('App\Http\Controllers\Controller'))->toBe('Shared');
    expect(layerOf('App\Models\Site'))->toBe('Shared');
    expect(layerOf('Illuminate\Support\Str'))->toBeNull();

    // And the parser must actually resolve names — a silent parse failure
    // would return an empty list and pass the boundary test for free.
    [$declared, $names] = referencesIn(dirname(__DIR__, 2).'/app/Modules/Edge/Livewire/Import.php');
    expect($declared)->toBe('App\Modules\Edge\Livewire\Import');
    expect($names)->toContain('App\Livewire\Concerns\DispatchesToastNotifications');
});
