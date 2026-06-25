<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Legt den einzigen Admin-Account aus der Umgebung an (Single-Admin-Modell).
 * Idempotent: aktualisiert den vorhandenen Eintrag anhand der E-Mail.
 */
final class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL', '');
        $password = (string) env('ADMIN_PASSWORD', '');

        if ($email === '' || $password === '') {
            // Ohne Zugangsdaten keinen unsicheren Default-Admin anlegen.
            Log::warning('Admin seeding skipped: ADMIN_EMAIL/ADMIN_PASSWORD not set');
            $this->command?->warn('ADMIN_EMAIL/ADMIN_PASSWORD nicht gesetzt — Admin-Seeding übersprungen.');

            return;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => (string) env('ADMIN_NAME', 'Admin'), 'password' => Hash::make($password)],
        );

        Log::info('Admin account seeded', ['email' => $email]);
    }
}
