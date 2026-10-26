<?php

namespace App\Accounting\QuickBooks;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * An error back from QuickBooks, with its own message ("Duplicate Name
 * Exists Error", "The account period has closed") for the sync log.
 */
class QuickBooksException extends RuntimeException
{
    public ?string $qboCode = null;

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('Fault.Error.0');
        $message = is_array($error)
            ? trim(($error['Message'] ?? 'QuickBooks error').(isset($error['Detail']) ? ': '.$error['Detail'] : ''))
            : 'QuickBooks returned '.$response->status().'.';

        $exception = new self($message, $response->status());
        $exception->qboCode = is_array($error) ? (string) ($error['code'] ?? '') : null;

        return $exception;
    }
}
