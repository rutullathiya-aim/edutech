<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Invitation Expiry Days
    |--------------------------------------------------------------------------
    |
    | The default number of days before an invitation expires.
    |
    */
    'expiry_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Daily Invitation Limit Per School
    |--------------------------------------------------------------------------
    |
    | Maximum number of new invitations a school may send per calendar day.
    |
    */
    'daily_limit' => 50,

    /*
    |--------------------------------------------------------------------------
    | Resend Cooldown & Limit
    |--------------------------------------------------------------------------
    |
    | Minimum wait in seconds between resending the same invitation, and the
    | total maximum number of sends permitted per invitation.
    |
    */
    'resend_cooldown_seconds' => 60,
    'max_sends' => 5,
];
