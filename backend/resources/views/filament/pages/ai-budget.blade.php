<x-filament-panels::page>
    <form wire:submit="save" class="space-y-4">
        {{ $this->form }}
        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit" icon="heroicon-o-check">{{ __('admin.ai_budget.save') }}</x-filament::button>
            <x-filament::button type="button" color="gray" icon="heroicon-o-arrow-path" wire:click="retryBlocked">{{ __('admin.ai_budget.retry') }}</x-filament::button>
        </div>
        <p class="text-sm text-gray-500">{{ __('admin.ai_budget.retry_help') }}</p>
    </form>

    <x-filament::section>
        <x-slot name="heading">{{ __('admin.ai_budget.history') }}</x-slot>
        <x-slot name="description">{{ __('admin.ai_budget.history_desc') }}</x-slot>

        @forelse ($this->getChanges() as $change)
            <div class="text-sm py-1 border-b border-gray-100 dark:border-gray-800">
                <span class="font-medium">{{ $change->created_at?->timezone('Europe/Ljubljana')->format('Y-m-d H:i') }}</span>
                · {{ $change->user?->email ?? __('admin.features.system') }}
                · <code>{{ json_encode($change->old) }}</code> → <code>{{ json_encode($change->new) }}</code>
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ __('admin.ai_budget.history_empty') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
