<?php

namespace App\Email\Domains;

use RuntimeException;

/**
 * Something went wrong talking to the email service. The message is for
 * logs and YardRunner staff; yards see YARD_MESSAGE.
 */
final class DomainProblem extends RuntimeException
{
    public const YARD_MESSAGE = 'We couldn’t set up that domain just now. Try again in a few minutes, or contact YardRunner support if it keeps happening.';
}
