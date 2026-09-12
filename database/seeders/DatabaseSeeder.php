<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Учётная запись администратора создаётся один раз при установке.
        // Логин и пароль берутся из .env — смените их на моноблоке
        // до того, как к нему получат доступ сотрудники.
        User::firstOrCreate(
            ['login' => env('ADMIN_LOGIN', 'admin')],
            [
                'name'      => 'Администратор',
                'email'     => env('ADMIN_EMAIL', 'admin@localhost'),
                'password'  => Hash::make(env('ADMIN_PASSWORD', 'admin')),
                'is_admin'  => true,
                'is_active' => true,
            ]
        );
    }
}
