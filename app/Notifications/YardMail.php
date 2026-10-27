<?php

namespace App\Notifications;

use App\Email\BrandedMail;
use App\Email\BrandedMessage;
use App\Email\MailGroup;
use App\Models\Location;
use App\Models\Tenant;

/**
 * Customer emails: the yard's brand, from the yard's mailbox for that kind
 * of email (or YardRunner's address under the yard's name until the yard
 * connects its own domain), with replies routed to the yard.
 */
class YardMail
{
    public static function for(Tenant $tenant, MailGroup $group = MailGroup::Main, ?Location $yard = null): BrandedMessage
    {
        return BrandedMail::customer($tenant, $group, $yard);
    }
}
