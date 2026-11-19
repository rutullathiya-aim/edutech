<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentSchool
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Load the school from the {school} ULID -> 404 if not found or soft-deleted.
        $schoolParam = $request->route('school');
        $schoolId = $schoolParam instanceof School ? $schoolParam->id : $schoolParam;

        if (! is_string($schoolId) || ! Str::isUlid($schoolId)) {
            abort(404);
        }

        $school = School::where('id', $schoolId)->first();
        if (! $school) {
            abort(404);
        }

        // 2. Find an active membership for the user in that school -> 404 if there isn't one.
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $hasActiveMembership = $user->memberships()
            ->where('school_id', $school->id)
            ->active()
            ->exists();

        if (! $hasActiveMembership) {
            abort(404);
        }

        // 3. School::isActive() -> otherwise the "school unavailable" page.
        if (! $school->isActive()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'School is currently unavailable.',
                ], 403);
            }

            return response()->view('errors.school-unavailable', [
                'school' => $school,
            ], 403);
        }

        // 4. CurrentSchool::set().
        CurrentSchool::set($school);

        // 5. Set the permission package's current team to this school.
        setPermissionsTeamId($school->id);

        // 6. Make the school the default school route parameter.
        URL::defaults(['school' => $school->id]);

        return $next($request);
    }
}
