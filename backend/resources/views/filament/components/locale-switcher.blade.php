@php($current = app()->getLocale())
<div class="flex items-center gap-1 rounded-lg bg-gray-100 p-0.5 text-xs font-semibold dark:bg-white/5" role="group" aria-label="Language">
    @foreach (config('locales.supported') as $code)
        <a
            href="{{ route('filament.admin.locale', ['locale' => $code]) }}"
            title="{{ __('admin.locale.'.$code) }}"
            @class([
                'rounded-md px-2 py-1 uppercase transition',
                'bg-white text-primary-600 shadow-sm dark:bg-gray-800 dark:text-primary-400' => $code === $current,
                'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $code !== $current,
            ])
            @if ($code === $current) aria-current="true" @endif
        >{{ $code }}</a>
    @endforeach
</div>
