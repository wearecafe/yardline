<?php

namespace App\Email;

/**
 * Which of the yard's mailboxes a customer email comes from. Each falls
 * back to the main mailbox when the yard hasn't named one.
 */
enum MailGroup: string
{
    case Main = 'main';
    case Accounts = 'accounts';
    case Orders = 'orders';
    case Delivery = 'delivery';
    case Rentals = 'rentals';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Main',
            self::Accounts => 'Accounts',
            self::Orders => 'Orders',
            self::Delivery => 'Delivery',
            self::Rentals => 'Rentals',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Main => 'Portal sign-in links, and anything without its own mailbox',
            self::Accounts => 'Invoices, statements, payment reminders, credit notes',
            self::Orders => 'Quotes, order confirmations, receipts, purchase orders',
            self::Delivery => 'Delivery scheduled, on the way, delivered',
            self::Rentals => 'Bookings, agreements, reminders, overdue notices',
        };
    }

    /**
     * The mailbox name suggested when a domain is first connected.
     */
    public function suggested(): string
    {
        return match ($this) {
            self::Main => 'hello',
            default => $this->value,
        };
    }
}
