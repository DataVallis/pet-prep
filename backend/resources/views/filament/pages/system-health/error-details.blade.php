@php /** @var \App\Models\SystemError $error */ @endphp
<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
        <div><dt class="font-medium text-gray-500">{{ __('admin.system_health.details.status') }}</dt>
            <dd>
                @if ($error->isResolved())
                    {{ __('admin.system_health.details.resolved_by', ['at' => $error->resolved_at->timezone('Europe/Ljubljana')->format('Y-m-d H:i'), 'who' => $error->resolver?->email ?? '—']) }}
                @else
                    {{ __('admin.system_health.errors.open') }}
                @endif
            </dd></div>
        <div><dt class="font-medium text-gray-500">{{ __('admin.system_health.errors.occurrences') }}</dt><dd>{{ number_format($error->occurrences) }}</dd></div>
        <div><dt class="font-medium text-gray-500">{{ __('admin.system_health.errors.first_seen') }}</dt><dd>{{ $error->first_seen_at->timezone('Europe/Ljubljana')->format('Y-m-d H:i:s') }}</dd></div>
        <div><dt class="font-medium text-gray-500">{{ __('admin.system_health.errors.last_seen') }}</dt><dd>{{ $error->last_seen_at->timezone('Europe/Ljubljana')->format('Y-m-d H:i:s') }}</dd></div>
        <div><dt class="font-medium text-gray-500">{{ __('admin.system_health.errors.where') }}</dt><dd>{{ __('admin.system_health.sources.'.$error->source) }} · <code>{{ $error->contextSummary() }}</code></dd></div>
        <div><dt class="font-medium text-gray-500">{{ __('admin.system_health.details.level') }}</dt><dd>{{ $error->level }}</dd></div>
        @if ($error->exception_class)
            <div class="sm:col-span-2"><dt class="font-medium text-gray-500">{{ __('admin.system_health.details.class') }}</dt><dd><code>{{ $error->exception_class }}</code></dd></div>
        @endif
        @if ($error->file)
            <div class="sm:col-span-2"><dt class="font-medium text-gray-500">{{ __('admin.system_health.details.file') }}</dt><dd><code>{{ $error->file }}:{{ $error->line }}</code></dd></div>
        @endif
        @if (! empty($error->context['user_id']))
            <div><dt class="font-medium text-gray-500">{{ __('admin.system_health.details.user') }}</dt><dd>#{{ $error->context['user_id'] }} ({{ $error->context['role'] ?? '?' }})</dd></div>
        @endif
        @if (! empty($error->context['ids']))
            <div><dt class="font-medium text-gray-500">{{ __('admin.system_health.details.ids') }}</dt><dd><code>{{ json_encode($error->context['ids']) }}</code></dd></div>
        @endif
    </dl>

    <div>
        <div class="font-medium text-gray-500">{{ __('admin.system_health.details.message') }}</div>
        <pre class="mt-1 whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-900">{{ $error->message }}</pre>
    </div>

    @if (! empty($error->trace))
        <div>
            <div class="font-medium text-gray-500">{{ __('admin.system_health.details.trace') }}</div>
            <pre class="mt-1 overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-900">@foreach ($error->trace as $i => $frame)#{{ $i }} {{ $frame }}
@endforeach</pre>
        </div>
    @endif
</div>
