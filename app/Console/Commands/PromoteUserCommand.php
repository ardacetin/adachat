<?php

namespace App\Console\Commands;

use App\Domain\Identity\Enums\UserRole;
use App\Models\Group;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Break-glass role assignment. Ada has no password login, so server access
 * is the recovery path: the user record is created (or updated) here and
 * linked to the identity provider on the person's first sign-in.
 */
#[Signature('ada:user:promote {email : E-mail address of the user} {--role=super_admin : super_admin, admin or user} {--name= : Display name when the user is created}')]
#[Description('Create or update a user and assign a role (break-glass access)')]
class PromoteUserCommand extends Command
{
    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $role = (string) $this->option('role');

        $validator = Validator::make(
            ['email' => $email, 'role' => $role],
            [
                'email' => ['required', 'email'],
                'role' => ['required', Rule::enum(UserRole::class)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        $created = $user === null;

        if ($user === null) {
            $user = new User;
            $user->forceFill([
                'email' => $email,
                'name' => (string) ($this->option('name') ?: strstr($email, '@', true)),
                'group_id' => Group::default()->id,
            ]);
        }

        $user->role = UserRole::from($role);
        $user->save();

        $this->components->info(sprintf(
            '%s %s with role [%s].',
            $created ? 'Created' : 'Updated',
            $user->email,
            $user->role->value,
        ));

        if ($created) {
            $this->components->bulletList([
                'The account is linked to the identity provider on first sign-in.',
                'The e-mail domain must be in AUTH_ALLOWED_DOMAINS.',
            ]);
        }

        return self::SUCCESS;
    }
}
