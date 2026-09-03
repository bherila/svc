/**
 * What the compose dialog needs before an invoice is sent, and what happened
 * to the ones already sent.
 *
 * Both are null or empty for anyone who cannot send: the addresses of a
 * client's people, and a log of who was mailed and when, are an operator's to
 * see. The server decides that, rather than the browser hiding a section it was
 * handed anyway.
 */
export type InvoiceEmailContext = {
    /** The address the client sees it come from. Not editable. */
    from: string;
    suggested_recipients: { email: string; label: string }[];
    default_subject: string;
    /** The sender's own address, named on the blind-copy control. */
    self: string;
};

/**
 * One attempt to email this invoice.
 *
 * Two statuses, deliberately not merged. `status` is ours and says only that
 * the message left here — pending, sent, or failed. `provider_status` is the
 * mail provider's and says what became of it: delivered, bounced, blocked,
 * marked as spam. Showing one word for both would let "sent" read as
 * "received", and an operator who believes that will chase a client who never
 * got the invoice.
 */
export type InvoiceDelivery = {
    id: string;
    status: string;
    recipients: string[];
    bcc: string[];
    subject: string;
    sent_at: string | null;
    failed_at: string | null;
    /** Our own sentence naming a failure class, never the mailer's own text. */
    error_summary: string | null;
    provider_status: string | null;
    provider_status_at: string | null;
};
