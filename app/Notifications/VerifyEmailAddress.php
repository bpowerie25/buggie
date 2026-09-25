<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's verification email, sent from the queue. Registering must never fail
 * because the mail server is down or not configured yet — the account is made, and
 * the link can be sent again from the verification page.
 */
class VerifyEmailAddress extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
