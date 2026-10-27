<?php

namespace App\Notifications;

use App\Email\BrandedMail;
use App\Models\SendingDomain;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The DNS records for a yard's sending domain, for whoever looks after
 * its DNS ("Send these to my IT person").
 */
class SendingDomainRecordsMessage extends Notification
{
    public function __construct(public SendingDomain $domain, public string $business, public string $sender) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $from = $this->sender !== '' ? "{$this->sender} at {$this->business}" : $this->business;
        $mail = BrandedMail::user($this->business, "You're getting this because {$from} sent you these records from YardRunner.")
            ->subject("DNS records to add for {$this->domain->domain}")
            ->title("DNS records for {$this->domain->domain}")
            ->line("{$from} uses YardRunner to email their customers, and would like those emails to come from {$this->domain->domain}. Please add these records to the domain's DNS:");

        foreach ($this->domain->records ?? [] as $record) {
            $optional = ($record['optional'] ?? false) ? ' (optional; skip it if the domain already has a DMARC record)' : '';
            $mail->line("{$record['label']}{$optional}: {$record['type']} record, host {$record['host']}, value {$record['value']}");
        }

        return $mail->line('Hosts are relative to the domain; most DNS providers add the domain for you. Changes usually show up within a few hours, and YardRunner checks for them automatically.');
    }
}
