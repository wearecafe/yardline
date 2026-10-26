<?php

namespace App\Http\Controllers\Rentals;

use App\Enums\DocumentKind;
use App\Http\Controllers\Controller;
use App\Http\Presenters\RentalPresenter;
use App\Models\CustomerDocument;
use App\Models\Rental;
use App\Rentals\Payments\RentalPayments;
use App\Rentals\PrepareAgreement;
use App\Rentals\RentalRules;
use App\Rentals\SignAgreement;
use App\Support\Files;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agreement and signing: on the counter tablet (staff, with an ID check)
 * or by the customer through the texted link (no sign-in).
 */
class AgreementController extends Controller
{
    public function show(Rental $rental, PrepareAgreement $prepare): Response
    {
        return $this->page($rental, $prepare, 'counter');
    }

    public function sign(Request $request, Rental $rental, SignAgreement $sign): RedirectResponse
    {
        $data = $this->validated($request, counter: true);

        $document = null;
        if (! empty($data['id_document_id'])) {
            $document = CustomerDocument::query()->where('customer_id', $rental->customer_id)->findOrFail((int) $data['id_document_id']);
        } elseif ($request->hasFile('licence_photo')) {
            $document = $this->storeLicence($request, $rental);
        }

        if ($document === null) {
            RentalRules::fail('Check the customer’s driver’s licence first.', 'licence_photo');
        }

        $sign->handle($rental, $data['signer_name'], $data['signature'], true, 'counter', $request, $document);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Agreement signed.']);

        return to_route('rentals.agreement', $rental);
    }

    /**
     * Pay step on the tablet: charge the card on file, or hand over a link.
     */
    public function pay(Request $request, Rental $rental, RentalPayments $payments): RedirectResponse
    {
        if ($rental->balanceCents() > 0) {
            $payments->chargeCardOnFile($rental, $request->user());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Paid. Ready for check-out.']);

        return to_route('rentals.show', $rental);
    }

    // ----------------------------------------------------------- public link

    public function publicShow(string $token, PrepareAgreement $prepare): Response
    {
        $rental = $this->byToken($token);

        return $this->page($rental, $prepare, 'link');
    }

    public function publicSign(Request $request, string $token, SignAgreement $sign): RedirectResponse
    {
        $rental = $this->byToken($token);
        $data = $this->validated($request, counter: false);

        $sign->handle($rental, $data['signer_name'], $data['signature'], true, 'link', $request);

        return to_route('rentals.public-sign', $token);
    }

    /**
     * Pay the balance from the signing link: off to the hosted payment page,
     * which also saves the card for the deposit hold at pickup.
     */
    public function publicPay(string $token, RentalPayments $payments): \Symfony\Component\HttpFoundation\Response
    {
        $rental = $this->byToken($token);

        if ($rental->balanceCents() <= 0) {
            return to_route('rentals.public-sign', $token);
        }

        $payment = $payments->paymentLink($rental, null, route('rentals.public-sign', $token));

        return Inertia::location((string) $payment->checkout_url);
    }

    private function page(Rental $rental, PrepareAgreement $prepare, string $via): Response
    {
        $agreement = $prepare->handle($rental);
        $rental->load('customer.documents');

        return Inertia::render('kiosk/agreement', [
            'via' => $via,
            'rental' => RentalPresenter::detail($rental),
            'agreement' => [
                'terms' => $agreement->terms,
                'summary' => $agreement->summary,
                'signed_at' => $agreement->signed_at?->toIso8601String(),
                'signer_name' => $agreement->signer_name,
                'pdf_url' => $via === 'counter' ? Files::url($agreement->pdf_path) : null,
            ],
            'yard' => $rental->tenant->name,
            'licence' => $via === 'counter' ? $this->licence($rental) : null,
            'submitUrl' => $via === 'counter'
                ? route('rentals.agreement.sign', $rental)
                : route('rentals.public-sign.store', $rental->signing_token),
            'payUrl' => $via === 'counter'
                ? route('rentals.agreement.pay', $rental)
                : route('rentals.public-sign.pay', $rental->signing_token),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function licence(Rental $rental): ?array
    {
        $document = $rental->customer?->documents
            ->where('kind', DocumentKind::DriversLicence)
            ->filter->isCurrent()
            ->sortByDesc('id')
            ->first();

        return $document ? [
            'id' => $document->id,
            'expires_on' => $document->expires_on?->toDateString(),
            'url' => Files::url($document->path),
        ] : null;
    }

    private function storeLicence(Request $request, Rental $rental): CustomerDocument
    {
        $request->validate(['licence_photo' => ['image', 'max:10240']]);

        if ($rental->customer_id === null) {
            RentalRules::fail('Add a customer to the rental first.');
        }

        return CustomerDocument::query()->create([
            'customer_id' => $rental->customer_id,
            'kind' => DocumentKind::DriversLicence,
            'path' => Files::storeUpload($request->file('licence_photo'), 'documents'),
            'verified_by' => $request->user()?->id,
            'verified_at' => now(),
        ]);
    }

    /**
     * @return array{signer_name: string, signature: string, id_document_id?: int|string|null}
     */
    private function validated(Request $request, bool $counter): array
    {
        /** @var array{signer_name: string, signature: string, id_document_id?: int|string|null} */
        return $request->validate([
            'signer_name' => ['required', 'string', 'max:255'],
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:2800000'],
            'acknowledged_training' => ['accepted'],
            'id_document_id' => $counter ? ['nullable', 'integer'] : ['prohibited'],
        ], [
            'acknowledged_training.accepted' => 'Confirm you’ve read the terms and can operate the equipment safely.',
            'signature.required' => 'Sign in the box first.',
        ]);
    }

    private function byToken(string $token): Rental
    {
        $rental = Rental::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('signing_token', $token)
            ->first();

        abort_if($rental === null || ! in_array($rental->status->value, ['quote', 'reserved', 'out'], true), 404);

        CurrentTenant::set($rental->tenant_id);

        return $rental;
    }
}
