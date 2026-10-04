<x-filament-panels::page>
    <div @if ($this->hasPending()) wire:poll.10s @endif class="space-y-6">
        <form wire:submit="generateImages" class="space-y-3">
            {{ $this->imageForm }}
            <x-filament::button type="submit" icon="heroicon-o-photo">Generate images</x-filament::button>
        </form>

        <form wire:submit="animate" class="space-y-3">
            {{ $this->videoForm }}
            <x-filament::button type="submit" icon="heroicon-o-film">Animate</x-filament::button>
        </form>

        <x-filament::section>
            <x-slot name="heading">Gallery (last 15 runs)</x-slot>
            <x-slot name="description">
                All lab calls so far: ~${{ number_format($this->totalLabSpendUsd(), 3) }} (estimated list price; fal invoice is the truth).
                Media URLs are fal.ai-hosted and may expire (M4-05 copies production media to our storage).
            </x-slot>

            @forelse ($this->getRuns() as $run)
                <div class="mb-6 border-b border-gray-200 pb-4 dark:border-gray-700">
                    <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                        <strong>Run #{{ $run->id }}</strong>
                        <span>{{ $run->kind }}</span>
                        <span>· {{ $run->breed }}</span>
                        @if ($run->pet_state)
                            <span>· state {{ $run->pet_state }} · from image #{{ $run->source_result_id }}</span>
                        @endif
                        <span>· est. ${{ number_format($run->estimated_cost_usd, 3) }}</span>
                        <span>· charged ~${{ number_format($run->results->sum('estimated_cost_usd'), 3) }}</span>
                        <span class="text-gray-500">· {{ $run->created_at?->diffForHumans() }}</span>
                        @if ($run->kind === 'video' && $run->results->contains('status', 'running'))
                            <x-filament::button size="xs" color="gray" wire:click="checkPending({{ $run->id }})">Check pending</x-filament::button>
                        @endif
                    </div>
                    @if ($run->fixed_traits)
                        <div class="mb-2 text-xs text-gray-500">Fixed: {{ collect($run->fixed_traits)->map(fn ($v, $k) => "$k = $v")->implode(', ') }}</div>
                    @endif

                    {{-- Inline grid: the stock Filament stylesheet has no custom theme build for arbitrary utilities. --}}
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem">
                        @foreach ($run->results as $result)
                            <div class="rounded-lg border border-gray-200 p-2 text-xs dark:border-gray-700">
                                <div class="mb-1 font-semibold">#{{ $result->id }} · sample {{ $result->sample_index + 1 }} · {{ $result->profile }}</div>
                                @if ($result->status === 'completed' && $result->result_url)
                                    @if ($result->kind === 'video')
                                        <video src="{{ $result->result_url }}" controls loop muted playsinline class="w-full rounded"></video>
                                    @else
                                        <a href="{{ $result->result_url }}" target="_blank" rel="noopener noreferrer">
                                            <img src="{{ $result->result_url }}" alt="Lab result {{ $result->id }}" class="w-full rounded" loading="lazy">
                                        </a>
                                    @endif
                                    <a href="{{ $result->result_url }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 underline">open</a>
                                @elseif (in_array($result->status, ['failed', 'unknown'], true))
                                    <div class="rounded bg-danger-50 p-2 text-danger-700 dark:bg-danger-950 dark:text-danger-300">
                                        {{ $result->status === 'unknown' ? 'Unknown (sent, no answer — cost kept)' : 'Failed' }}: {{ $result->error_reason }}<br>{{ \Illuminate\Support\Str::limit((string) $result->error, 200) }}
                                    </div>
                                @else
                                    <div class="rounded bg-gray-50 p-2 dark:bg-gray-900">{{ $result->status }}…</div>
                                @endif
                                <div class="mt-1 text-gray-500">
                                    {{ $result->latency_ms !== null ? number_format($result->latency_ms / 1000, 1).' s' : '—' }}
                                    · ~${{ number_format($result->estimated_cost_usd, 3) }}
                                    @if ($result->seed) · seed {{ $result->seed }} @endif
                                </div>
                                <details class="mt-1">
                                    <summary class="cursor-pointer">prompt & traits</summary>
                                    <p class="mt-1 whitespace-pre-line">{{ $result->prompt }}</p>
                                    @if ($result->traits)
                                        <p class="mt-1 text-gray-500">{{ collect($result->traits)->map(fn ($v, $k) => "$k: $v")->implode(' · ') }}</p>
                                    @endif
                                </details>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">No runs yet.</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
