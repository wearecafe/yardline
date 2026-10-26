<?php

namespace App\Rentals\Notifications;

use App\Models\Rental;
use App\Support\Demo;
use App\Support\Money;
use Throwable;

/**
 * Booking confirmations, signing links, pickup and return reminders,
 * overdue notices and receipts, by email and text. Each kind goes once per
 * rental (overdue: once a day), recorded in rental_notifications.
 */
class RentalNotifier
{
    /**
     * @return bool whether anything was sent
     */
    public function send(Rental $rental, string $kind, bool $force = false): bool
    {
        $rental->loadMissing(['customer', 'items', 'tenant', 'location', 'agreement']);
        $customer = $rental->customer;

        if ($customer === null || (blank($customer->email) && blank($customer->phone))) {
            return false;
        }

        if (! $force && $this->alreadySent($rental, $kind)) {
            return false;
        }

        $channels = array_values(array_filter([
            filled($customer->email) ? 'mail' : null,
            filled($customer->phone) && ($rental->text_reminders || in_array($kind, ['signing_link', 'confirmation'], true)) ? 'sms' : null,
        ]));

        if ($channels === []) {
            return false;
        }

        $message = $this->message($rental, $kind, $channels);

        try {
            // The demo yard's customers are fictional: log it, don't send it.
            if (! Demo::isCurrentTenant()) {
                $customer->notify($message);
            }
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        foreach ($channels as $channel) {
            $rental->notificationsSent()->create(['kind' => $kind, 'channel' => $channel, 'sent_at' => now()]);
        }

        return true;
    }

    private function alreadySent(Rental $rental, string $kind): bool
    {
        $query = $rental->notificationsSent()->where('kind', $kind);

        if ($kind === 'overdue') {
            $query->where('sent_at', '>=', now()->subHours(20));
        }

        return $query->exists();
    }

    /**
     * @param  list<string>  $channels
     */
    private function message(Rental $rental, string $kind, array $channels): RentalMessage
    {
        $tz = $rental->tenant->timezone;
        $items = $rental->items->pluck('description')->unique()->implode(', ');
        $out = $rental->starts_at->setTimezone($tz)->format('D M j, g:i a');
        $back = $rental->ends_at->setTimezone($tz)->format('D M j, g:i a');
        $signUrl = $rental->signing_token ? route('rentals.public-sign', $rental->signing_token) : null;
        $signed = $rental->isSigned();

        [$subject, $text, $label, $url, $attachment] = match ($kind) {
            'confirmation' => [
                "Rental {$rental->number} is booked",
                "Your rental is booked: {$items}, {$out} to {$back}.".($signed ? '' : ' Sign the rental agreement before pickup.'),
                $signed ? null : 'Sign the agreement',
                $signed ? null : $signUrl,
                null,
            ],
            'signing_link' => [
                "Sign your rental agreement, {$rental->number}",
                "Please review and sign the rental agreement for {$items}.",
                'Review and sign',
                $signUrl,
                null,
            ],
            'pickup_reminder' => [
                "Your rental starts {$out}",
                "Reminder: {$items} is booked from {$out} at {$rental->location->name}. Bring your driver’s licence.",
                null, null, null,
            ],
            'return_reminder' => [
                "{$items} is due back {$back}",
                "{$items} is due back {$back}. Reply or call us if you need to keep it longer.",
                null, null, null,
            ],
            'overdue' => [
                "{$items} was due back {$back}",
                "{$items} was due back {$back}. Please return it or call us to extend. Late days are charged.",
                null, null, null,
            ],
            'receipt' => [
                "Receipt for rental {$rental->number}",
                "Thanks, {$items} is checked in. Total ".Money::format($rental->total_cents).'. '.$this->depositLine($rental),
                null, null, null,
            ],
            'agreement_signed' => [
                "Your signed rental agreement, {$rental->number}",
                "Here’s your signed agreement for {$items}, {$out} to {$back}.",
                null, null,
                $rental->agreement?->pdf_path,
            ],
            default => ["Rental {$rental->number}", "Update on your rental {$rental->number}.", null, null, null],
        };

        // The signed PDF only goes by email.
        if ($kind === 'agreement_signed') {
            $channels = array_values(array_intersect($channels, ['mail']));
        }

        return new RentalMessage($rental, $kind, $subject, $text, $label, $url, $attachment, $channels);
    }

    private function depositLine(Rental $rental): string
    {
        return match ($rental->deposit_status->value) {
            'released' => 'Your deposit hold has been released.',
            'captured' => Money::format($rental->deposit_captured_cents).' of the deposit was applied to extra charges.',
            default => '',
        };
    }
}
