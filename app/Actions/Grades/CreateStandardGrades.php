<?php

namespace App\Actions\Grades;

use App\Models\Grade;
use App\Tenant\CurrentSchool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreateStandardGrades
{
    /**
     * Standard Indian K-12 grade definitions.
     *
     * @var list<array{name: string, short_name: string, sort_order: int}>
     */
    private const array STANDARD_GRADES = [
        ['name' => 'Nursery', 'short_name' => 'Nursery', 'sort_order' => 1],
        ['name' => 'LKG', 'short_name' => 'LKG', 'sort_order' => 2],
        ['name' => 'UKG', 'short_name' => 'UKG', 'sort_order' => 3],
        ['name' => 'Class 1', 'short_name' => '1', 'sort_order' => 4],
        ['name' => 'Class 2', 'short_name' => '2', 'sort_order' => 5],
        ['name' => 'Class 3', 'short_name' => '3', 'sort_order' => 6],
        ['name' => 'Class 4', 'short_name' => '4', 'sort_order' => 7],
        ['name' => 'Class 5', 'short_name' => '5', 'sort_order' => 8],
        ['name' => 'Class 6', 'short_name' => '6', 'sort_order' => 9],
        ['name' => 'Class 7', 'short_name' => '7', 'sort_order' => 10],
        ['name' => 'Class 8', 'short_name' => '8', 'sort_order' => 11],
        ['name' => 'Class 9', 'short_name' => '9', 'sort_order' => 12],
        ['name' => 'Class 10', 'short_name' => '10', 'sort_order' => 13],
        ['name' => 'Class 11', 'short_name' => '11', 'sort_order' => 14],
        ['name' => 'Class 12', 'short_name' => '12', 'sort_order' => 15],
    ];

    /**
     * Create missing standard grades for the current school within one transaction.
     *
     * @return Collection<int, Grade>
     */
    public function handle(): Collection
    {
        CurrentSchool::idOrFail();

        return DB::transaction(function (): Collection {
            $existingNames = Grade::query()->pluck('name')->all();
            $existingSortOrders = Grade::query()->pluck('sort_order')->all();

            /** @var Collection<int, Grade> $created */
            $created = new Collection;

            foreach (self::STANDARD_GRADES as $attributes) {
                if (
                    in_array($attributes['name'], $existingNames, true)
                    || in_array($attributes['sort_order'], $existingSortOrders, true)
                ) {
                    continue;
                }

                $created->push(Grade::create($attributes));
            }

            return $created;
        });
    }
}
