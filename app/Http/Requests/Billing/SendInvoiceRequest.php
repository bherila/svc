<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What the operator chose to send with this invoice.
 *
 * Everything is optional and everything has a default, because the button that
 * sends an invoice with no thought at all still has to work: recipients fall
 * back to the client's billing address, the subject to the invoice number, and
 * the note to the template's own words.
 *
 * `bcc_self` is a flag rather than an address. The only blind copy this screen
 * offers is the sender's own, and taking an address here would let a request
 * blind-copy anyone - which is the one thing about a blind copy that would not
 * be visible to the recipient or on the record.
 */
class SendInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recipients' => ['nullable', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['email', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            // Long enough for a real covering note and short enough that the
            // body of an invoice email cannot become a file transfer.
            'message' => ['nullable', 'string', 'max:5000'],
            'bcc_self' => ['sometimes', 'boolean'],
        ];
    }
}
