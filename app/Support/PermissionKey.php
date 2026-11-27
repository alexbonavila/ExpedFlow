<?php

namespace App\Support;

enum PermissionKey: string
{
    case ManageUsers = 'users.manage';
    case ManageRoles = 'roles.manage';
    case AssignRoles = 'roles.assign';
    case ViewStatuses = 'statuses.view';
    case ManageStatuses = 'statuses.manage';
    case ViewClients = 'clients.view';
    case ManageClients = 'clients.manage';
    case ViewExpedientTypes = 'expedient-types.view';
    case ManageExpedientTypes = 'expedient-types.manage';
    case ViewExpedients = 'expedients.view';
    case CreateExpedients = 'expedients.create';
    case UpdateExpedients = 'expedients.update';
    case DeleteExpedients = 'expedients.delete';
    case RestoreExpedients = 'expedients.restore';
    case ForceDeleteExpedients = 'expedients.force-delete';
    case ViewTasks = 'tasks.view';
    case CreateTasks = 'tasks.create';
    case UpdateTasks = 'tasks.update';
    case AssignTasks = 'tasks.assign';
    case ReorderTasks = 'tasks.reorder';
    case DeleteTasks = 'tasks.delete';
    case RestoreTasks = 'tasks.restore';
    case ForceDeleteTasks = 'tasks.force-delete';
    case ViewTaskTemplates = 'task-templates.view';
    case ManageTaskTemplates = 'task-templates.manage';
    case ManageTaskChecklist = 'task-checklists.manage';
    case CreateTaskComments = 'task-comments.create';
    case UpdateTaskComments = 'task-comments.update';
    case DeleteTaskComments = 'task-comments.delete';
    case RestoreTaskComments = 'task-comments.restore';
    case ForceDeleteTaskComments = 'task-comments.force-delete';
    case ViewTags = 'tags.view';
    case ManageTags = 'tags.manage';
    case AssociateTags = 'tags.associate';
    case ManagePersonalKanban = 'kanban.manage';
    case ViewCalendar = 'calendar.view';
    case UploadCaseFiles = 'case-files.upload';
    case ViewIncidents = 'incidents.view';

    public function group(): string
    {
        return str($this->value)->before('.')->value();
    }

    public function action(): string
    {
        return str($this->value)->after('.')->value();
    }

    public function restricted(): bool
    {
        return match ($this) {
            self::ManageUsers, self::ManageRoles, self::AssignRoles, self::ManageStatuses,
            self::ForceDeleteExpedients, self::ForceDeleteTasks, self::ForceDeleteTaskComments => true,
            default => false,
        };
    }
}
