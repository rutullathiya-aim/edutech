<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\School;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use SensitiveParameter;

class ShowInvitationController extends Controller
{
    /**
     * Validate a raw invitation token, move it into the session, and redirect away from the token URL.
     */
    public function __invoke(#[SensitiveParameter] string $token): RedirectResponse|Response
    {
        $invitation = Invitation::findPendingByToken($token);
        $invitation?->loadMissing('school');

        if (! $invitation instanceof Invitation
            || ! ($invitation->school instanceof School)
            || ! $invitation->school->isActive()) {
            session()->forget('pending_invitation_token');

            return response()->view('invitations.invalid');
        }

        session()->put('pending_invitation_token', $token);

        return to_route('invitations.accept');
    }
}
