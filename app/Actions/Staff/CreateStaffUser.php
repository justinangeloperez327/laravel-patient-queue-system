<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates a staff account. There is no public registration: accounts are
 * provisioned by administrators (or the console during bootstrap).
 */
final class CreateStaffUser
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    /**
     * @param  array{name: string, email: string, password: string, password_confirmation: string, roles: list<string>}  $input
     */
    public function handle(array $input): User
    {
        $input['email'] = mb_strtolower(trim($input['email']));

        /** @var array{name: string, email: string, password: string, roles: list<string>} $validated */
        $validated = Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::enum(Role::class)],
        ])->validate();

        return DB::transaction(function () use ($validated): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            $user->syncRoles($validated['roles']);

            return $user;
        });
    }
}
