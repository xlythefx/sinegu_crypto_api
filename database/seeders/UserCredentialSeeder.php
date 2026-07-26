<?php

namespace Database\Seeders;

use App\Models\UserCredential;
use Illuminate\Database\Seeder;

class UserCredentialSeeder extends Seeder
{
    /**
     * Local test account for the SineguAlerts auth page.
     * Login: test@sinegu.com / password123
     */
    public function run(): void
    {
        UserCredential::updateOrCreate(
            ['email' => 'test@sinegu.com'],
            [
                'name' => 'Test Trader',
                'password' => 'password123',
                'status' => 'active',
                'email_verified' => true,
            ]
        );

        // Admin account for the admin side (login: admin@sinegu.com / password123)
        UserCredential::updateOrCreate(
            ['email' => 'admin@sinegu.com'],
            [
                'name' => 'Admin',
                'password' => 'password123',
                'status' => 'active',
                'type' => 'admin',
                'email_verified' => true,
            ]
        );
    }
}
