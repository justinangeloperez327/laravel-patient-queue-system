@props(['slots' => [], 'selected' => '', 'ready' => false])

<flux:field>
    <flux:label>{{ __('Available slots') }}</flux:label>
    @if (! $ready)
        <flux:text>{{ __('Choose a service, provider and date to see free slots.') }}</flux:text>
    @elseif (count($slots) === 0)
        <flux:text>{{ __('No free slots on this day. Try another date or provider.') }}</flux:text>
    @else
        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('Available slots') }}">
            @foreach ($slots as $slot)
                <button type="button" role="radio" aria-checked="{{ $selected === $slot->key() ? 'true' : 'false' }}"
                    wire:click="$set('scheduled_start_at', '{{ $slot->key() }}')" wire:key="slot-{{ $slot->key() }}"
                    @class([
                        'rounded-md border px-3 py-1.5 text-sm tabular-nums focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                        'border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-900' => $selected === $slot->key(),
                        'border-zinc-200 hover:border-zinc-400 dark:border-zinc-700' => $selected !== $slot->key(),
                    ])>{{ $slot->localLabel() }}</button>
            @endforeach
        </div>
    @endif
    <flux:error name="scheduled_start_at" />
</flux:field>
