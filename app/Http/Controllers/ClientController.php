<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\AuditSubject;
use App\Support\AuthorizationInput;
use App\Support\BusinessWrite;
use App\Support\IdentityError;
use App\Support\PermissionKey;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class ClientController extends Controller
{
    public function __construct(private readonly BusinessWrite $write, private readonly AuditRecorder $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return ClientResource::collection(Client::query()->orderBy('name')->orderBy('id')->get());
    }

    public function show(string $client): ClientResource
    {
        return new ClientResource(Client::query()->findOrFail(AuthorizationInput::identifier($client)));
    }

    public function store(ClientRequest $request): JsonResponse
    {
        $name = Arr::string($request->validated(), 'name');
        $client = $this->write->run($request, PermissionKey::ManageClients, function (User $actor, AuditContext $context) use ($name): Client {
            $client = $this->save(new Client, $name);
            $this->audit->record($context, AuditEvent::ClientCreated, $client, after: AuditSubject::Client->snapshot($client));

            return $client;
        });

        return (new ClientResource($client))->response()->setStatusCode(201);
    }

    public function update(ClientRequest $request, string $client): ClientResource
    {
        $id = AuthorizationInput::identifier($client);
        $name = Arr::string($request->validated(), 'name');
        $record = $this->write->run($request, PermissionKey::ManageClients,
            function (User $actor, AuditContext $context) use ($id, $name): Client {
                $client = Client::query()->lockForUpdate()->findOrFail($id);
                $before = AuditSubject::Client->snapshot($client);
                $this->save($client, $name);
                $this->audit->record($context, AuditEvent::ClientUpdated, $client, $before, AuditSubject::Client->snapshot($client));

                return $client;
            });

        return new ClientResource($record);
    }

    public function destroy(Request $request, string $client): JsonResponse
    {
        $id = AuthorizationInput::identifier($client);
        $this->write->run($request, PermissionKey::ManageClients, function (User $actor, AuditContext $context) use ($id): void {
            $record = Client::query()->lockForUpdate()->findOrFail($id);
            if (Gate::forUser($actor)->denies('delete', $record)) {
                throw new IdentityError(409, 'client_referenced');
            }

            try {
                if (Client::query()->whereKey($record->id)->delete() === 1) {
                    $this->audit->record($context, AuditEvent::ClientDeleted, $record, AuditSubject::Client->snapshot($record));
                }
            } catch (QueryException $error) {
                if (($error->errorInfo[1] ?? null) === 1451) {
                    throw new IdentityError(409, 'client_referenced');
                }

                throw $error;
            }
        });

        return response()->json(['message' => __('identity.client_deleted'), 'code' => 'client_deleted']);
    }

    private function save(Client $client, string $name): Client
    {
        try {
            if (! $client->forceFill(['name' => $name])->save()) {
                throw new RuntimeException('Client persistence did not complete.');
            }
        } catch (UniqueConstraintViolationException) {
            throw new IdentityError(409, 'client_name_conflict');
        }

        return $client;
    }
}
