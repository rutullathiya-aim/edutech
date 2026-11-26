<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentSchool
{
    protected const ACTIVE_REQUEST_KEY = 'tenant_school_middleware_active';

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
        Context::addHidden(self::ACTIVE_REQUEST_KEY, true);

        // 5. Set the permission package's current team to this school.
        setPermissionsTeamId($school->id);
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        // 6. Make the school the default school route parameter.
        URL::defaults(['school' => $school->id]);

        return $next($request);
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     */
    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();
        static::resetContext($user instanceof User ? $user : null);
    }

    /**
     * Reset tenant and permission state if this request entered SetCurrentSchool middleware.
     */
    public static function cleanupFromMiddleware(?User $user = null): void
    {
        if (Context::getHidden(self::ACTIVE_REQUEST_KEY) !== true) {
            return;
        }

        static::resetContext($user);
    }

    /**
     * Clear the active school context and cached permission state.
     */
    public static function resetContext(?User $user = null): void
    {
        CurrentSchool::forget();
        Context::forgetHidden(self::ACTIVE_REQUEST_KEY);
        setPermissionsTeamId(null);

        if ($user instanceof User) {
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
        }
    }
}
