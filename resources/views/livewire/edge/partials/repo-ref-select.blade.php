<div class="space-y-2">
    <x-input-label for="git-ref" :value="__('Branch or tag')" />
    @if ($repoBranches !== [] || $repoTags !== [])
        <select id="git-ref" wire:model.live="gitRef" class="dply-input mt-1 block w-full font-mono text-sm">
            @if ($repoBranches !== [])
                <optgroup label="{{ __('Branches') }}">
                    @foreach ($repoBranches as $name)
                        <option value="branch:{{ $name }}">{{ $name }}</option>
                    @endforeach
                </optgroup>
            @endif
            @if ($repoTags !== [])
                <optgroup label="{{ __('Tags') }}">
                    @foreach ($repoTags as $name)
                        <option value="tag:{{ $name }}">{{ $name }}</option>
                    @endforeach
                </optgroup>
            @endif
        </select>
    @endif
    <x-text-input id="branch" wire:model.live.debounce.500ms="branch" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="{{ __('Or type a branch or tag') }}" />
    <x-input-error :messages="$errors->get('branch')" class="mt-2" />
</div>
