<?php

namespace App\Notifications;

use App\Email\MailGroup;
use App\Models\PurchaseOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PurchaseOrderMessage extends Notification
{
    public function __construct(public PurchaseOrder $po) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $po = $this->po;
        $mail = YardMail::for($po->tenant, MailGroup::Orders, $po->location)
            ->subject("Purchase order {$po->number} from {$po->tenant->name}")
            ->title("Purchase order {$po->number}")
            ->facts([
                'Deliver to' => $po->location->name,
                'Needed by' => $po->expected_on?->format('F j, Y'),
            ])
            ->greeting('Hello'.($po->supplier->contact_name ? " {$po->supplier->contact_name}" : ''))
            ->line("Please supply the following to {$po->location->name}".($po->expected_on ? ' by '.$po->expected_on->format('F j') : '').':');

        foreach ($po->lines as $line) {
            $mail->line('• '.rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.')." {$line->product->unit->label()} {$line->product->name} ({$line->product->sku})");
        }

        if ($po->notes) {
            $mail->line($po->notes);
        }

        return $mail->line('Please quote our PO number on the delivery slip.')->salutation($po->tenant->name);
    }
}
