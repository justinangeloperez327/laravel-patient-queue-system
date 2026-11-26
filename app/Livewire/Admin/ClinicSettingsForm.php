<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Models\User;
use App\Services\ClinicSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Clinic-wide settings (administrators). Values are stored in the settings
 * table and override the config/clinic.php defaults.
 */
#[Title('Clinic settings')]
final class ClinicSettingsForm extends Component
{
    /**
     * Keys use "__" instead of "." so Livewire does not treat them as nested paths.
     *
     * @var array<string, int|bool|string>
     */
    public array $values = [];

    public function mount(ClinicSettings $settings): void
    {
        abort_unless(Auth::user()?->can(Permission::ManageSettings->value) === true, 403);

        foreach ($settings->current() as $key => $value) {
            $this->values[str_replace('.', '__', $key)] = $value;
        }
    }

    public function save(ClinicSettings $settings): void
    {
        /** @var User $actor */
        $actor = Auth::user();
        $input = [];

        foreach ($this->values as $key => $value) {
            $input[str_replace('__', '.', $key)] = $value;
        }

        try {
            $settings->update($actor, $input);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors['values.'.$field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        session()->flash('status', __('Clinic settings saved.'));
    }

    public function render(): View
    {
        return view('livewire.admin.clinic-settings', [
            'definitions' => ClinicSettings::definitions(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }
}
