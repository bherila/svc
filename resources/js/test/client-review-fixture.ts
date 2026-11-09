import type { ApiSchema } from '@/lib/api';

/** A row the client has not decided on yet, with no dispute behind it. */
export function clientReviewFixture(
    overrides: Partial<ApiSchema<'ClientReview'>> = {},
): ApiSchema<'ClientReview'> {
    return {
        state: 'undecided',
        state_label: 'Waiting for the client',
        held: false,
        dispute_note: null,
        disputed_at: null,
        settlement: null,
        settlement_label: null,
        reply: null,
        settled_at: null,
        ...overrides,
    };
}
