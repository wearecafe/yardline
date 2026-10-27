<?php

namespace App\Email;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * A notification email drawn with YardRunner's layout in one of two
 * brands (see BrandedMail). Adds a title, a key facts box and a status
 * banner to Laravel's lines-and-a-button message.
 */
class BrandedMessage extends MailMessage
{
    public function __construct(EmailTheme $theme)
    {
        $this->view(
            ['html' => 'mail.branded', 'text' => 'mail.branded-text'],
            ['theme' => $theme, 'title' => null, 'facts' => [], 'notice' => null],
        );
    }

    /**
     * The heading at the top of the email. Defaults to the subject.
     */
    public function title(string $title): static
    {
        $this->viewData['title'] = $title;

        return $this;
    }

    /**
     * Label => value pairs shown in a shaded box (order number, amount due,
     * delivery window…). Empty values are left out.
     *
     * @param  array<string, string|null>  $facts
     */
    public function facts(array $facts): static
    {
        $this->viewData['facts'] = array_filter($facts, fn (?string $value) => $value !== null && $value !== '');

        return $this;
    }

    /**
     * A banner above the title: 'success', 'warning' or 'danger'.
     */
    public function notice(string $text, string $tone = 'success'): static
    {
        $this->viewData['notice'] = ['text' => $text, 'tone' => $tone];

        return $this;
    }

    public function emailTheme(): EmailTheme
    {
        return $this->viewData['theme'];
    }
}
