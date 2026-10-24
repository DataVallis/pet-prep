@php
    /** @var \App\Models\AppErrorFingerprint $error */
    /** @var \Illuminate\Support\Collection<int, \App\Models\AppErrorReport> $reports */
    $latest = $reports->first();
@endphp
<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
        <div><dt class="font-medium text-gray-500">{{ __('admin.app_errors.scope') }}</dt><dd>{{ $error->scope }}</dd></div>
        <div><dt class="font-medium text-gray-500">{{ __('admin.app_errors.fingerprint') }}</dt><dd><code>{{ substr($error->fingerprint, 0, 16) }}</code></dd></div>
        <div><dt class="font-medium text-gray-500">{{ __('admin.app_errors.last_24h') }}</dt><dd>{{ number_format($last24) }}</dd></div>
        <div><dt class="font-medium text-gray-500">{{ __('admin.app_errors.total') }}</dt><dd>{{ number_format($error->occurrences) }}</dd></div>
        <div><dt class="font-medium text-gray-500">{{ __('admin.app_errors.first_seen') }}</dt><dd>{{ $error->first_seen_at->timezone('Europe/Ljubljana')->format('Y-m-d H:i:s') }}</dd></div>
        <div><dt class="font-medium text-gray-500">{{ __('admin.app_errors.last_seen') }}</dt><dd>{{ $error->last_seen_at->timezone('Europe/Ljubljana')->format('Y-m-d H:i:s') }}</dd></div>
    </dl>

    <div>
        <div class="font-medium text-gray-500">{{ __('admin.system_health.details.message') }}</div>
        <pre class="mt-1 whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-900">{{ $error->message }}</pre>
    </div>

    <table class="w-full text-left text-xs">
        <thead class="text-gray-500">
            <tr>
                <th class="py-1 pr-3">{{ __('admin.app_errors.versions') }}</th>
                <th class="py-1 pr-3">{{ __('admin.app_errors.platforms') }}</th>
                <th class="py-1 pr-3">{{ __('admin.app_errors.total') }}</th>
                <th class="py-1 pr-3">{{ __('admin.app_errors.roles') }}</th>
                <th class="py-1 pr-3">{{ __('admin.app_errors.last_seen') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($reports as $report)
                <tr>
                    <td class="py-1 pr-3"><code>{{ $report->app_version }}</code></td>
                    <td class="py-1 pr-3">{{ $report->platform }} {{ $report->sample['os_version'] ?? '' }}</td>
                    <td class="py-1 pr-3">{{ number_format($report->occurrences) }}</td>
                    <td class="py-1 pr-3">{{ \App\Filament\Widgets\SystemHealth\AppErrorsTable::roles($report) }}</td>
                    <td class="py-1 pr-3">{{ $report->last_seen_at->timezone('Europe/Ljubljana')->format('Y-m-d H:i') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($latest)
        <div>
            <div class="font-medium text-gray-500">{{ __('admin.app_errors.sample') }}</div>
            <dl class="mt-1 grid grid-cols-1 gap-x-6 gap-y-1 text-xs sm:grid-cols-2">
                @foreach (['route', 'fatal', 'runtime_version', 'update_id', 'build', 'role', 'occurred_at', 'received_at'] as $key)
                    <div><dt class="inline text-gray-500">{{ $key }}:</dt> <dd class="inline"><code>{{ is_bool($latest->sample[$key] ?? null) ? (($latest->sample[$key] ?? false) ? 'true' : 'false') : ($latest->sample[$key] ?? '—') }}</code></dd></div>
                @endforeach
            </dl>
        </div>
        @if (! empty($latest->sample['stack']))
            <div>
                <div class="font-medium text-gray-500">{{ __('admin.app_errors.stack') }}</div>
                <pre class="mt-1 overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-900">{{ $latest->sample['stack'] }}</pre>
            </div>
        @endif
    @endif
</div>
