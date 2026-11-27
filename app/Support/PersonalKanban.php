<?php

namespace App\Support;

use App\Models\Expedient;
use App\Models\ExpedientUserPreference;
use App\Models\Status;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class PersonalKanban
{
    public function __construct(
        private readonly ExpedientLifecycle $lifecycle,
        private readonly CaseOrdering $ordering,
        private readonly ExpedientWriter $writer,
    ) {}

    /** @return list<array{status: Status, expedients: list<array{id: int, code: string, name: string, hidden: bool}>}> */
    public function board(User $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            $statuses = Status::query()->orderBy('position')->orderBy('id')->get();
            $columns = Expedient::query()->get(['id', 'status_id', 'code', 'name', 'column_position', 'column_version'])
                ->groupBy('status_id');
            $preferences = ExpedientUserPreference::query()->where('user_id', $actor->id)
                ->whereHas('expedient')->get()->keyBy('expedient_id');
            $board = [];
            foreach ($statuses as $status) {
                $entries = [];
                foreach ($this->ordered($columns->get($status->id, new Collection), $preferences) as $case) {
                    $entries[] = [
                        'id' => $case->id,
                        'code' => $case->code,
                        'name' => $case->name,
                        'hidden' => $preferences->get($case->id)->hidden ?? false,
                    ];
                }
                $board[] = ['status' => $status, 'expedients' => $entries];
            }

            return $board;
        });
    }

    /** @return array{expedient_id: int, position: ?int, hidden: bool} */
    public function preference(User $actor, int $id): array
    {
        $case = Expedient::query()->findOrFail($id);
        $preference = $case->preferences()->where('user_id', $actor->id)->first();
        Gate::forUser($actor)->authorize('manage', [ExpedientUserPreference::class, $case, $actor, $preference]);

        return $this->projection($case, $preference);
    }

    /** @return array{expedient_id: int, position: ?int, hidden: bool} */
    public function setHidden(User $actor, int $id, bool $hidden): array
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('viewAny', Expedient::class);
        $case = $this->lifecycle->lockParent($id);
        $preference = $case->preferences()->where('user_id', $actor->id)->lockForUpdate()->first();
        $gate->authorize('manage', [ExpedientUserPreference::class, $case, $actor, $preference]);

        $preference = $this->persist($actor, $case, $preference, ['hidden' => $hidden]);

        return $this->projection($case, $preference);
    }

    /** @return array{expedient_id: int, position: int, hidden: bool, status_id: int} */
    public function move(User $actor, int $id, int $destination, ?int $position): array
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('viewAny', Expedient::class);
        $initial = Expedient::query()->findOrFail($id);
        $source = $initial->status_id;
        $changed = $source !== $destination;

        if ($changed) {
            $gate->authorize('update', $initial);
        }

        $statusIds = $changed ? [$source, $destination] : [$source];
        $ids = Expedient::query()->whereIn('status_id', $statusIds)->orderBy('id')->pluck('id')->all();
        $cases = Expedient::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        $case = $this->lifecycle->lockParent($id);

        if ($case->status_id !== $source || count($ids) !== $cases->count()
            || $cases->contains(fn (Expedient $entry): bool => ! in_array($entry->status_id, $statusIds, true))) {
            throw new IdentityError(409, 'board_membership_conflict', 'catalogs');
        }

        $gate->authorize('manage', [ExpedientUserPreference::class, $case, $actor]);
        $this->ordering->lockStatuses(...$statusIds);

        $remaining = $cases->reject(fn (Expedient $entry): bool => $entry->id === $id);
        $destinationCases = $remaining->whereStrict('status_id', $destination);
        $last = $destinationCases->count() + 1;
        $position ??= $last;
        if ($position > $last) {
            throw ValidationException::withMessages(['position' => __('catalogs.move_position_invalid')]);
        }

        // Retain the locked pre-move models for ordering and let F acquire reference locks before preferences.
        $moved = $changed ? $this->writer->update($actor, $id, ['status_id' => $destination]) : $case;
        $preferences = ExpedientUserPreference::query()->where('user_id', $actor->id)->whereIn('expedient_id', $ids)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('expedient_id');

        $destinationOrder = $this->ordered($destinationCases, $preferences)->all();
        array_splice($destinationOrder, $position - 1, 0, [$moved]);
        $this->rank($actor, $destinationOrder, $preferences);

        if ($changed) {
            $this->rank($actor, array_values($this->ordered($remaining->whereStrict('status_id', $source), $preferences)->all()), $preferences);
        }

        return [
            'expedient_id' => $id,
            'position' => $position,
            'hidden' => $preferences->get($id)->hidden ?? false,
            'status_id' => $destination,
        ];
    }

    /**
     * @param  Collection<int, Expedient>  $cases
     * @param  Collection<int, ExpedientUserPreference>  $preferences
     * @return Collection<int, Expedient>
     */
    private function ordered(Collection $cases, Collection $preferences): Collection
    {
        return $cases->sortBy(function (Expedient $case) use ($preferences): array {
            $rank = $this->validRank($case, $preferences->get($case->id));

            return [$rank === null, $rank ?? 0, $case->column_position, $case->id];
        })->values();
    }

    /**
     * @param  list<Expedient>  $cases
     * @param  Collection<int, ExpedientUserPreference>  $preferences
     */
    private function rank(User $actor, array $cases, Collection $preferences): void
    {
        foreach ($cases as $index => $case) {
            $this->persist($actor, $case, $preferences->get($case->id), [
                'position' => $index + 1,
                'column_version' => $case->column_version,
            ]);
        }
    }

    /** @param array{hidden?: bool, position?: int, column_version?: int} $fields */
    private function persist(User $actor, Expedient $case, ?ExpedientUserPreference $preference, array $fields): ExpedientUserPreference
    {
        $preference ??= new ExpedientUserPreference;
        $preference->forceFill($fields + ['user_id' => $actor->id, 'expedient_id' => $case->id]);
        if (! $preference->save()) {
            throw new RuntimeException('Case preference persistence did not complete.');
        }

        return $preference;
    }

    /** @return array{expedient_id: int, position: ?int, hidden: bool} */
    private function projection(Expedient $case, ?ExpedientUserPreference $preference): array
    {
        return [
            'expedient_id' => $case->id,
            'position' => $this->validRank($case, $preference),
            'hidden' => $preference->hidden ?? false,
        ];
    }

    private function validRank(Expedient $case, ?ExpedientUserPreference $preference): ?int
    {
        if ($preference === null || $preference->column_version !== $case->column_version) {
            return null;
        }

        $rank = filter_var($preference->position, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $rank === false ? null : $rank;
    }
}
