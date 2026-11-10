<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateUser extends Command
{
    protected $signature = 'user:create {email} {--name=} {--role=admin : admin or viewer}';

    protected $description = 'Create a website login, or reset its password if it exists';

    public function handle(): int
    {
        $email = $this->argument('email');
        $role = $this->option('role');
        $name = $this->option('name') ?: strtok($email, '@');

        if (! array_key_exists($role, User::ROLES)) {
            $this->error('Role must be admin or viewer.');

            return self::FAILURE;
        }

        $password = $this->secret('Password (at least 8 characters)');
        $check = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email'],
            'password' => ['required', Password::min(8)],
        ]);
        if ($check->fails()) {
            foreach ($check->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['email' => $email], ['name' => $name, 'role' => $role, 'password' => $password]);
        $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." {$role} login for {$email}.");

        return self::SUCCESS;
    }
}
