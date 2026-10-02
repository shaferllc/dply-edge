<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerAgent;
use App\Modules\Edge\Services\Containers\EdgeContainerCommands;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Console: run a command in one of this app's containers through the dply
 * agent and watch its output (T-038, ruling r-5w7h5d0b902aeq0n). Any command:
 * the app's own code already runs there. Each run is in the app's activity.
 */
class Console extends Component
{
    use MountsEdgeWorkspaceSection;

    public string $command = '';

    public string $target = '';

    /** Start the container when it is asleep (it then bills while awake). */
    public bool $wake = true;

    public int $timeout = 300;

    public ?string $runId = null;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        abort_unless(($site->edgeMeta()['runtime_mode'] ?? '') === 'container', 404);
        $this->target = array_key_first(EdgeContainerCommands::targets($site)) ?? 'instance-0';
    }

    public function run(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'command' => ['required', 'string', 'max:4000'],
            'target' => ['required', 'in:'.implode(',', array_keys(EdgeContainerCommands::targets($this->site)))],
            'timeout' => ['required', 'integer', 'min:5', 'max:'.EdgeContainerCommands::MAX_TIMEOUT],
        ]);
        try {
            $this->runId = EdgeContainerCommands::start($this->site, trim($this->command), $this->target, $this->timeout, $this->wake, auth()->user());
        } catch (\RuntimeException $e) {
            $this->addError('command', $e->getMessage());
        }
    }

    public function useSuggestion(string $command): void
    {
        $this->command = $command;
    }

    /** Off: the next deploy builds without the agent, and the Console stops working. */
    public function setAgent(bool $on): void
    {
        $this->authorize('update', $this->site);
        $this->site->mergeEdgeMeta(['container_agent' => $on]);
        $this->site->save();
    }

    public function render(): View
    {
        $run = $this->runId !== null ? EdgeContainerCommands::read($this->runId) : null;

        return view('livewire.sites.edge.workspace.console', [
            'targets' => EdgeContainerCommands::targets($this->site),
            'suggestions' => EdgeContainerCommands::suggestions($this->site),
            'run' => $run,
            'running' => $run !== null && in_array($run['status'], ['queued', 'running'], true),
            'flagOn' => EdgeContainerAgent::flagOn($this->site),
            'agentLive' => EdgeContainerAgent::live($this->site),
            'agentWanted' => ($this->site->edgeMeta()['container_agent'] ?? true) !== false,
            'canRun' => auth()->user()?->can('update', $this->site) ?? false,
        ]);
    }
}
