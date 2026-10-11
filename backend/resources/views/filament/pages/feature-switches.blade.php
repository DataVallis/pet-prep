<x-filament-panels::page>
    <form wire:submit="save" class="space-y-4">
        {{ $this->form }}
        <x-filament::button type="submit" icon="heroicon-o-check">{{ __('admin.features.save') }}</x-filament::button>
    </form>

    <x-filament::section>
        <x-slot name="heading">{{ __('admin.features.history') }}</x-slot>
        <x-slot name="description">{{ __('admin.features.history_desc') }}</x-slot>

        @forelse ($this->getChanges() as $change)
            <div class="text-sm py-1 border-b border-gray-100 dark:border-gray-800">
                <span class="font-medium">{{ $change->created_at?->timezone('Europe/Ljubljana')->format('Y-m-d H:i') }}</span>
                · {{ $change->user?->email ?? __('admin.features.system') }}
                · <code>{{ $change->key }}</code>:
                <code>{{ json_encode($change->old) }}</code> → <code>{{ json_encode($change->new) }}</code>
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ __('admin.features.history_empty') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
