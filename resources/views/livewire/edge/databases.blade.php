@if ($compact)
    <div class="max-h-[70vh] overflow-y-auto">
        @include('livewire.edge.partials.databases-manager')
    </div>
@else
    <div class="dply-page-shell space-y-4 pt-6">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Projects'), 'href' => route('dashboard'), 'icon' => 'globe-alt'],
            ['label' => __('Databases'), 'icon' => 'circle-stack'],
        ]" />

        <x-profile-shell
            :title="__('Databases')"
            :description="__('Serverless SQLite databases on Dply Edge. Bind one to a project and query it from your app as env.DB.')"
            icon="heroicon-o-circle-stack"
        >
            @include('livewire.edge.partials.databases-manager')
        </x-profile-shell>
    </div>
@endif
