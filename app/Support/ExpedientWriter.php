<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ExpedientWriter
{
    public function __construct(
        private readonly AnnualNumbering $numbering,
        private readonly CaseOrdering $ordering,
        private readonly ExpedientLifecycle $lifecycle,
    ) {}

    /** @param array<string, mixed> $input Validated case fields from the protected write transaction. */
    public function create(User $actor, array $input): Expedient
    {
        Gate::forUser($actor)->authorize('create', Expedient::class);
        $keys = $this->numbering->allocate();
        $status = Arr::integer($input, 'status_id');
        $this->ordering->lockStatuses($status);
        $type = $this->lockReferences($input);
        $data = Arr::only($input, ['name', 'description', 'requester_client_id', 'represented_client_id']);

        $case = (new Expedient)->forceFill($data + $keys + [
            'status_id' => $status,
            'expedient_type_id' => $type->id,
            'code' => sprintf('%04d%03d%s', $keys['year'], $keys['sequence_number'], $type->code),
            'column_position' => $this->ordering->appendTo($status),
            'column_version' => 1,
        ]);
        $this->save($case);

        return $case;
    }

    /** @param array<string, mixed> $input Validated partial case fields from the protected write transaction. */
    public function update(User $actor, int $id, array $input): Expedient
    {
        $case = $this->lifecycle->lockParent($id);
        Gate::forUser($actor)->authorize('update', $case);
        $status = Arr::integer($input, 'status_id', $case->status_id);
        $this->ordering->lockStatuses($case->status_id, $status);
        $data = Arr::only($input, ['name', 'description', 'expedient_type_id', 'requester_client_id', 'represented_client_id', 'status_id']);
        $this->lockReferences($data + $case->only(['expedient_type_id', 'requester_client_id', 'represented_client_id']));

        $case->forceFill($data);
        if ($case->isDirty('status_id')) {
            $case->forceFill([
                'column_position' => $this->ordering->appendTo($status),
                'column_version' => $this->ordering->increment($case->column_version),
            ]);
        }
        $this->save($case);

        return $case;
    }

    private function save(Expedient $case): void
    {
        if (! $case->save()) {
            throw new RuntimeException('Case persistence did not complete.');
        }
    }

    /** @param array<string, mixed> $input */
    private function lockReferences(array $input): ExpedientType
    {
        $type = ExpedientType::query()->sharedLock()->find(Arr::integer($input, 'expedient_type_id'));
        if ($type === null) {
            throw ValidationException::withMessages(['expedient_type_id' => __('identity.case_reference_invalid')]);
        }

        $requester = Arr::integer($input, 'requester_client_id');
        $represented = isset($input['represented_client_id']) ? Arr::integer($input, 'represented_client_id') : null;
        if ($requester === $represented) {
            throw ValidationException::withMessages(['represented_client_id' => __('identity.case_clients_distinct')]);
        }
        $clients = Client::query()->whereKey($represented === null ? [$requester] : [$requester, $represented])
            ->orderBy('id')->sharedLock()->get(['id']);
        if ($clients->find($requester) === null) {
            throw ValidationException::withMessages(['requester_client_id' => __('identity.case_reference_invalid')]);
        }
        if ($represented !== null && $clients->find($represented) === null) {
            throw ValidationException::withMessages(['represented_client_id' => __('identity.case_reference_invalid')]);
        }

        return $type;
    }
}
