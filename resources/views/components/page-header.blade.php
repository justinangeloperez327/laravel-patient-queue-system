@props(['heading', 'subheading' => null])

<div class="flex flex-wrap items-start justify-between gap-4">
    <div>
        <flux:heading size="xl" level="1">{{ $heading }}</flux:heading>
        @if ($subheading)
            <flux:text class="mt-1">{{ $subheading }}</flux:text>
        @endif
    </div>

    @isset($actions)
        <div class="flex items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
