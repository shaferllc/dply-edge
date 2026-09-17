<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="activity"
            :title="__('Activity')"
            :description="__('Audit trail — filter by family or search action / subject.')"
            icon="heroicon-o-clock"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Activity'), 'icon' => 'clock'],
            ]"
        >
            <x-plan-upsell :organization="$organization" :title="__('The audit log is on the Team plan')" :message="__('Every change is still being recorded. Upgrade to Team to browse, filter and export the full history.')" />
        </x-organization-shell>
    </div>
</div>
