<div class="flex max-w-2xl flex-col gap-6">
    <x-page-header :heading="$device === null ? __('Add display') : __('Edit :name', ['name' => $device->name])" />

    @if ($newKey)
        <flux:callout variant="warning" icon="key" :heading="__('Pairing key — shown only now')" data-test="display-key">
            <flux:callout.text>
                {{ __('Open :url on the screen and enter this key:', ['url' => route('display.show', $device)]) }}
                <span class="mt-2 block font-mono text-lg tracking-wider" data-test="display-key-value">{{ $newKey }}</span>
                {{ __('It is not stored and cannot be shown again. Rotating the key disconnects screens using the old one.') }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="save" class="flex flex-col gap-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="name" :label="__('Name')" required :description:trailing="__('For staff, e.g. Main waiting room')" />
            <flux:input wire:model="title" :label="__('Screen title')" :badge="__('Optional')" />
        </div>
        <flux:checkbox.group wire:model="service_ids" :label="__('Services shown')">
            @foreach ($this->serviceOptions as $service)
                <flux:checkbox :value="(string) $service->id" :label="$service->queue_prefix.' — '.$service->name" />
            @endforeach
        </flux:checkbox.group>
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="voice_lang" :label="__('Voice language')" required :description:trailing="__('Browser speech language, e.g. en-US, fil-PH')" />
        </div>
        <flux:switch wire:model="is_active" :label="__('Active')" :description="__('Deactivating disconnects the screen immediately.')" />

        <div class="flex flex-wrap items-center gap-3">
            <flux:button variant="primary" type="submit">{{ __('Save display') }}</flux:button>
            @if ($device)
                <flux:button wire:click="rotateKey" wire:confirm="{{ __('Issue a new key? Screens using the current key will be disconnected.') }}" icon="arrow-path">{{ __('Rotate key') }}</flux:button>
            @endif
            <flux:button variant="ghost" :href="route('displays.index')" wire:navigate>{{ __('Back') }}</flux:button>
        </div>
    </form>
</div>
