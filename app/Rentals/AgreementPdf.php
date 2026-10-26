<?php

namespace App\Rentals;

use App\Models\RentalAgreement;
use App\Support\Files;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The signed agreement as a PDF: terms, summary, signature image, and the
 * time, IP and device it was signed from.
 */
class AgreementPdf
{
    public function render(RentalAgreement $agreement): string
    {
        $agreement->loadMissing('rental.tenant');
        $signature = null;

        if ($agreement->signature_path !== null && Files::disk()->exists($agreement->signature_path)) {
            $signature = 'data:image/png;base64,'.base64_encode((string) Files::disk()->get($agreement->signature_path));
        }

        $html = view('pdf.rental-agreement', [
            'agreement' => $agreement,
            'rental' => $agreement->rental,
            'tenant' => $agreement->rental->tenant,
            'signature' => $signature,
        ])->render();

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
