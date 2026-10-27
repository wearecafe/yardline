<?php

namespace Tests\Feature\Email;

use App\Email\BrandedMail;
use App\Email\BrandSettings;
use App\Email\MailGroup;
use App\Models\SendingDomain;
use App\Notifications\UserInviteMessage;
use App\Notifications\YardMail;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer emails carry the yard's brand; emails to the yard's own users
 * carry YardRunner's.
 */
class BrandedEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_emails_carry_the_yards_brand(): void
    {
        $this->signInToNewTenant();
        $tenant = CurrentTenant::get();
        $tenant->update(['name' => 'Stoneworks Supply']);
        BrandSettings::save($tenant, ['primary' => '#1F5C99', 'tax_numbers' => 'HST 81234 5678 RT0001', 'footer_note' => 'Family owned since 1987']);

        $html = (string) YardMail::for($tenant)
            ->subject('Order 10482 confirmed')
            ->title('Your order is confirmed')
            ->facts(['Order' => '10482', 'PO number' => null])
            ->line('We’ll deliver on Thursday.')
            ->action('View order', 'https://example.test/orders/10482')
            ->render();

        $this->assertStringContainsString('Stoneworks Supply', $html);
        $this->assertStringContainsString('#1F5C99', $html);
        $this->assertStringContainsString('Your order is confirmed', $html);
        $this->assertStringContainsString('10482', $html);
        $this->assertStringNotContainsString('PO number', $html, 'empty facts are left out');
        $this->assertStringContainsString('Stoneworks Supply HST 81234 5678 RT0001', $html);
        $this->assertStringContainsString('Family owned since 1987', $html);
        $this->assertStringContainsString('Sent with', $html);
    }

    public function test_the_sent_with_yardrunner_line_can_be_switched_off(): void
    {
        $this->signInToNewTenant();
        $tenant = CurrentTenant::get();
        BrandSettings::save($tenant, ['sent_with_yardrunner' => false]);

        $this->assertStringNotContainsString('Sent with', (string) YardMail::for($tenant)->line('Hi')->render());
    }

    public function test_custom_colours_only_count_with_the_colour_system_on(): void
    {
        $this->signInToNewTenant();
        $tenant = CurrentTenant::get();
        BrandSettings::save($tenant, ['primary' => '#1F5C99', 'overrides' => ['primary_subtle' => '#ABCDEF']]);

        $this->assertStringNotContainsString('#ABCDEF', (string) YardMail::for($tenant)->facts(['Order' => '1'])->render());

        BrandSettings::save($tenant->refresh(), ['colour_system' => true]);

        $this->assertStringContainsString('#ABCDEF', (string) YardMail::for($tenant)->facts(['Order' => '1'])->render());
    }

    public function test_user_emails_carry_yardrunners_brand(): void
    {
        $this->signInToNewTenant();
        $tenant = CurrentTenant::get();
        BrandSettings::save($tenant, ['primary' => '#1F5C99']);

        $mail = (new UserInviteMessage('https://example.test/invite', $tenant->name, 'Sales'))->toMail(new \stdClass);
        $html = (string) $mail->render();

        $this->assertSame([config('mail.from.address'), 'YardRunner'], $mail->from);
        $this->assertStringContainsString('YardRunner', $html);
        $this->assertStringContainsString('#F26A1B', $html);
        $this->assertStringNotContainsString('#1F5C99', $html);
        $this->assertStringNotContainsString('Sent with', $html);
    }

    public function test_customer_emails_use_yardrunners_address_until_the_yard_sends_from_its_own(): void
    {
        config(['mail.from.address' => 'mail@yardline.test', 'yardline.email.tenant_from_domain' => '']);
        $this->signInToNewTenant();
        $tenant = CurrentTenant::get();

        $this->assertSame(['mail@yardline.test', $tenant->name], YardMail::for($tenant)->from);

        config(['yardline.email.tenant_from_domain' => 'mail.yardrunner.test']);
        $this->assertSame(["{$tenant->slug}@mail.yardrunner.test", $tenant->name], YardMail::for($tenant)->from);

        $domain = SendingDomain::query()->create([
            'domain' => 'stoneworks.test',
            'status' => SendingDomain::VERIFIED,
            'mailboxes' => ['main' => 'hello', 'accounts' => 'accounts'],
            'sending_enabled' => true,
        ]);

        $this->assertSame(['accounts@stoneworks.test', $tenant->name], YardMail::for($tenant, MailGroup::Accounts)->from);
        $this->assertSame(['hello@stoneworks.test', $tenant->name], YardMail::for($tenant, MailGroup::Delivery)->from, 'no delivery mailbox: main');

        // A domain that stops passing falls back without anyone touching it.
        $domain->update(['status' => SendingDomain::FAILING]);
        $this->assertSame(["{$tenant->slug}@mail.yardrunner.test", $tenant->name], YardMail::for($tenant, MailGroup::Accounts)->from);
    }

    public function test_the_text_version_is_plain(): void
    {
        $this->signInToNewTenant();

        $mail = BrandedMail::customer(CurrentTenant::get())
            ->title('Your statement')
            ->facts(['Balance due' => '$1,200.00'])
            ->line('Your statement is attached.');

        $text = (string) view('mail.branded-text', $mail->data())->render();

        $this->assertStringContainsString('Balance due: $1,200.00', $text);
        $this->assertStringNotContainsString('<', $text);
    }
}
