<?php

namespace App\Models\Concerns;

/**
 * Trait for models that are automatically scoped to the current school tenant.
 *
 * In Step C, this trait will attach the automatic fail-closed school filter (global scope)
 * and auto-fill school_id upon creation.
 *
 * Distinct from BelongsToSchool:
 * - Membership uses ONLY BelongsToSchool because login and school selection must query
 *   memberships across all schools before an active tenant school is known.
 * - Invitation uses BOTH BelongsToSchool and ScopedBySchool, but its token lookup
 *   (findPendingByToken) explicitly bypasses scoping via withoutGlobalScopes().
 * - Ordinary Layer 2+ tenant data will use both.
 */
trait ScopedBySchool
{
    /**
     * Boot the ScopedBySchool trait.
     */
    protected static function bootScopedBySchool(): void
    {
        // Step C will register the SchoolScope global scope and creating auto-filler here.
    }
}
