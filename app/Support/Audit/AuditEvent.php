<?php

namespace App\Support\Audit;

enum AuditEvent: string
{
    case UserInitialized = 'user.initialized';
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserEnabled = 'user.enabled';
    case UserDisabled = 'user.disabled';
    case UserPasswordChanged = 'user.password_changed';
    case UserPasswordRecovered = 'user.password_recovered';
    case UserRolesChanged = 'user.roles_changed';
    case UserTokenIssued = 'user.token_issued';
    case UserTokenRevoked = 'user.token_revoked';
    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RoleDeleted = 'role.deleted';
    case RolePermissionsChanged = 'role.permissions_changed';
    case ClientCreated = 'client.created';
    case ClientUpdated = 'client.updated';
    case ClientDeleted = 'client.deleted';
    case CaseCreated = 'case.created';
    case CaseUpdated = 'case.updated';
    case CaseDeleted = 'case.deleted';
    case CaseRestored = 'case.restored';
    case CaseForceDeleted = 'case.force_deleted';
    case CaseTemplatesCopied = 'case.templates_copied';
    case CaseTasksDeleted = 'case.tasks_deleted';
    case CaseDescendantsRemoved = 'case.descendants_removed';
    case TaskDescendantsRemoved = 'task.descendants_removed';
    case CaseTagsChanged = 'case.tags_changed';
    case TaskCreated = 'task.created';
    case TaskUpdated = 'task.updated';
    case TaskMoved = 'task.moved';
    case TaskDeleted = 'task.deleted';
    case TaskRestored = 'task.restored';
    case TaskForceDeleted = 'task.force_deleted';
    case TaskAssignmentsChanged = 'task.assignments_changed';
    case TaskCopied = 'task.copied';
    case CommentCreated = 'comment.created';
    case CommentUpdated = 'comment.updated';
    case CommentDeleted = 'comment.deleted';
    case CommentRestored = 'comment.restored';
    case CommentForceDeleted = 'comment.force_deleted';
    case ChecklistCreated = 'checklist.created';
    case ChecklistUpdated = 'checklist.updated';
    case ChecklistDeleted = 'checklist.deleted';
    case ChecklistReordered = 'checklist.reordered';
    case ChecklistCopied = 'checklist.copied';
    case CaseTypeCreated = 'case_type.created';
    case CaseTypeUpdated = 'case_type.updated';
    case CaseTypeDeleted = 'case_type.deleted';
    case StatusCreated = 'status.created';
    case StatusUpdated = 'status.updated';
    case StatusDeleted = 'status.deleted';
    case StatusReordered = 'status.reordered';
    case TagCreated = 'tag.created';
    case TagUpdated = 'tag.updated';
    case TagDeleted = 'tag.deleted';
    case TagDetached = 'tag.detached';
    case TemplateCreated = 'template.created';
    case TemplateUpdated = 'template.updated';
    case TemplateChecklistRemoved = 'template.checklist_removed';
    case TemplateDeleted = 'template.deleted';
    case TemplateReordered = 'template.reordered';
    case TemplateChecklistCreated = 'template_checklist.created';
    case TemplateChecklistUpdated = 'template_checklist.updated';
    case TemplateChecklistDeleted = 'template_checklist.deleted';
    case TemplateChecklistReordered = 'template_checklist.reordered';
    case StorageProvisioned = 'storage.provisioned';
    case StorageRetried = 'storage.retried';
    case StorageUploaded = 'storage.uploaded';

    public function subject(): AuditSubject
    {
        $prefix = explode('.', $this->value, 2)[0];

        return $prefix === 'storage' ? AuditSubject::CaseRecord : AuditSubject::from($prefix);
    }

    public function category(): string
    {
        return match (true) {
            str_starts_with($this->value, 'storage.') => 'storage',
            $this->subject() === AuditSubject::User, $this->subject() === AuditSubject::Role => 'security',
            default => 'business',
        };
    }
}
