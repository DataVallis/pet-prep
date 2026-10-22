@php
    $palette = [
        'blocked' => 'danger',
        'warning' => 'warning',
        'ok' => 'success',
    ];
    $color = $palette[$level] ?? 'gray';
    $falColor = match ($falLevel) {
        'exhausted' => 'danger',
        'low' => 'warning',
        'ok' => 'success',
        default => 'gray',
    };
@endphp

<x-filament-widgets::widget>
    <div wire:poll.60s
         data-ai-budget-level="{{ $level }}"
         data-fal-balance-level="{{ $falLevel }}"
         style="border-left: 4px solid rgb(var(--{{ $color }}-500)); background-color: rgba(var(--{{ $color }}-500), 0.08);"
         class="rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <p class="text-base font-semibold" style="color: rgb(var(--{{ $color }}-600));">
                    {{ __('admin.ai_alerts.banner.'.$level, ['percent' => $warnPercent]) }}
                </p>
                <ul class="text-sm space-y-0.5">
                    @foreach ($statuses as $status)
                        @php $c = $palette[$status->level()] ?? 'gray'; @endphp
                        <li>
                            <span style="color: rgb(var(--{{ $c }}-600));">●</span>
                            {{ __('admin.ai_alerts.budgets.'.$status->budget.'_'.$status->period) }}:
                            <strong>${{ sprintf('%.2f', $status->spentUsd) }}</strong> / ${{ sprintf('%.2f', $status->capUsd) }}
                            ({{ rtrim(rtrim(sprintf('%.1f', $status->percent()), '0'), '.') }} %)
                        </li>
                    @endforeach
                    <li>
                        <span style="color: rgb(var(--{{ $falColor }}-600));">●</span>
                        @if ($falBalance !== null && $falBalance['status'] === 'ok')
                            {{ __('admin.ai_alerts.banner.fal_balance', ['balance' => sprintf('%.2f', $falBalance['balance_usd']), 'threshold' => sprintf('%.2f', $falThreshold), 'at' => \Illuminate\Support\Carbon::parse($falBalance['checked_at'])->timezone('Europe/Ljubljana')->format('Y-m-d H:i')]) }}
                        @else
                            {{ __('admin.ai_alerts.banner.fal_unknown_'.($falBalance['status'] ?? 'never')) }}
                        @endif
                        @if ($falLevel === 'exhausted')
                            — <strong>{{ __('admin.ai_alerts.banner.fal_exhausted') }}</strong>
                        @endif
                        · {{ trans_choice('admin.ai_alerts.banner.fal_failures', $falFailures, ['count' => $falFailures]) }}
                    </li>
                </ul>
            </div>
            <x-filament::button tag="a" :href="$budgetUrl" size="sm" :color="$color === 'success' ? 'gray' : $color" icon="heroicon-o-banknotes">
                {{ __('admin.ai_alerts.open') }}
            </x-filament::button>
        </div>
    </div>
</x-filament-widgets::widget>
