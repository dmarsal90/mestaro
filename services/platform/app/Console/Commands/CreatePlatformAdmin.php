<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

final class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {email} {name}';

    protected $description = 'Create a platform administrator (password is asked interactively, never passed as an argument)';

    public function handle(): int
    {
        $password = $this->secret('Password (min 12 characters)');

        $validator = Validator::make(
            ['email' => strtolower($this->argument('email')), 'name' => $this->argument('name'), 'password' => $password],
            [
                'email' => ['required', 'email', 'max:254', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', Password::min(12)],
            ],
        );
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        // Not mass-assignable on purpose: only this command grants platform admin.
        $user = (new User)->forceFill([...$validator->validated(), 'is_platform_admin' => true]);
        $user->save();

        $this->info("Platform admin {$user->email} created.");

        return self::SUCCESS;
    }
}
