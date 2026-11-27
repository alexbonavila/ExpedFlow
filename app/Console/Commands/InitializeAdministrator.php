<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\IdentityInput;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Permission\Models\Role;

final class InitializeAdministrator extends Command
{
    protected $signature = 'expedflow:admin:init';

    protected $description = 'Initialize the first administrator using protected application configuration';

    public function handle(): int
    {
        try {
            $input = IdentityInput::validate(config()->array('identity.bootstrap'), [
                'name' => IdentityInput::nameRules(),
                'email' => IdentityInput::emailRules(),
                'password' => IdentityInput::newPassword(),
            ]);
        } catch (ValidationException) {
            $this->error('Set valid ADMIN_NAME, ADMIN_EMAIL and ADMIN_PASSWORD values in protected configuration.');

            return self::FAILURE;
        }

        try {
            $created = $this->createAdministrator(Arr::string($input, 'name'), Arr::string($input, 'email'), Arr::string($input, 'password'));
        } catch (LogicException|UniqueConstraintViolationException) {
            $this->error('Administrator initialization refused. Ask the owner to review the reserved role and account records.');

            return self::FAILURE;
        }

        $this->info($created
            ? 'Administrator initialized. Remove the bootstrap values and refresh cached configuration.'
            : 'Administrator already initialized for this email. Existing credentials were preserved.');

        return self::SUCCESS;
    }

    private function createAdministrator(string $name, string $email, #[\SensitiveParameter] string $password): bool
    {
        return DB::transaction(function () use ($name, $email, $password): bool {
            $created = DB::table('roles')->insertOrIgnore([
                'name' => User::ADMIN_ROLE,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $role = Role::query()->where('name', User::ADMIN_ROLE)->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
            $administrators = User::role($role)->lockForUpdate()->limit(2)->get();

            if ($administrators->count() === 1 && IdentityInput::email($administrators->firstOrFail()->email) === $email) {
                return false;
            }

            if ($created !== 1 || $administrators->isNotEmpty() || User::query()->where('email', $email)->exists()) {
                throw new LogicException('Administrator state requires owner review.');
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);
            $user->assignRole($role);

            return true;
        }, 3);
    }
}
