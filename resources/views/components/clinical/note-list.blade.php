@props(['notes', 'amendable' => false])

<section class="flex flex-col gap-3">
    @forelse ($notes as $note)
        <article class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="note-{{ $note->id }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="md" level="3">{{ $note->note_type->label() }}</flux:heading>
                <div class="flex items-center gap-2 text-sm text-zinc-500">
                    <span>{{ $note->author->name }}</span>
                    @if ($note->isSigned())
                        <flux:badge size="sm" color="green">{{ __('Signed :time', ['time' => \App\Support\ClinicTime::toLocalDisplay($note->signed_at)]) }}</flux:badge>
                    @else
                        <flux:badge size="sm">{{ __('Draft') }}</flux:badge>
                    @endif
                </div>
            </div>
            <dl class="mt-2 grid gap-2 text-sm">
                @foreach (['chief_complaint' => __('Chief complaint'), 'subjective' => __('Subjective'), 'objective' => __('Objective'), 'assessment' => __('Assessment'), 'plan' => __('Plan'), 'additional_notes' => __('Notes')] as $field => $label)
                    @if (filled($note->{$field}))
                        <div><dt class="font-medium">{{ $label }}</dt><dd class="whitespace-pre-line">{{ $note->{$field} }}</dd></div>
                    @endif
                @endforeach
            </dl>
            @foreach ($note->amendments as $amendment)
                <div class="mt-3 border-s-2 border-amber-400 ps-3 text-sm" wire:key="amendment-{{ $amendment->id }}">
                    <div class="font-medium">{{ __('Amendment by :name, :time', ['name' => $amendment->author->name, 'time' => \App\Support\ClinicTime::toLocalDisplay($amendment->signed_at)]) }}</div>
                    <div class="text-zinc-500">{{ __('Reason: :reason', ['reason' => $amendment->reason]) }}</div>
                    <div class="whitespace-pre-line">{{ $amendment->amendment }}</div>
                </div>
            @endforeach
            @if ($amendable && $note->isSigned() && auth()->user()->can($note->note_type->writePermission()->value))
                <div class="mt-3"><flux:button size="sm" variant="ghost" wire:click="startAmending({{ $note->id }})">{{ __('Add amendment') }}</flux:button></div>
            @endif
        </article>
    @empty
        <flux:text>{{ __('No notes yet.') }}</flux:text>
    @endforelse
</section>
