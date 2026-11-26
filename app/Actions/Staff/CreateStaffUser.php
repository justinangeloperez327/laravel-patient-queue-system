<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Creates a staff account. There is no public registration.
 *
 * When no password is supplied (the admin screen), a random one is set and
 * the new user receives a password reset link to choose their own.
 * The console (`staff:create`) passes no actor: shell access is the
 * authorization for bootstrapping the first administrator.
 */
final class CreateStaffUser
{
    use PasswordValidationRules;
    use ProfileValidationRules;
    use SyncsStaffAssignments;

    /**
     * @param  array<string, mixed>  $input  name, email, roles, department_ids?, password?, password_confirmation?
     */
    public function handle(array $input, ?User $actor = null): User
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('create', User::class);
        }

        if (is_string($input['email'] ?? null)) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }

        $input['department_ids'] ??= [];
        $invite = ! array_key_exists('password', $input);

        /** @var array{name: string, email: string, password?: string, roles: list<string>, department_ids: list<int>} $validated */
        $validated = Validator::make($input, [
            ...$this->profileRules(),
            ...($invite ? [] : ['password' => $this->passwordRules()]),
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::enum(Role::class)],
            'department_ids' => ['array'],
            'department_ids.*' => ['integer', 'distinct', Rule::exists('departments', 'id')->where('is_active', true)],
        ])->validate();

        $user = DB::transaction(function () use ($validated, $invite): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $invite ? Str::password(32) : $validated['password'] ?? '',
            ]);

            $user->syncRoles($validated['roles']);
            $this->syncAssignments($user, array_map('intval', $validated['department_ids']));

            return $user;
        });

        if ($invite) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return $user;
    }
}
