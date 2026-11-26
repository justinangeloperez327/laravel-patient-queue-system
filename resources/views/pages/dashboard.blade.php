<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * Labels of the roles held by the signed-in staff member.
     *
     * @return list<string>
     */
    #[Computed]
    public function roleLabels(): array
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->getRoleNames()
            ->map(fn (string $name): string => Role::tryFrom($name)?->label() ?? $name)
            ->sort()
            ->values()
            ->all();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Signed in as :name.', ['name' => auth()->user()->name]) }}</flux:text>
    </div>

    <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
        <flux:heading size="lg" level="2">{{ __('Your roles') }}</flux:heading>

        <div class="mt-3 flex flex-wrap gap-2" data-test="role-list">
            @foreach ($this->roleLabels as $label)
                <flux:badge>{{ $label }}</flux:badge>
            @endforeach
        </div>

        <flux:text class="mt-4">
            {{ __('Operational widgets for queues, appointments and reports will appear here as those modules are implemented.') }}
        </flux:text>
    </div>
</div>
