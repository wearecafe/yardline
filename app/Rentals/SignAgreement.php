<?php

namespace App\Rentals;

use App\Models\CustomerDocument;
use App\Models\Rental;
use App\Models\RentalAgreement;
use App\Rentals\Notifications\RentalNotifier;
use App\Support\Files;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Records a signature: the drawn signature image, printed name, training
 * acknowledgement, ID on file (at the counter), time, IP and device. Then
 * stores the signed PDF and emails it to the customer.
 */
class SignAgreement
{
    public function __construct(
        private readonly PrepareAgreement $prepare,
        private readonly AgreementPdf $pdf,
        private readonly RentalNotifier $notifier,
    ) {}

    public function handle(
        Rental $rental,
        string $signerName,
        string $signatureDataUrl,
        bool $acknowledgedTraining,
        string $via,
        Request $request,
        ?CustomerDocument $idDocument = null,
    ): RentalAgreement {
        if (! in_array($rental->status->value, ['quote', 'reserved', 'out'], true)) {
            RentalRules::fail('This rental can no longer be signed.');
        }

        if (! $acknowledgedTraining) {
            RentalRules::fail('The customer has to confirm they’ve read the terms and can operate the equipment safely.', 'acknowledged_training');
        }

        $agreement = $this->prepare->handle($rental);

        if ($agreement->signed_at !== null) {
            RentalRules::fail('This agreement is already signed.');
        }

        try {
            $signaturePath = Files::storeDataUrl($signatureDataUrl, 'signatures');
        } catch (InvalidArgumentException) {
            RentalRules::fail('Sign in the box first.', 'signature');
        }

        $agreement->fill([
            'signer_name' => $signerName,
            'signature_path' => $signaturePath,
            'acknowledged_training' => true,
            'id_document_id' => $idDocument?->id,
            'signed_via' => $via,
            'signed_ip' => $request->ip(),
            'signed_user_agent' => substr((string) $request->userAgent(), 0, 500),
            'signed_at' => now(),
        ])->save();

        $agreement->forceFill(['pdf_path' => Files::storeContents($this->pdf->render($agreement), 'agreements', 'pdf')])->save();

        $rental->setRelation('agreement', $agreement);
        $this->notifier->send($rental, 'agreement_signed');

        return $agreement;
    }
}
