<x-filament-panels::page>
    <form wire:submit="save" class="space-y-4">
        {{ $this->form }}
        <x-filament::button type="submit" icon="heroicon-o-check">Shrani</x-filament::button>
    </form>

    <x-filament::section>
        <x-slot name="heading">Zgodovina sprememb</x-slot>
        <x-slot name="description">Zadnjih 20 sprememb (kdo, kdaj, prej → potem).</x-slot>

        @forelse ($this->getChanges() as $change)
            <div class="text-sm py-1 border-b border-gray-100 dark:border-gray-800">
                <span class="font-medium">{{ $change->created_at?->timezone('Europe/Ljubljana')->format('Y-m-d H:i') }}</span>
                · {{ $change->user?->email ?? 'system' }}
                · <code>{{ $change->key }}</code>:
                <code>{{ json_encode($change->old) }}</code> → <code>{{ json_encode($change->new) }}</code>
            </div>
        @empty
            <p class="text-sm text-gray-500">Še ni sprememb (privzeto: izklopljeno).</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
