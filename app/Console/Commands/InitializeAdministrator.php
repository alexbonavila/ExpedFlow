<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\AuditSubject;
use App\Support\AuthorizationCatalog;
use App\Support\IdentityInput;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LogicException;

final class InitializeAdministrator extends Command
{
    protected $signature = 'expedflow:admin:init';

    protected $description = 'Initialize the first administrator using protected application configuration';

    public function handle(AuthorizationCatalog $catalog, AuditRecorder $audit): int
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
            $created = $this->createAdministrator($catalog, $audit, Arr::string($input, 'name'), Arr::string($input, 'email'), Arr::string($input, 'password'));
        } catch (LogicException|UniqueConstraintViolationException) {
            $this->error('Administrator initialization refused. Ask the owner to review the reserved role and account records.');

            return self::FAILURE;
        }

        $this->info($created
            ? 'Administrator initialized. Remove the bootstrap values and refresh cached configuration.'
            : 'Administrator already initialized for this email. Existing credentials were preserved.');

        return self::SUCCESS;
    }

    private function createAdministrator(AuthorizationCatalog $catalog, AuditRecorder $audit, string $name, string $email, #[\SensitiveParameter] string $password): bool
    {
        $context = AuditContext::bootstrap();

        return DB::transaction(function () use ($catalog, $audit, $context, $name, $email, $password): bool {
            [$role, $created] = $catalog->ensureAdministratorRole();
            $administrators = User::role($role)->orderBy('users.id')->lockForUpdate()->limit(2)->get();

            if ($administrators->count() === 1 && IdentityInput::email($administrators->firstOrFail()->email) === $email) {
                return false;
            }

            $pending = DB::table('seeded_catalogs')->where('catalog', AuthorizationCatalog::BOOTSTRAP_PENDING);
            if ((! $created && ! $pending->exists()) || $administrators->isNotEmpty() || User::query()->where('email', $email)->exists()) {
                throw new LogicException('Administrator state requires owner review.');
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);
            $user->assignRole($role);
            $catalog->install();
            $pending->delete();
            $audit->record($context, AuditEvent::UserInitialized, $user, after: AuditSubject::User->snapshot($user) + [
                'roles' => [$role->id], 'enabled' => true,
            ]);

            return true;
        }, 3);
    }
}
