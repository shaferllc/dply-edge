<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire;

use App\Models\EdgeDatabase;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Projects → Databases: an organization's Cloudflare D1 databases. Create,
 * browse tables, run SQL, and attach one to a project as a D1 binding.
 */
#[Layout('layouts.app')]
class Databases extends Component
{
    public const LOCATIONS = ['' => 'Automatic', 'wnam' => 'Western North America', 'enam' => 'Eastern North America', 'weur' => 'Western Europe', 'eeur' => 'Eastern Europe', 'apac' => 'Asia-Pacific', 'oc' => 'Oceania'];

    public bool $compact = false;

    public string $name = '';

    public string $location = '';

    #[Url(as: 'db', except: '')]
    public string $selected = '';

    public string $sql = "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name;";

    /** @var list<array<string, mixed>>|null */
    public ?array $results = null;

    public ?string $queryError = null;

    public string $attachSite = '';

    public string $bindingName = 'DB';

    public function create(): void
    {
        $org = $this->organization();
        $this->authorize('update', $org);
        $this->validate([
            'name' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'location' => ['nullable', 'in:'.implode(',', array_keys(self::LOCATIONS))],
        ], ['name.regex' => __('Use lowercase letters, numbers and dashes.')]);

        $limit = $org->tierAllowances()['databases'] ?? null;
        if ($limit !== null && EdgeDatabase::query()->where('organization_id', $org->id)->count() >= $limit) {
            $this->addError('name', __('Your :plan plan includes :count databases. Upgrade on the billing page for more.', ['plan' => $org->planTierLabel(), 'count' => $limit]));

            return;
        }
        if (EdgeDatabase::query()->where('organization_id', $org->id)->where('name', $this->name)->exists()) {
            $this->addError('name', __('You already have a database with that name.'));

            return;
        }

        try {
            $created = $this->client()->createD1Database(EdgeDatabase::cloudflareName($org, $this->name), $this->location);
        } catch (Throwable $e) {
            $this->addError('name', __('Dply Edge: :error', ['error' => $e->getMessage()]));

            return;
        }

        $database = EdgeDatabase::query()->create([
            'organization_id' => $org->id,
            'name' => $this->name,
            'cloudflare_id' => (string) $created['uuid'],
            'location_hint' => $this->location ?: null,
            'created_by' => auth()->id(),
        ]);
        audit_log($org, auth()->user(), 'database.created', $database, null, ['name' => $database->name]);

        $this->reset('name', 'location');
        $this->select($database->id);
    }

    public function select(string $id): void
    {
        $this->selected = $id;
        $this->results = null;
        $this->queryError = null;
    }

    public function run(): void
    {
        $database = $this->database();
        $this->authorize('update', $database->organization);
        $this->validate(['sql' => ['required', 'string', 'max:100000']]);

        try {
            $this->results = $this->client()->queryD1($database->cloudflare_id, $this->sql);
            $this->queryError = null;
            Cache::forget('d1-info:'.$database->cloudflare_id);
        } catch (Throwable $e) {
            $this->results = null;
            $this->queryError = $e->getMessage();
        }
    }

    public function attach(): void
    {
        $database = $this->database();
        $this->validate([
            'attachSite' => ['required', 'string'],
            'bindingName' => ['required', 'regex:/^[A-Z][A-Z0-9_]{0,63}$/'],
        ], ['bindingName.regex' => __('Binding names are UPPER_SNAKE_CASE.')]);

        $site = Site::query()->where('organization_id', $database->organization_id)->findOrFail($this->attachSite);
        $this->authorize('update', $site);

        $overrides = is_array($site->edgeMeta()['bindings_overrides'] ?? null) ? $site->edgeMeta()['bindings_overrides'] : [];
        $overrides = array_values(array_filter($overrides, fn ($row) => ($row['name'] ?? null) !== $this->bindingName));
        $overrides[] = ['name' => $this->bindingName, 'kind' => 'd1', 'value' => $database->cloudflare_id];
        $site->mergeEdgeMeta(['bindings_overrides' => $overrides]);
        $site->save();

        session()->flash('status', __(':db is bound to :site as :binding. Redeploy the project to use it.', ['db' => $database->name, 'site' => $site->name, 'binding' => $this->bindingName]));
    }

    public function delete(string $id, string $confirmName): void
    {
        $database = EdgeDatabase::query()->where('organization_id', $this->organization()->id)->findOrFail($id);
        $this->authorize('update', $database->organization);
        if ($confirmName !== $database->name) {
            $this->addError('delete', __('Type the database name to confirm.'));

            return;
        }

        try {
            $this->client()->deleteD1Database($database->cloudflare_id);
        } catch (Throwable $e) {
            $this->addError('delete', __('Dply Edge: :error', ['error' => $e->getMessage()]));

            return;
        }

        audit_log($database->organization, auth()->user(), 'database.deleted', $database, null, ['name' => $database->name]);
        $database->delete();
        $this->selected = '';
        $this->results = null;
    }

    public function render(): View
    {
        $org = $this->organization();
        $selected = $this->selected !== '' ? EdgeDatabase::query()->where('organization_id', $org->id)->find($this->selected) : null;
        $info = null;
        if ($selected !== null) {
            try {
                $info = Cache::remember('d1-info:'.$selected->cloudflare_id, 60, fn () => $this->client()->getD1Database($selected->cloudflare_id));
            } catch (Throwable) {
                $info = null;
            }
        }

        return view('livewire.edge.databases', [
            'org' => $org,
            'databases' => EdgeDatabase::query()->where('organization_id', $org->id)->orderBy('name')->get(),
            'current' => $selected,
            'info' => $info,
            'sites' => $org->sites()->whereNotNull('edge_backend')->orderBy('name')->get(['id', 'name', 'meta']),
            'limit' => $org->tierAllowances()['databases'] ?? null,
            'locations' => self::LOCATIONS,
        ]);
    }

    private function database(): EdgeDatabase
    {
        return EdgeDatabase::query()->where('organization_id', $this->organization()->id)->findOrFail($this->selected);
    }

    private function organization(): Organization
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        return $org;
    }

    private function client(): EdgeCloudflareClient
    {
        return EdgeCloudflareClient::fromConfig();
    }
}
