<?php

namespace App\Http\Controllers;

use App\Enums\DocumentKind;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Support\Files;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Driver's licence and insurance certificates kept on a customer's file.
 */
class CustomerDocumentController extends Controller
{
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(DocumentKind::class)],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:12288'],
            'expires_on' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:40'],
        ]);

        $customer->documents()->create([
            'kind' => $data['kind'],
            'path' => Files::storeUpload($request->file('file'), 'documents'),
            'expires_on' => $data['expires_on'] ?? null,
            'reference' => $data['reference'] ?? null,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => DocumentKind::from($data['kind'])->label().' saved.']);

        return back();
    }

    public function destroy(Customer $customer, CustomerDocument $document): RedirectResponse
    {
        Files::disk()->delete((string) $document->path);
        $document->delete();

        return back();
    }
}
