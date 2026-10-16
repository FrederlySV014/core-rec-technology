<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Access\Models\PlatformRole;
use App\Modules\Audit\Models\CentralAuditLog;
use App\Modules\Identity\Enums\UserStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreatePlatformAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'platform:create-admin
                            {email? : Correo electrónico del superadministrador}
                            {--name= : Nombre completo del usuario}
                            {--password= : Contraseña (omitir para ingresar de forma oculta o generarla)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea o asigna el primer superadministrador autorizado de la plataforma sin credenciales fijas en el código.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Correo electrónico del administrador:');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('El correo electrónico proporcionado no es válido.');

            return self::FAILURE;
        }

        $superAdminRole = PlatformRole::where('slug', 'super_admin')->first();
        if (! $superAdminRole) {
            $this->error('El rol de plataforma "super_admin" no existe. Ejecute primero php artisan db:seed --class=CentralPlatformRoleSeeder');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        $generatedPassword = null;
        $password = $this->option('password');

        if (! $password) {
            if ($this->input->isInteractive()) {
                $password = $this->secret('Contraseña (deje en blanco para generar una segura):');
            }

            if (! $password) {
                $generatedPassword = Str::password(16);
                $password = $generatedPassword;
            }
        }

        if (! $user) {
            $name = $this->option('name') ?: ($this->input->isInteractive() ? $this->ask('Nombre del administrador:', 'Superadmin') : 'Superadmin');

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]);

            $this->info("Usuario [{$user->email}] creado exitosamente.");
        } else {
            $user->update([
                'status' => UserStatus::Active,
                'password' => Hash::make($password),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            $this->info("Usuario existente [{$user->email}] actualizado con nueva contraseña y estado activo.");
        }

        // Attach super_admin role with active pivot
        $user->platformRoles()->syncWithoutDetaching([
            $superAdminRole->id => [
                'assigned_at' => now(),
                'suspended_at' => null,
            ],
        ]);

        CentralAuditLog::create([
            'user_id' => $user->id,
            'action' => 'platform.admin_created',
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => (string) $user->id,
            'new_values' => [
                'email' => $user->email,
                'role' => $superAdminRole->slug,
            ],
            'created_at' => now(),
        ]);

        $this->info("Rol [{$superAdminRole->name}] asignado correctamente a [{$user->email}].");

        if ($generatedPassword) {
            $this->warn("Contraseña generada: {$generatedPassword}");
            $this->line('Guárdela en un lugar seguro. No se volverá a mostrar.');
        }

        return self::SUCCESS;
    }
}
