<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge;

use App\Livewire\Concerns\DismissesConsoleActionRun;
use App\Models\Site;
use App\Support\Sites\SiteSettingsViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The workspace console-action banner as its own component, so its 4s poll
 * re-renders only this banner instead of the whole EdgeSettings shell. When
 * the run finishes it tells the shell to refresh once per grace tick.
 */
class ConsoleActionBanner extends Component
{
    use DismissesConsoleActionRun;

    #[Locked]
    public Site $site;

    /** @var list<string> */
    #[Locked]
    public array $kinds = [];

    protected function consoleActionSubject(): Model
    {
        return $this->site;
    }

    public function render(): View
    {
        $run = SiteSettingsViewData::consoleActionRun($this->site, $this->kinds);

        // Grace window after terminal state (see the partial): the shell reads
        // state the worker wrote alongside the ConsoleAction, so nudge it.
        if ($run !== null && ! $run->isInFlight() && $run->finished_at?->gt(now()->subSeconds(12))) {
            $this->dispatch('console-action-finished');
        }

        return view('livewire.sites.edge.console-action-banner', [
            'run' => $run,
            'kindLabels' => (array) config('console_actions.kinds', []),
        ]);
    }
}
