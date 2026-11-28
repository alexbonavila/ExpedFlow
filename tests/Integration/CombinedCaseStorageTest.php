<?php

use App\Models\Expedient;
use App\Models\ExpedientSequence;
use App\Models\ExpedientType;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateChecklistItem;
use App\Models\User;
use App\Support\Samba\CaseStorageLock;
use App\Support\Samba\MountVerifier;
use App\Support\Samba\StorageConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\SambaFixture;
use Tests\Support\SambaHttp;

uses(SambaHttp::class);

beforeEach(function (): void {
    $this->prepareStandaloneSambaHttp(initializeDatabase: true);
});

afterEach(function (): void {
    RefreshDatabaseState::$migrated = false;
});

test('commits the case template copy before storage and preserves its data through retry and lifecycle', function (): void {
    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/EXPEDIENTES');
        config(['samba' => $fixture->settings()]);

        $type = ExpedientType::factory()->create(['code' => '05']);
        $template = TaskTemplate::factory()->create(['expedient_type_id' => $type->id, 'name' => 'Document review']);
        TaskTemplateChecklistItem::factory()->create(['task_template_id' => $template->id, 'name' => 'Check signatures']);
        $input = $this->caseInput(['expedient_type_id' => $type->id, 'name' => 'Combined case']);
        $input += ['copy_template' => true, 'template_status_id' => $input['status_id']];
        $actor = $this->authorizationAdmin('en');
        $headers = $this->authorizationBearer($actor);

        $observed = false;
        $verifier = Mockery::mock(MountVerifier::class);
        $verifier->shouldReceive('mountId')->andReturnUsing(function (StorageConfiguration $configuration) use ($fixture, &$observed): int {
            if (! $observed) {
                expect(DB::transactionLevel())->toBe(0);
                $case = Expedient::query()->sole();
                expect($case->samba_relative_path)->toBe('EXPEDIENTES/'.$case->code.' Combined case')
                    ->and(ExpedientSequence::query()->sole()->last_number)->toBe($case->sequence_number)
                    ->and($case->tasks()->sole()->checklistItems()->sole()->name)->toBe('Check signatures');
                $observed = true;
            }

            return $fixture->mountId($configuration);
        });
        $this->app->instance(MountVerifier::class, $verifier);

        $created = $this->api('POST', '/api/v1/expedients', $input, $headers)->assertCreated()
            ->assertJsonPath('storage.status', 'failed')->assertJsonPath('storage.code', 'storage_template_missing')
            ->assertJsonMissingPath('data.samba_relative_path');
        expect($observed)->toBeTrue();
        $case = Expedient::query()->sole();
        $task = $case->tasks()->sole();
        $item = $task->checklistItems()->sole();
        $target = $fixture->root.'/'.$case->samba_relative_path;
        $path = "/api/v1/expedients/{$case->id}";
        $incident = '/api/v1/incidents/'.$created->json('storage.incident_id');

        expect($target)->not->toBeDirectory();
        $this->api('PATCH', "/api/v1/tasks/{$task->id}", ['name' => 'Reviewed'], $headers)->assertOk()
            ->assertJsonPath('data.name', 'Reviewed');
        $this->api('GET', "/api/v1/tasks/{$task->id}/checklist", [], $headers)->assertOk()
            ->assertJsonPath('data.0.id', $item->id);
        $this->api('PATCH', "$path/preferences", ['hidden' => true], $headers)->assertOk()
            ->assertJsonPath('data.hidden', true);
        $second = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
        $this->api('GET', "$path/preferences", [], $second)->assertOk()->assertJsonPath('data.hidden', false);

        $otherType = ExpedientType::factory()->create(['code' => '09']);
        $this->api('PATCH', $path, ['name' => 'Renamed', 'expedient_type_id' => $otherType->id], $headers)->assertOk()
            ->assertJsonPath('data.code', $case->code);
        expect($case->fresh()->samba_relative_path)->toBe($case->samba_relative_path);

        mkdir($fixture->root.'/PLANTILLAS/05', 0700, true);
        file_put_contents($fixture->root.'/PLANTILLAS/05/checklist.txt', 'Original type');
        $this->api('POST', "$path/storage/retry", [], $headers)->assertOk()->assertJsonPath('status', 'ready');
        $this->api('POST', "$path/storage/retry", [], $headers)->assertOk()->assertJsonPath('status', 'ready');
        expect(file_get_contents($target.'/checklist.txt'))->toBe('Original type')
            ->and($case->tasks()->sole()->id)->toBe($task->id)
            ->and($task->checklistItems()->sole()->id)->toBe($item->id)
            ->and(ExpedientSequence::query()->sole()->last_number)->toBe($case->sequence_number);
        $this->assertDatabaseCount('expedients', 1);
        $this->api('GET', "$path/preferences", [], $headers)->assertOk()->assertJsonPath('data.hidden', true);

        $role = Role::create(['name' => 'Combined case reader', 'guard_name' => 'web']);
        $role->givePermissionTo('expedients.view');
        $reader = $this->authorizationBearer(User::factory()->create()->assignRole($role));
        $pdf = "%PDF-1.7\n% Combined case fixture\n%%EOF\n";
        $upload = ['file' => UploadedFile::fake()->createWithContent('Review.pdf', $pdf)];
        $this->sambaMultipart($case->id, $upload, $reader)->assertForbidden();
        app(CaseStorageLock::class)->run($case->id, fn () => $this->sambaMultipart($case->id, $upload, $headers)
            ->assertConflict()->assertJsonPath('code', 'storage_busy'));
        expect(is_file($target.'/Review.pdf'))->toBeFalse();
        $this->sambaMultipart($case->id, $upload, $headers)->assertCreated()->assertJsonPath('code', 'upload_saved');

        $this->api('DELETE', $path, [], $headers)->assertOk();
        expect(file_get_contents($target.'/Review.pdf'))->toBe($pdf);
        $this->api('POST', "$path/restore", [], $headers)->assertOk();
        expect(file_get_contents($target.'/Review.pdf'))->toBe($pdf)
            ->and($case->tasks()->sole()->id)->toBe($task->id);
        $this->api('DELETE', $path, [], $headers)->assertOk();
        $this->api('POST', "$path/force-delete", [], $headers)->assertOk();
        expect(file_get_contents($target.'/Review.pdf'))->toBe($pdf)
            ->and(file_get_contents($target.'/checklist.txt'))->toBe('Original type');
        $this->api('GET', $incident, [], $headers)->assertOk()->assertJsonPath('data.case_code', $case->code)
            ->assertJsonPath('data.case_id', null)->assertJsonPath('data.type', 'storage_template_missing');
    } finally {
        $fixture->clean();
    }
});

