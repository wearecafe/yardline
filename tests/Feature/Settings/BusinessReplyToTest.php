<?php

namespace Tests\Feature\Settings;

use App\Notifications\YardMail;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessReplyToTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_to_email_is_saved_in_settings(): void
    {
        $this->signInToNewTenant();
        $tenant = CurrentTenant::get();
        $tenant->update(['settings' => ['other' => 'kept']]);

        $this->patch(route('business.update'), [
            'name' => 'Stone Yard',
            'timezone' => 'America/Toronto',
            'reply_to_email' => 'sales@stoneyard.test',
        ])->assertSessionHasNoErrors();

        $tenant->refresh();
        $this->assertSame('sales@stoneyard.test', $tenant->replyToEmail());
        $this->assertSame('kept', $tenant->settings['other']);
    }

    public function test_reply_to_email_must_be_valid(): void
    {
        $this->signInToNewTenant();

        $this->patch(route('business.update'), [
            'name' => 'Stone Yard',
            'timezone' => 'America/Toronto',
            'reply_to_email' => 'not-an-email',
        ])->assertSessionHasErrors('reply_to_email');
    }

    public function test_customer_mail_uses_yard_name_and_reply_to(): void
    {
        config(['mail.from.address' => 'mail@yardrunner.test']);
        $this->signInToNewTenant();
        $tenant = CurrentTenant::get();
        $tenant->update(['name' => 'Stone Yard', 'settings' => ['reply_to_email' => 'sales@stoneyard.test']]);

        $mail = YardMail::for($tenant);

        $this->assertSame(['mail@yardrunner.test', 'Stone Yard'], $mail->from);
        $this->assertSame([['sales@stoneyard.test', 'Stone Yard']], $mail->replyTo);
    }

    public function test_customer_mail_has_no_reply_to_when_unset(): void
    {
        $this->signInToNewTenant();

        $this->assertSame([], YardMail::for(CurrentTenant::get())->replyTo);
    }
}
