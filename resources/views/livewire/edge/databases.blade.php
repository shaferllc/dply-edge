@if ($compact)
    <div class="grid gap-6">
        @include('livewire.edge.partials.dply-databases')
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
            :description="__('Postgres, MySQL and MongoDB databases your apps connect to, and serverless SQLite (D1) databases bound as env.DB.')"
            icon="heroicon-o-circle-stack"
        >
            <div class="grid gap-8">
                @include('livewire.edge.partials.dply-databases')
                @include('livewire.edge.partials.databases-manager')
            </div>
        </x-profile-shell>
    </div>
@endif
