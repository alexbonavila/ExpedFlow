<?php

namespace App\Http\Controllers;

use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\AuditSubject;
use App\Support\AuthorizationInput;
use App\Support\BusinessWrite;
use App\Support\CatalogInput;
use App\Support\CatalogWrite;
use App\Support\IdentityError;
use App\Support\PermissionKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class TagCatalogController extends Controller
{
    public function __construct(private readonly BusinessWrite $write, private readonly AuditRecorder $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return TagResource::collection(Tag::query()->orderBy('name')->orderBy('id')->get());
    }

    public function show(string $tag): TagResource
    {
        return new TagResource(Tag::query()->findOrFail(AuthorizationInput::identifier($tag)));
    }

    public function store(Request $request): JsonResponse
    {
        $fields = $this->fields($request);
        $tag = $this->write->run($request, PermissionKey::ManageTags, function (User $actor, AuditContext $context) use ($fields): Tag {
            $tag = $this->save(new Tag, $fields);
            $this->audit->record($context, AuditEvent::TagCreated, $tag, after: AuditSubject::Tag->snapshot($tag));

            return $tag;
        });

        return (new TagResource($tag))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $tag): TagResource
    {
        $id = AuthorizationInput::identifier($tag);
        $fields = $this->fields($request, partial: true);
        $record = $this->write->run($request, PermissionKey::ManageTags,
            function (User $actor, AuditContext $context) use ($id, $fields): Tag {
                $tag = Tag::query()->lockForUpdate()->findOrFail($id);
                $before = AuditSubject::Tag->snapshot($tag);
                $this->save($tag, $fields);
                $this->audit->record($context, AuditEvent::TagUpdated, $tag, $before, AuditSubject::Tag->snapshot($tag));

                return $tag;
            });

        return new TagResource($record);
    }

    public function destroy(Request $request, string $tag): JsonResponse
    {
        $id = AuthorizationInput::identifier($tag);
        $this->write->run($request, PermissionKey::ManageTags, function (User $actor, AuditContext $context) use ($id): void {
            $record = Tag::query()->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('delete', $record);
            $record->expedients()->newPivotQuery()->select('expedient_id')
                ->chunkById(500, function (Collection $links) use ($record, $context): void {
                    $this->audit->record($context, AuditEvent::TagDetached, $record,
                        metadata: ['case_ids' => $links->pluck('expedient_id')->all()]);
                }, 'expedient_id');
            if ($record->delete() !== true) {
                throw new RuntimeException('Tag deletion did not complete.');
            }
            $this->audit->record($context, AuditEvent::TagDeleted, $record, AuditSubject::Tag->snapshot($record));
        });

        return CatalogWrite::deleted('tag_deleted');
    }

    /** @return array<string, mixed> */
    private function fields(Request $request, bool $partial = false): array
    {
        return CatalogInput::validate($request, [
            'name' => CatalogInput::name($partial),
            'color' => [...($partial ? ['sometimes'] : []), 'bail', 'required', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function save(Tag $record, array $fields): Tag
    {
        try {
            if (! $record->forceFill($fields)->save()) {
                throw new RuntimeException('Tag persistence did not complete.');
            }
        } catch (UniqueConstraintViolationException) {
            throw new IdentityError(409, 'tag_name_conflict', 'catalogs');
        }

        return $record;
    }
}
