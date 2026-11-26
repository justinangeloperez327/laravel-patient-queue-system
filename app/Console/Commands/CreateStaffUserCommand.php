<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Staff\CreateStaffUser;
use App\Enums\Role;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Provisions a staff account from the console, e.g. the first administrator.
 * The password is always prompted for (never passed as an argument) so it
 * does not end up in shell history.
 */
final class CreateStaffUserCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'staff:create
        {--name= : Full name}
        {--email= : Email address}
        {--role=* : Role(s) to assign (admin, receptionist, nurse, doctor, service_staff)}';

    /**
     * @var string
     */
    protected $description = 'Create a staff user account with one or more roles';

    public function handle(CreateStaffUser $createStaffUser): int
    {
        $nameOption = $this->option('name');
        $emailOption = $this->option('email');
        $roles = $this->stringList($this->option('role'));

        $name = is_string($nameOption) && $nameOption !== '' ? $nameOption : text('Name', required: true);
        $email = is_string($emailOption) && $emailOption !== '' ? $emailOption : text('Email', required: true);

        if ($roles === []) {
            $roles = $this->stringList(multiselect(
                label: 'Roles',
                options: array_combine(
                    Role::values(),
                    array_map(static fn (Role $role): string => $role->label(), Role::cases()),
                ),
                required: true,
            ));
        }

        $secret = password('Password', required: true);
        $confirmation = password('Confirm password', required: true);

        try {
            $user = $createStaffUser->handle([
                'name' => $name,
                'email' => $email,
                'password' => $secret,
                'password_confirmation' => $confirmation,
                'roles' => $roles,
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->components->info("Created staff user #{$user->id} ({$user->email}).");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
