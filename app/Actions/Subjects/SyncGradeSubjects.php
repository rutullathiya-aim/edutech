<?php

namespace App\Actions\Subjects;

use App\Models\Grade;
use App\Models\GradeSubject;
use App\Models\Subject;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class SyncGradeSubjects
{
    /**
     * Atomically sync one grade's subject curriculum in place to preserve existing IDs and audit history.
     *
     * @param  list<array{subject_id: string, is_optional?: bool, sort_order: int}>  $items
     * @return Collection<int, GradeSubject>
     */
    public function handle(Grade $grade, array $items): Collection
    {
        $schoolId = CurrentSchool::idOrFail();

        if ($grade->school_id !== $schoolId) {
            throw new LogicException('Cannot sync subjects for a grade belonging to another school.');
        }

        return DB::transaction(function () use ($grade, $items): Collection {
            $lockedGrade = Grade::query()
                ->whereKey($grade->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedGrade->isArchived()) {
                throw new LogicException('Cannot manage curriculum for an archived grade.');
            }

            $subjectIds = array_column($items, 'subject_id');

            if (count($subjectIds) !== count(array_unique($subjectIds))) {
                throw new InvalidArgumentException('A subject cannot appear more than once for the same grade.');
            }

            $subjects = Subject::query()
                ->whereIn('id', $subjectIds)
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                /** @var Subject|null $subject */
                $subject = $subjects->get($item['subject_id']);

                if (! $subject instanceof Subject) {
                    throw new LogicException('Subject does not belong to the current school or does not exist.');
                }

                if ($subject->isArchived()) {
                    throw new LogicException("Cannot assign archived subject '{$subject->name}'.");
                }

                if ($item['sort_order'] < 0) {
                    throw new InvalidArgumentException('Subject sort order cannot be negative.');
                }
            }

            $existingBySubject = GradeSubject::query()
                ->where('grade_id', $lockedGrade->id)
                ->get()
                ->keyBy('subject_id');

            $submittedSubjectLookup = array_flip($subjectIds);

            foreach ($existingBySubject as $subjectId => $existingRow) {
                if (! isset($submittedSubjectLookup[$subjectId])) {
                    $existingRow->delete();
                }
            }

            /** @var Collection<int, GradeSubject> $synced */
            $synced = new Collection;

            foreach ($items as $item) {
                $isOptional = (bool) ($item['is_optional'] ?? false);
                $existingRow = $existingBySubject->get($item['subject_id']);

                if ($existingRow instanceof GradeSubject) {
                    $existingRow->is_optional = $isOptional;
                    $existingRow->sort_order = $item['sort_order'];

                    if ($existingRow->isDirty()) {
                        $existingRow->save();
                    }

                    $synced->push($existingRow);

                    continue;
                }

                $synced->push(GradeSubject::create([
                    'grade_id' => $lockedGrade->id,
                    'subject_id' => $item['subject_id'],
                    'is_optional' => $isOptional,
                    'sort_order' => $item['sort_order'],
                ]));
            }

            return $synced->sortBy('sort_order')->values();
        });
    }
}
