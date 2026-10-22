@php($p = $this->getPreview())
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="description">{{ __('admin.state_upgrade.description') }}</x-slot>
        {{ $this->form }}
    </x-filament::section>

    @if ($p['blocked'] !== null)
        <x-filament::section>
            <p class="text-sm font-medium text-danger-600">{{ __('admin.state_upgrade.blocked.'.$p['blocked']) }}</p>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">{{ __('admin.state_upgrade.preview') }}</x-slot>
        <x-slot name="description">{{ __('admin.state_upgrade.preview_desc') }}</x-slot>

        <div class="text-sm space-y-1">
            <p>{{ __('admin.state_upgrade.totals', ['eligible' => $p['eligible'], 'pets' => $p['pets'], 'looks' => $p['looks'], 'complete' => $p['complete_looks'], 'cost' => sprintf('%.2f', $p['cost_usd'])]) }}</p>
            <p>{{ __('admin.state_upgrade.budget', ['left' => sprintf('%.2f', $p['budget']['left_today_usd']), 'daily' => sprintf('%.2f', $p['budget']['daily_cap_usd']), 'month_left' => sprintf('%.2f', $p['budget']['left_month_usd']), 'monthly' => sprintf('%.2f', $p['budget']['monthly_cap_usd'])]) }}</p>
            <p class="font-medium">
                @if ($p['budget']['days'] === null)
                    {{ __('admin.state_upgrade.days_never') }}
                @elseif ($p['budget']['fits_today'])
                    {{ __('admin.state_upgrade.fits_today') }}
                @else
                    {{ __('admin.state_upgrade.days', ['days' => $p['budget']['days']]) }}
                @endif
                @if ($p['budget']['over_month'])
                    · {{ __('admin.state_upgrade.over_month') }}
                @endif
            </p>
            @if ($p['budget']['today_share_percent'] !== null && $p['cost_usd'] > 0)
                <p>{{ __('admin.state_upgrade.today_share', ['today' => sprintf('%.2f', $p['budget']['today_usd']), 'share' => $p['budget']['today_share_percent'].' %']) }}</p>
            @endif
            @unless ($p['phase_clips'])
                <p class="text-warning-600">{{ __('admin.state_upgrade.phase_clips_off') }}</p>
            @endunless
        </div>

        <div class="overflow-x-auto mt-4">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                        <th class="py-1 pr-3">{{ __('admin.state_upgrade.col.look') }}</th>
                        <th class="py-1 pr-3">{{ __('admin.state_upgrade.col.stage') }}</th>
                        <th class="py-1 pr-3 text-right">{{ __('admin.state_upgrade.col.pets') }}</th>
                        @foreach (\App\Services\Media\StateMediaUpgradePlanner::CATEGORIES as $category)
                            <th class="py-1 pr-3 text-right">{{ __('admin.state_upgrade.col.'.$category) }}</th>
                        @endforeach
                        <th class="py-1 text-right">{{ __('admin.state_upgrade.col.cost') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($p['groups'] as $g)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-1 pr-3">
                                {{ __('breeds.'.$g['breed']) }} · {{ $g['colour'] ?? '—' }}
                                @if ($g['unique'])
                                    <span class="text-gray-500">({{ __('admin.state_upgrade.unique', ['id' => $g['pet_id']]) }})</span>
                                @else
                                    <span class="text-gray-500">(#{{ $g['look_id'] }})</span>
                                @endif
                                @if ($g['stage_remake'] > 0)
                                    <span class="text-warning-600">· {{ __('admin.state_upgrade.stage_remake', ['pets' => $g['stage_remake']]) }}</span>
                                @endif
                            </td>
                            <td class="py-1 pr-3">{{ $g['stage'] ?? '—' }}</td>
                            <td class="py-1 pr-3 text-right">{{ $g['pets'] }}</td>
                            @foreach (\App\Services\Media\StateMediaUpgradePlanner::CATEGORIES as $category)
                                <td class="py-1 pr-3 text-right">{{ $g['items'][$category] ?: '—' }}</td>
                            @endforeach
                            <td class="py-1 text-right font-medium">${{ sprintf('%.2f', $g['cost_usd']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="py-2 text-gray-500">{{ __('admin.state_upgrade.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $this->upgradeAction }}</div>
        <p class="text-sm text-gray-500 mt-2">{{ __('admin.state_upgrade.upgrade_help') }}</p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('admin.state_upgrade.history') }}</x-slot>
        @forelse ($this->getRuns() as $run)
            <div class="text-sm py-1 border-b border-gray-100 dark:border-gray-800">
                <span class="font-medium">{{ $run->created_at?->timezone('Europe/Ljubljana')->format('Y-m-d H:i') }}</span>
                · {{ $run->user?->email ?? __('admin.features.system') }}
                · {{ __('admin.state_upgrade.run', ['pets' => $run->new['pets'] ?? 0, 'cost' => sprintf('%.2f', $run->new['estimate_usd'] ?? 0)]) }}
                · <code>{{ json_encode($run->new['filters'] ?? []) }}</code>
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ __('admin.state_upgrade.history_empty') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
