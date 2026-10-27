<?php

namespace App\Email;

use App\Models\Location;
use App\Models\Tenant;

/**
 * Where every email starts. The recipient decides the brand:
 *
 * - customer(): the tenant's customers and suppliers get the yard's brand,
 *   from the yard's mailbox for that kind of email.
 * - user(): the tenant's own users get YardRunner's brand, from YardRunner.
 */
final class BrandedMail
{
    public static function customer(Tenant $tenant, MailGroup $group = MailGroup::Main, ?Location $yard = null): BrandedMessage
    {
        $sender = Sender::forCustomer($tenant, $group);
        $mail = (new BrandedMessage(EmailTheme::tenant($tenant, $yard)))->from($sender->address, $sender->name);

        if ($sender->replyTo !== null) {
            $mail->replyTo($sender->replyTo, $tenant->name);
        }

        return $mail;
    }

    /**
     * @param  string|null  $business  the yard business they belong to, shown in the header
     * @param  string|null  $why  the footer's "You're getting this because…" line
     */
    public static function user(?string $business = null, ?string $why = null): BrandedMessage
    {
        $sender = Sender::yardRunner();

        return (new BrandedMessage(EmailTheme::yardRunner($business, $why)))->from($sender->address, $sender->name);
    }
}
