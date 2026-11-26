<?php

namespace App\Actions\Subjects;

use App\Enums\SubjectKind;
use App\Models\Subject;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreateStandardSubjects
{
    /**
     * Standard Indian K-12 scholastic and co-scholastic subject definitions.
     *
     * @var list<array{name: string, kind: SubjectKind}>
     */
    private const array STANDARD_SUBJECTS = [
        ['name' => 'English', 'kind' => SubjectKind::Scholastic],
        ['name' => 'Hindi', 'kind' => SubjectKind::Scholastic],
        ['name' => 'Mathematics', 'kind' => SubjectKind::Scholastic],
        ['name' => 'Science', 'kind' => SubjectKind::Scholastic],
        ['name' => 'Social Science', 'kind' => SubjectKind::Scholastic],
        ['name' => 'EVS', 'kind' => SubjectKind::Scholastic],
        ['name' => 'Computer Science', 'kind' => SubjectKind::Scholastic],
        ['name' => 'Sanskrit', 'kind' => SubjectKind::Scholastic],
        ['name' => 'Physics', 'kind' => SubjectKind::Scholastic],
        ['name' => 'Chemistry', 'kind' => SubjectKind::Scholastic],
        ['name' => 'Biology', 'kind' => SubjectKind::Scholastic],
        ['name' => 'Accountancy', 'kind' => SubjectKind::Scholastic],
        ['name' => 'Business Studies', 'kind' => SubjectKind::Scholastic],
        ['name' => 'Economics', 'kind' => SubjectKind::Scholastic],
        ['name' => 'Art Education', 'kind' => SubjectKind::CoScholastic],
        ['name' => 'Health & Physical Education', 'kind' => SubjectKind::CoScholastic],
        ['name' => 'Work Education', 'kind' => SubjectKind::CoScholastic],
    ];

    /**
     * Create missing standard subjects for the current school within one transaction.
     *
     * @return Collection<int, Subject>
     */
    public function handle(): Collection
    {
        CurrentSchool::idOrFail();

        return DB::transaction(function (): Collection {
            $existingNames = Subject::query()->pluck('name')->all();

            /** @var Collection<int, Subject> $created */
            $created = new Collection;

            foreach (self::STANDARD_SUBJECTS as $definition) {
                if (in_array($definition['name'], $existingNames, true)) {
                    continue;
                }

                $created->push(Subject::create([
                    'name' => $definition['name'],
                    'code' => null,
                    'kind' => $definition['kind'],
                ]));
            }

            return $created;
        });
    }
}
