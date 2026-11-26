<div class="rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
    <flux:text>{{ __('You are not serving a patient. Call and start a patient from your counter first.') }}</flux:text>
    <div class="mt-3"><flux:button :href="route('workspace')" wire:navigate>{{ __('Go to my counter') }}</flux:button></div>
</div>
