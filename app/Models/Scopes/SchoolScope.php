<?php

namespace App\Models\Scopes;

use App\Tenant\CurrentSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class SchoolScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * Fails closed: if no current school is set, CurrentSchool::idOrFail()
     * throws a NoCurrentSchoolException rather than returning all schools' data.
     *
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('school_id'), '=', CurrentSchool::idOrFail());
    }
}
