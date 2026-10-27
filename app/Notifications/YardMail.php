<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Customer emails go out from YardRunner's verified sending address under the
 * yard's name, with replies routed to the yard when it has set an address.
 */
class YardMail
{
    public static function for(Tenant $tenant): MailMessage
    {
        $mail = (new MailMessage)->from((string) config('mail.from.address'), $tenant->name);

        if (($replyTo = $tenant->replyToEmail()) !== null) {
            $mail->replyTo($replyTo, $tenant->name);
        }

        return $mail;
    }
}
