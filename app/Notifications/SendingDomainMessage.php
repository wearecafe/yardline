<?php

namespace App\Notifications;

use App\Email\BrandedMail;
use App\Email\Sender;
use App\Models\SendingDomain;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells owners and admins their sending domain is verified, or that a
 * verified domain has stopped passing and customer emails have gone back
 * to YardRunner's address.
 */
class SendingDomainMessage extends Notification
{
    public function __construct(public SendingDomain $domain, public string $status) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $domain = $this->domain;
        $business = $domain->tenant->name ?? null;
        $mail = BrandedMail::user($business, "You're getting this because you manage settings for {$business}.");

        if ($this->status === SendingDomain::VERIFIED) {
            return $mail
                ->subject("{$domain->domain} is verified")
                ->notice('All records found')
                ->title('Your sending domain is verified')
                ->line($domain->sending_enabled
                    ? "Customer emails now come from your own addresses at {$domain->domain}."
                    : "Turn on “Send customer emails from this domain” and your customers will get quotes, invoices and delivery updates from your own addresses.")
                ->action($domain->sending_enabled ? 'Open email settings' : 'Turn on sending', route('sending-domain.edit'))
                ->line('Leave the DNS records in place. If one is removed, we’ll switch back to YardRunner’s address and let you know.');
        }

        $fallback = $domain->tenant !== null ? Sender::fallbackAddress($domain->tenant) : 'YardRunner’s address';

        return $mail
            ->subject("Action needed: {$domain->domain} stopped passing")
            ->notice('Needs attention', 'danger')
            ->title('Your sending domain needs attention')
            ->line("We can no longer find all the DNS records for {$domain->domain}, so customer emails are going out from {$fallback} until they’re back.")
            ->action('Check the records', route('sending-domain.edit'))
            ->line('Nothing is lost: once the records are back, sending from your domain starts again on its own.');
    }
}
