@php
    $f = $this->getFunnel();
    $pct = fn (int $part, int $whole) => ($p = \App\Services\Funnel\FunnelService::percent($part, $whole)) === null ? '' : ' ('.$p.' %)';
    // [step, base it is a share of, eligible key or null]
    $cols = [
        ['registered', null, null],
        ['child_added', 'registered', null],
        ['pet_born', 'registered', null],
        ['d1', 'd1_eligible', 'd1_eligible'],
        ['d7', 'd7_eligible', 'd7_eligible'],
        ['paid', 'pet_born', null],
        ['paid_test', 'pet_born', null],
        ['week4', 'week4_eligible', 'week4_eligible'],
        ['certificate', 'registered', null],
    ];
    $cell = function (array $line, array $col) use ($pct) {
        [$step, $base, $eligible] = $col;
        if ($eligible !== null) {
            return $line[$eligible] === 0 ? '—' : $line[$step].' / '.$line[$eligible].$pct($line[$step], $line[$eligible]);
        }

        return $line[$step].($base === null ? '' : $pct($line[$step], $line[$base]));
    };
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="description">{{ __('admin.funnel.description') }}</x-slot>

        <label class="text-sm font-medium">
            {{ __('admin.funnel.period') }}
            <select wire:model.live.number="weeks" class="ml-2 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-gray-700">
                @foreach (\App\Filament\Pages\Funnel::WEEK_OPTIONS as $option)
                    <option value="{{ $option }}">{{ __('admin.funnel.weeks', ['weeks' => $option]) }}</option>
                @endforeach
            </select>
        </label>

        <div class="overflow-x-auto mt-4">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                        <th class="py-1 pr-3">{{ __('admin.funnel.col.cohort') }}</th>
                        @foreach ($cols as $col)
                            <th class="py-1 pr-3 text-right">{{ __('admin.funnel.col.'.$col[0]) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($f['rows'] as $line)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-1 pr-3">{{ $line['cohort'] }}</td>
                            @foreach ($cols as $col)
                                <td class="py-1 pr-3 text-right">{{ $cell($line, $col) }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="10" class="py-2 text-gray-500">{{ __('admin.funnel.empty') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($f['rows'] !== [])
                    <tfoot>
                        <tr class="font-medium">
                            <td class="py-1 pr-3">{{ __('admin.funnel.total') }}</td>
                            @foreach ($cols as $col)
                                <td class="py-1 pr-3 text-right">{{ $cell($f['total'], $col) }}</td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('admin.funnel.legend') }}</x-slot>
        <ul class="text-sm space-y-1 text-gray-600 dark:text-gray-300">
            @foreach (\App\Services\Funnel\FunnelService::STEPS as $step)
                <li><span class="font-medium">{{ __('admin.funnel.col.'.$step) }}</span> — {{ __('admin.funnel.help.'.$step) }}</li>
            @endforeach
            <li>{{ __('admin.funnel.help.note') }}</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
