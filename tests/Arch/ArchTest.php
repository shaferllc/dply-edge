<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/*
|--------------------------------------------------------------------------
| Architecture tests
|--------------------------------------------------------------------------
|
| Structural invariants, checked by parsing app/ rather than by running it.
| These live in their own `Arch` testsuite (see phpunit.xml) because they are
| slow and memory-hungry relative to what they cover — parsing ~3,600 files
| takes ~35s and needs well over the 1G that phpunit.xml pins. Run them with
| `composer test:arch`; CI runs them as a separate step.
|
| The module boundary (Modules must not reach into the Livewire/Controller
| shell) is deliberately NOT expressed here: tests/Unit/ModuleBoundaryTest.php
| already enforces it with a BASELINE of known-debt exemptions plus a guard
| against stale entries. An arch rule would either duplicate that list or fail
| on debt that is already tracked.
|
| Every ignore below is a checked exception, not a silenced failure — each one
| was inspected and is legitimate.
|
*/

// phpunit.xml pins memory_limit=1G, which is not enough to parse app/ in one
// pass, and PHPUnit's ini setting overrides `php -d` on the command line.
ini_set('memory_limit', '2G');

arch('no debug or shell-escape functions ship')
    ->expect([
        'dd', 'dump', 'var_dump', 'ray', 'print_r',
        'shell_exec', 'passthru', 'system', 'proc_open', 'popen', 'eval',
    ])
    ->not->toBeUsed()
    ->ignoring([
        // Opt-in debug decorator; its dump() calls are guarded by function_exists().
        'App\Actions\Decorators\DebuggableDecorator',
    ]);

arch('enums are enums')
    ->expect('App\Enums')
    ->toBeEnums();

arch('action concerns are traits')
    ->expect('App\Actions\Concerns')
    ->toBeTraits();

arch('livewire concerns are traits')
    ->expect('App\Livewire\Concerns')
    ->toBeTraits();

arch('controllers are suffixed')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller');

arch('jobs are queueable')
    ->expect('App\Jobs')
    ->classes()
    ->toImplement(ShouldQueue::class);

arch('models extend eloquent')
    ->expect('App\Models')
    ->classes()
    ->toExtend(Model::class)
    // A bag of Discord permission-bit constants that happens to live in Models.
    ->ignoring('App\Models\DiscordPermissions');

arch('livewire components extend component')
    ->expect('App\Livewire')
    ->classes()
    ->toExtend(Component::class)
    // Livewire form objects extend Livewire\Form.
    ->ignoring('App\Livewire\Forms');

arch('console commands extend command')
    ->expect('App\Console\Commands')
    ->classes()
    ->toExtend(Command::class);

