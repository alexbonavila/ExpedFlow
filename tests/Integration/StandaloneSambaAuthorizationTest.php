<?php

use App\Models\User;
use App\Support\AuthorizationCatalog;
use App\Support\EffectivePermissions;
use App\Support\PermissionKey;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\Support\SambaHttp;

uses(SambaHttp::class);

test('initializes the ordinary CIFS actor before enabling production HTTP security', function (): void {
    $this->prepareStandaloneSambaHttp(initializeDatabase: true);
    try {
        expect($this->app->environment())->toBe('production');
        $role = AuthorizationCatalog::roles()->where('name', AuthorizationCatalog::ORDINARY_ROLE)->sole();
        expect($role->guard_name)->toBe(AuthorizationCatalog::GUARD);
        $actor = User::factory()->create()->assignRole(AuthorizationCatalog::ORDINARY_ROLE);
        expect($actor->fresh()->roles()->sole()->is($role))->toBeTrue();
        $permissions = app(EffectivePermissions::class)->for($actor);
        expect($permissions)->toContain(PermissionKey::CreateExpedients->value, PermissionKey::UploadCaseFiles->value)
            ->not->toContain(PermissionKey::ForceDeleteExpedients->value);

        $this->refreshApplication();
        $this->prepareStandaloneSambaHttp(initializeDatabase: false);
        $actor = User::query()->sole();
        expect($actor->roles()->sole()->is($role))->toBeTrue()
            ->and(app(EffectivePermissions::class)->allows($actor, PermissionKey::UploadCaseFiles))->toBeTrue();
    } finally {
        RefreshDatabaseState::$migrated = false;
    }
});
