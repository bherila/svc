<?php

namespace App\Support\Billing;

use DomainException;

/**
 * What is about to be sent with an invoice, once it has been checked.
 *
 * An immutable value rather than four parameters, because these four travel
 * together through the request, the delivery record, the mailable and the
 * audit line, and a positional list of two string arrays is exactly where a
 * recipient list ends up in the blind-copy field.
 *
 * Validation happens in the constructor, so a draft that exists is a draft that
 * can be sent. `DomainException` rather than a validation exception: these are
 * statements about the message, and the application renders the former as a 422
 * with its message intact.
 */
final class InvoiceEmailDraft
{
    /** What a subject line may hold, on the screen and through `invoices.send` alike. */
    public const int SUBJECT_MAX_LENGTH = 255;

    /**
     * Long enough for a real covering note and short enough that the body of
     * an invoice email cannot become a file transfer.
     */
    public const int MESSAGE_MAX_LENGTH = 5000;

    /**
     * @param  list<string>  $recipients  at least one, each a valid address
     * @param  list<string>  $bcc  blind copies, usually the sender themselves
     * @param  string  $subject  never blank; the invoice number alone is a fine subject
     * @param  string|null  $body  the covering note, or null for the template's own words
     *
     * @throws DomainException
     */
    public function __construct(
        public readonly array $recipients,
        public readonly array $bcc,
        public readonly string $subject,
        public readonly ?string $body,
    ) {
        if ($this->recipients === []) {
            throw new DomainException('At least one email recipient is required.');
        }

        foreach ([...$this->recipients, ...$this->bcc] as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException('Every invoice recipient must be a valid email address.');
            }
        }

        if (trim($this->subject) === '') {
            throw new DomainException('An invoice email needs a subject.');
        }
    }

    /**
     * Build a draft from whatever a caller supplied, over sensible defaults.
     *
     * Addresses are trimmed and de-duplicated, and an address already on the To
     * line is dropped from the blind copies - sending someone a copy of a
     * message they are already receiving is a duplicate they cannot explain.
     *
     * @param  list<string>  $recipients
     * @param  list<string>  $bcc
     *
     * @throws DomainException
     */
    public static function of(array $recipients, array $bcc, string $subject, ?string $body): self
    {
        $to = self::clean($recipients);
        $blind = array_values(array_filter(
            self::clean($bcc),
            static fn (string $address): bool => ! in_array($address, $to, true),
        ));

        $note = $body === null ? null : trim($body);

        return new self($to, $blind, trim($subject), $note === '' ? null : $note);
    }

    /**
     * The message an operator composed, sent from their own address.
     *
     * The one composition the invoice screen and `invoices.send` share (#454):
     * a subject left out is the invoice's default, a note left out is the
     * template's own words, and the only blind copy offered is the sender's
     * own. `bcc_self` is a flag rather than an address everywhere, because
     * taking an address would let a request blind-copy anyone - the one thing
     * about a blind copy that would be visible neither to the recipient nor on
     * the record.
     *
     * @param  list<string>  $recipients
     *
     * @throws DomainException
     */
    public static function composed(array $recipients, ?string $senderEmail, bool $bccSelf, ?string $subject, string $defaultSubject, ?string $message): self
    {
        $bcc = $bccSelf && $senderEmail !== null && $senderEmail !== '' ? [$senderEmail] : [];
        $chosen = $subject === null || trim($subject) === '' ? $defaultSubject : $subject;

        return self::of($recipients, $bcc, $chosen, $message);
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string>
     */
    private static function clean(array $addresses): array
    {
        return array_values(array_unique(array_filter(array_map('trim', $addresses))));
    }
}
