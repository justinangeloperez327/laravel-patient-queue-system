<x-display.layout :title="__('Pair display')">
    <main class="flex min-h-screen items-center justify-center p-6">
        <form method="POST" action="{{ route('display.pair', $device) }}" class="flex w-full max-w-sm flex-col gap-4 rounded-xl border border-zinc-700 bg-zinc-900 p-6">
            @csrf
            <h1 class="text-xl font-semibold">{{ __('Pair this screen') }}</h1>
            <p class="text-sm text-zinc-400">{{ __('Enter the display key an administrator gave you.') }}</p>
            <label for="key" class="text-sm font-medium">{{ __('Display key') }}</label>
            <input id="key" name="key" type="password" autocomplete="off" required autofocus
                class="rounded-md border border-zinc-600 bg-zinc-950 px-3 py-2 font-mono focus-visible:outline-2 focus-visible:outline-white"
                @error('key') aria-invalid="true" aria-describedby="key-error" @enderror>
            @error('key')
                <p id="key-error" class="text-sm text-red-400" role="alert">{{ $message }}</p>
            @enderror
            <button type="submit" class="rounded-md bg-white px-4 py-2 font-medium text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">{{ __('Pair screen') }}</button>
        </form>
    </main>
</x-display.layout>
