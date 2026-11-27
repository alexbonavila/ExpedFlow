<?php

use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\ExpedientUserPreference;
use App\Models\Status;
use App\Models\Tag;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateChecklistItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RefreshGuardedMySql;

uses(RefreshGuardedMySql::class);

test('initialization seeds one localized ordered catalog and preserves later customizations and deletions', function () {
    $this->seed(DatabaseSeeder::class);

    $statuses = Status::query()->orderBy('position')->get();
    expect($statuses->pluck('name')->all())
        ->toBe(['En espera', 'Pendientes', 'En curso', 'Paradas', 'Para verificar', 'Finalizadas']);
    expect($statuses->pluck('position')->all())->toBe([1, 2, 3, 4, 5, 6]);
    expect($statuses->pluck('name_translations.ca')->all())
        ->toBe(['En espera', 'Pendents', 'En curs', 'Aturades', 'Per verificar', 'Finalitzades']);
    expect($statuses->pluck('name_translations.en')->all())
        ->toBe(['Waiting', 'Pending', 'In progress', 'Paused', 'To verify', 'Completed']);
    expect($statuses->pluck('name_translations.es')->all())->toBe($statuses->pluck('name')->all());
    $this->assertDatabaseEmpty('users');

    $edited = $statuses->first();
    $removed = $statuses->last();
    $edited->forceFill(['name' => 'Customized', 'name_translations' => null, 'position' => 50])->save();
    $removed->delete();

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('statuses', 5);
    expect(DB::table('seeded_catalogs')->orderBy('catalog')->pluck('catalog')->all())
        ->toBe(['authorization_admin_pending', 'authorization_v1', 'initial_statuses']);
    $this->assertModelMissing($removed);
    expect($edited->refresh()->name)->toBe('Customized');
    expect($edited->position)->toBe(50);
    expect($edited->name_translations)->toBeNull();

    Status::query()->delete();
    $this->seed(DatabaseSeeder::class);
    $this->assertDatabaseEmpty('statuses');
});

test('templates belong to a type and carry ordered checklist data without dates or assignees', function () {
    $type = ExpedientType::factory()->create();
    $template = TaskTemplate::factory()->for($type, 'type')->create(['description' => 'Template description']);
    $second = TaskTemplateChecklistItem::factory()->for($template, 'template')->create(['position' => 20]);
    $first = TaskTemplateChecklistItem::factory()->for($template, 'template')->create(['position' => 10]);

    expect($template->type->is($type))->toBeTrue();
    expect($type->templates()->sole()->is($template))->toBeTrue();
    expect($first->template->is($template))->toBeTrue();
    expect($template->checklistItems()->orderBy('position')->get()->modelKeys())->toBe([$first->id, $second->id]);
    expect(Schema::getColumnListing('task_templates'))->not->toContain('scheduled_date', 'scheduled_time', 'user_id');
    expect(fn () => $type->delete())->toThrow(QueryException::class, 'task_templates_expedient_type_id_foreign');

    $template->delete();

    $this->assertModelMissing($first);
    $this->assertModelMissing($second);
    $this->assertModelExists($type);
});

test('tag identity ignores case and accents, preserves the label and validates the color', function () {
    $tag = Tag::factory()->create(['name' => "\u{00A0}Café  Projects ", 'color' => '#aDe012']);

    expect($tag->refresh()->name)->toBe('Café  Projects');
    expect($tag->color)->toBe('#aDe012');
    expect(fn () => Tag::factory()->create(['name' => 'cafe  projects']))->toThrow(UniqueConstraintViolationException::class);
    expect(Tag::factory()->create(['name' => 'Cafe Projects'])->exists)->toBeTrue();
    expect(fn () => Tag::factory()->create(['name' => ' ']))->toThrow(QueryException::class, 'tags_name_check');
    expect(fn () => Tag::factory()->create(['color' => 'purple']))->toThrow(QueryException::class, 'tags_color_check');
});

test('case tags and personal preferences remain distinct and sparse per user', function () {
    $case = Expedient::factory()->create()->refresh();
    $tag = Tag::factory()->create();
    $otherTag = Tag::factory()->create();
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    expect($case->preferences()->count())->toBe(0);
    $case->tags()->attach([$tag->id, $otherTag->id]);

    $hidden = ExpedientUserPreference::factory()->for($case)->for($user)->create(['hidden' => 1]);
    $ordered = ExpedientUserPreference::factory()->for($case)->for($otherUser)->create([
        'position' => 9,
        'column_version' => $case->column_version,
    ]);

    expect($case->tags()->get()->modelKeys())->toEqualCanonicalizing([$tag->id, $otherTag->id]);
    expect($tag->expedients()->sole()->is($case))->toBeTrue();
    expect($case->preferences()->get()->modelKeys())->toEqualCanonicalizing([$hidden->id, $ordered->id]);
    expect($hidden->expedient->is($case))->toBeTrue();
    expect($hidden->user->is($user))->toBeTrue();
    expect($user->expedientPreferences()->sole()->is($hidden))->toBeTrue();
    expect($hidden->refresh()->hidden)->toBeTrue();
    expect($hidden->position)->toBeNull();
    expect($hidden->column_version)->toBeNull();
    expect($ordered->refresh()->hidden)->toBeFalse();
    expect($ordered->position)->toBe(9);

    expect(fn () => $case->tags()->attach($tag))->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => ExpedientUserPreference::factory()->for($case)->for($user)->create())
        ->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => $hidden->forceFill(['position' => 7])->save())
        ->toThrow(QueryException::class, 'expedient_user_preferences_state_check');
    expect(fn () => DB::table('expedient_user_preferences')->where('id', $hidden->id)->update(['hidden' => 2]))
        ->toThrow(QueryException::class, 'expedient_user_preferences_state_check');

    $tag->delete();
    $this->assertDatabaseMissing('expedient_tag', ['tag_id' => $tag->id]);
    $case->forceFill(['status_id' => Status::factory()->create()->id, 'column_version' => 2, 'column_position' => 100])->save();
    expect($ordered->refresh()->column_version)->toBe(1);
    expect($hidden->refresh()->hidden)->toBeTrue();

    $case->delete();
    $this->assertModelExists($hidden);
    $this->assertDatabaseHas('expedient_tag', ['expedient_id' => $case->id]);

    $case->forceDelete();

    $this->assertModelMissing($hidden);
    $this->assertModelMissing($ordered);
    $this->assertDatabaseMissing('expedient_tag', ['expedient_id' => $case->id]);
    $this->assertModelExists($otherTag);
});
