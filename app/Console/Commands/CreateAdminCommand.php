<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminCommand extends Command
{
    protected $signature = 'mydoc:admin';

    protected $description = 'Create an administrator account interactively';

    public function handle(): int
    {
        $name = $this->ask('Administrator name');
        $email = $this->ask('Administrator email');

        $validator = Validator::make(
            compact('name', 'email'),
            ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email']]
        );
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $password = $this->secret('Administrator password');
        $confirmation = $this->secret('Confirm password');
        $passwordValidator = Validator::make(
            ['password' => $password, 'confirmation' => $confirmation],
            ['password' => ['required', 'string', 'min:8'], 'confirmation' => ['same:password']]
        );
        if ($passwordValidator->fails()) {
            $this->error($passwordValidator->errors()->first());

            return self::FAILURE;
        }

        $admin = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
        $admin->role = 'admin';
        $admin->save();
        $this->info('Administrator account created.');

        return self::SUCCESS;
    }
}
