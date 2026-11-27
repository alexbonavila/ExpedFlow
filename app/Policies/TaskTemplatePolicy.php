<?php

namespace App\Policies;

use App\Models\ExpedientType;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateChecklistItem;
use App\Models\User;
use App\Support\EffectivePermissions;
use App\Support\PermissionKey;

final class TaskTemplatePolicy
{
    public function __construct(private readonly EffectivePermissions $permissions) {}

    public function viewAny(User $actor, ExpedientType $type): bool
    {
        return $this->permissions->allows($actor, PermissionKey::ViewExpedientTypes)
            && $this->permissions->allows($actor, PermissionKey::ViewTaskTemplates);
    }

    public function view(User $actor, TaskTemplate $template, ExpedientType $type): bool
    {
        return $template->expedient_type_id === $type->id && $this->viewAny($actor, $type);
    }

    public function create(User $actor, ExpedientType $type): bool
    {
        return $this->permissions->allows($actor, PermissionKey::ViewExpedientTypes)
            && $this->permissions->allows($actor, PermissionKey::ManageTaskTemplates);
    }

    public function update(User $actor, TaskTemplate $template, ExpedientType $type): bool
    {
        return $template->expedient_type_id === $type->id && $this->create($actor, $type);
    }

    public function manageChecklist(User $actor, TaskTemplate $template, ExpedientType $type, ?TaskTemplateChecklistItem $item = null): bool
    {
        return ($item === null || $item->task_template_id === $template->id) && $this->update($actor, $template, $type);
    }
}