test('rolls back case identity and copied rows when the storage intent cannot be saved', function (): void {
    $verifier = Mockery::mock(MountVerifier::class);
    $verifier->shouldNotReceive('mountId');
    $this->app->instance(MountVerifier::class, $verifier);

    $headers = $this->authorizationBearer($this->authorizationAdmin());
    $input = $this->caseInput();
    $template = TaskTemplate::factory()->create(['expedient_type_id' => $input['expedient_type_id']]);
    TaskTemplateChecklistItem::factory()->create(['task_template_id' => $template->id]);
    Expedient::updating(function (Expedient $case): void {
        if ($case->isDirty('samba_relative_path')) {
            expect(DB::transactionLevel())->toBeGreaterThan(0)
                ->and($case->tasks()->sole()->checklistItems()->count())->toBe(1);
            throw new RuntimeException('Synthetic failure persisting the storage intent.');
        }
    });

    $this->api('POST', '/api/v1/expedients', $input + [
        'copy_template' => true, 'template_status_id' => $input['status_id'],
    ], $headers)->assertInternalServerError()->assertJsonPath('code', 'server_error');
    foreach (['expedients', 'expedient_sequences', 'tasks', 'task_checklist_items', 'incidents'] as $table) {
        $this->assertDatabaseEmpty($table);
    }
});
