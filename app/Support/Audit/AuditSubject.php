<?php

namespace App\Support\Audit;

use App\Models\Client;
use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\Status;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

enum AuditSubject: string
{
    case User = 'user';
    case Role = 'role';
    case Client = 'client';
    case CaseRecord = 'case';
    case Task = 'task';
    case Comment = 'comment';
    case Checklist = 'checklist';
    case CaseType = 'case_type';
    case Status = 'status';
    case Tag = 'tag';
    case Template = 'template';
    case TemplateChecklist = 'template_checklist';

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return match ($this) {
            self::User => User::class,
            self::Role => Role::class,
            self::Client => Client::class,
            self::CaseRecord => Expedient::class,
            self::Task => Task::class,
            self::Comment => TaskComment::class,
            self::Checklist => TaskChecklistItem::class,
            self::CaseType => ExpedientType::class,
            self::Status => Status::class,
            self::Tag => Tag::class,
            self::Template => TaskTemplate::class,
            self::TemplateChecklist => TaskTemplateChecklistItem::class,
        };
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return match ($this) {
            self::User => ['name' => 'text', 'email' => 'redact', 'password' => 'redact', 'locale' => 'locale', 'enabled' => 'flag', 'roles' => 'ids'],
            self::Role => ['name' => 'text', 'permissions' => 'permissions'],
            self::Client => ['name' => 'text'],
            self::CaseRecord => [
                'name' => 'text', 'code' => 'case_code', 'description' => 'redact', 'expedient_type_id' => 'id',
                'requester_client_id' => 'id', 'represented_client_id' => 'id', 'status_id' => 'id',
                'column_position' => 'id', 'state' => 'state', 'tags' => 'ids',
            ],
            self::Task => [
                'name' => 'text', 'description' => 'redact', 'expedient_id' => 'id', 'status_id' => 'id',
                'scheduled_date' => 'date', 'scheduled_time' => 'time', 'position' => 'id', 'state' => 'state', 'assignees' => 'ids',
            ],
            self::Comment => ['task_id' => 'id', 'body' => 'redact', 'state' => 'state'],
            self::Checklist => ['task_id' => 'id', 'name' => 'text', 'completed' => 'flag', 'position' => 'id'],
            self::CaseType => ['name' => 'text', 'code' => 'text'],
            self::Status => ['name' => 'text', 'name_translations' => 'labels', 'position' => 'id'],
            self::Tag => ['name' => 'text', 'color' => 'text'],
            self::Template => ['expedient_type_id' => 'id', 'name' => 'text', 'description' => 'redact', 'position' => 'id'],
            self::TemplateChecklist => ['task_template_id' => 'id', 'name' => 'text', 'position' => 'id'],
        };
    }

    /** @return array<string, mixed> */
    public function snapshot(Model $model): array
    {
        $attributes = $model->getAttributes();
        $snapshot = array_intersect_key($attributes, $this->fields());

        if (array_key_exists('name_translations', $snapshot)) {
            $snapshot['name_translations'] = $model->getAttribute('name_translations');
        }

        return $snapshot;
    }
}
