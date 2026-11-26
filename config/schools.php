<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Trial Period (Days)
    |--------------------------------------------------------------------------
    |
    | The number of days a newly registered school remains on trial before
    | an active subscription is required.
    |
    */

    'trial_days' => (int) env('SCHOOL_TRIAL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Current Terms & Privacy Policy Version
    |--------------------------------------------------------------------------
    |
    | Version string recorded in the audit trail whenever a school owner
    | accepts the Terms of Service and Privacy Policy during registration.
    |
    */

    'terms_version' => env('SCHOOL_TERMS_VERSION', '2026-11-01'),

];
