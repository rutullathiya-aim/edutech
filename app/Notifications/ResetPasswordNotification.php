<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

class ResetPasswordNotification extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    //
}
