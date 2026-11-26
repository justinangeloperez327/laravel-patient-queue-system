<x-display.layout :title="$device->title()">
    <main id="display" class="flex min-h-screen flex-col gap-8 p-8"
        data-device-id="{{ $device->id }}"
        data-channel="{{ $device->channelName() }}"
        data-snapshot-url="{{ route('display.snapshot', $device) }}"
        data-auth-url="{{ route('display.auth', $device) }}"
        data-voice-lang="{{ $device->voiceLang() }}"
        data-reverb='@json($reverb)'>
        <header class="flex items-center justify-between gap-6">
            <h1 class="text-4xl font-semibold tracking-tight">{{ $device->title() }}</h1>
            <div class="flex items-center gap-4">
                <span id="connection" class="rounded-full bg-zinc-800 px-3 py-1 text-sm text-zinc-300" role="status" aria-live="polite">{{ __('Connecting…') }}</span>
                <time id="clock" class="text-3xl tabular-nums text-zinc-300"></time>
            </div>
        </header>

        <section aria-labelledby="now-serving-heading" class="flex-1">
            <h2 id="now-serving-heading" class="mb-4 text-2xl font-medium text-zinc-400">{{ __('Now serving') }}</h2>
            <ul id="now-serving" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" aria-live="polite"></ul>
            <p id="now-serving-empty" class="text-2xl text-zinc-500">{{ __('Please wait to be called.') }}</p>
        </section>

        <section aria-labelledby="recent-heading">
            <h2 id="recent-heading" class="mb-3 text-xl font-medium text-zinc-400">{{ __('Recently called') }}</h2>
            <ol id="recent-calls" class="flex flex-wrap gap-3 text-2xl tabular-nums text-zinc-300"></ol>
        </section>

        <button id="enable-sound" type="button"
            class="fixed bottom-6 end-6 rounded-lg bg-white px-5 py-3 text-lg font-medium text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
            {{ __('Enable sound') }}
        </button>
    </main>
</x-display.layout>
