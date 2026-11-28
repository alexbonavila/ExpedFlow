<?php

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Spatie\Permission\Models\Role;
use Tests\Support\AuthorizationHttp;
use Tests\Support\RefreshGuardedMySql;

uses(AuthorizationHttp::class, RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('task comments preserve authored text and identity while authorized peers edit and delete', function (): void {
    $task = Task::factory()->create();
    $other = Task::factory()->create();
    $author = User::factory()->create()->assignRole('user');
    $peer = User::factory()->create()->assignRole('user');
    $a = $this->authorizationBearer($author);
    $b = $this->authorizationBearer($peer);
    $root = "/api/v1/tasks/{$task->id}/comments";
    foreach (['', "\u{2003}", str_repeat('é', 32768)] as $body) {
        $this->api('POST', $root, ['body' => $body], $a)->assertUnprocessable()->assertJsonValidationErrors('body');
    }
    $body = "  Plain text <&>\n ";
    $result = $this->api('POST', $root, ['body' => $body, 'author_user_id' => $peer->id, 'task_id' => $other->id], $a)
        ->assertCreated()->assertJsonPath('data.body', $body)->assertJsonPath('data.author_user_id', $author->id)
        ->assertJsonPath('data.author.name', $author->name)->assertJsonMissingPath('data.author.email');
    $id = $result->json('data.id');
    $path = "$root/$id";
    $this->api('GET', $root, headers: $b)->assertOk()->assertJsonCount(1, 'data');
    $this->api('GET', "/api/v1/tasks/{$other->id}/comments/$id", headers: $b)->assertNotFound();
    $this->api('GET', $path, headers: $b)->assertOk()->assertJsonMissingPath('data.deleted_at');
    $this->api('PATCH', $path, ['body' => 'Peer edit', 'author_user_id' => $peer->id], $b)
        ->assertOk()->assertJsonPath('data.body', 'Peer edit')->assertJsonPath('data.author_user_id', $author->id);

    $this->api('DELETE', $path, headers: $b)->assertOk()->assertJsonPath('code', 'task_comment_deleted');
    $this->api('GET', $path, headers: $a)->assertNotFound();
    $this->api('GET', "$root/trash", headers: $a)->assertOk()->assertJsonCount(1, 'data');
    $this->api('GET', "$root/trash/$id", headers: $a)->assertOk()->assertJsonStructure(['data' => ['deleted_at']]);
    $this->api('POST', "$path/force-delete", headers: $b)->assertForbidden();
    $this->api('POST', "$path/restore", headers: $b)->assertOk()->assertJsonPath('data.author_user_id', $author->id);
    $admin = $this->authorizationBearer($this->authorizationAdmin());
    $this->api('POST', "$path/force-delete", headers: $admin)->assertNotFound();
    $this->api('DELETE', $path, headers: $b)->assertOk();
    $this->api('POST', "$path/force-delete", headers: $admin)->assertOk()->assertJsonPath('code', 'task_comment_force_deleted');
    $this->assertDatabaseEmpty('task_comments');
});

test('comments require action grants and an active parent and roll back failed changes', function (): void {
    $task = Task::factory()->create();
    $comment = TaskComment::factory()->for($task)->create();
    $root = "/api/v1/tasks/{$task->id}/comments";
    $path = "$root/{$comment->id}";
    $role = Role::create(['name' => 'comment-reader', 'guard_name' => 'web'])->givePermissionTo(['tasks.view', 'expedients.view']);
    $reader = $this->authorizationBearer(User::factory()->create()->assignRole($role));
    $this->api('GET', $root, headers: $reader)->assertOk();
    $this->api('GET', "$root/trash", headers: $reader)->assertForbidden();
    $this->api('GET', '/api/v1/tasks/999999/comments/trash', headers: $reader)->assertForbidden();
    $this->api('PATCH', $path, ['body' => 'Denied'], $reader)->assertForbidden();

    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    TaskComment::updated(function (): never {
        throw new RuntimeException('Synthetic comment update failure.');
    });
    $this->api('PATCH', $path, ['body' => 'Uncommitted'], $headers)->assertInternalServerError();
    expect($comment->fresh()->body)->toBe($comment->body);
    $second = TaskComment::factory()->for($task)->create();
    $second->delete();
    $case = "/api/v1/expedients/{$task->expedient_id}";
    $this->api('DELETE', $case, headers: $headers)->assertOk();
    $this->api('GET', $path, headers: $headers)->assertNotFound();
    $this->api('POST', "$root/{$second->id}/restore", headers: $headers)->assertNotFound();
    $this->api('POST', "$case/restore", headers: $headers)->assertOk();
    $this->api('GET', $path, headers: $headers)->assertOk();
    $this->api('GET', "$root/{$second->id}", headers: $headers)->assertNotFound();
});
