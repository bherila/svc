import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import {
    AgreementTermsForm,
    AgreementTitle,
} from '@/components/agreements/agreement-editor';
import type { AgreementTerms } from '@/types/agreement';

const api = vi.hoisted(() => ({
    request: vi.fn(),
    refresh: vi.fn().mockResolvedValue(undefined),
}));
vi.mock('@/lib/api', () => ({ apiRequest: api.request }));
vi.mock('@/lib/refresh-page-props', () => ({ refreshPageProps: api.refresh }));
const agreement: AgreementTerms = {
    id: 'synthetic-agreement',
    version: 'a'.repeat(64),
    title: 'Synthetic agreement',
    status: 'draft',
    currency: 'USD',
    billing_cadence: 'monthly',
    effective_billing_cadence: 'monthly',
    effective_first_cycle_proration: null,
    is_recurring: true,
    starts_on: '2026-10-01',
    ends_on: null,
    signed_at: null,
    retainer_minutes_per_period: null,
    retainer_minutes_per_month: null,
    retainer_amount_per_period: null,
    hourly_rate_amount: 12500,
    rollover_months: null,
    project: null,
    rollover_policy: null,
    catch_up_threshold_minutes: null,
    first_cycle_proration: null,
    bill_overage_interim: null,
    activated_at: null,
    terminated_at: null,
    signer_name: null,
    signer_title: null,
    retainer_minutes: null,
    retainer_amount: null,
    period_retainer_minutes: null,
    period_retainer_amount: null,
    agreement_text: null,
    is_visible_to_client: false,
};
beforeEach(() => vi.clearAllMocks());

it('renames with only the title and rendered version, and refreshes a stale refusal', async () => {
    api.request.mockResolvedValue({
        ok: false,
        status: 409,
        message: 'Synthetic terms changed.',
    });
    render(
        <AgreementTitle
            agreement={agreement}
            updateHref="/api/synthetic/agreement"
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Rename this agreement' }),
    );
    fireEvent.change(screen.getByLabelText('Agreement name'), {
        target: { value: 'Synthetic corrected title' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Synthetic terms changed.',
    );
    expect(api.request).toHaveBeenCalledWith(
        'PATCH',
        '/api/synthetic/agreement',
        {
            title: 'Synthetic corrected title',
            expected_version: agreement.version,
        },
    );
    await waitFor(() => expect(api.refresh).toHaveBeenCalledOnce());
});

it('preserves blank terms as null and converts a stated price to minor units', async () => {
    api.request.mockResolvedValue({
        ok: false,
        status: 422,
        message: 'Synthetic validation refusal.',
    });
    const done = vi.fn();
    render(
        <AgreementTermsForm
            agreement={agreement}
            updateHref="/api/synthetic/agreement"
            onDone={done}
        />,
    );
    fireEvent.change(screen.getByLabelText('Hourly rate'), {
        target: { value: '125.50' },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Save terms' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Synthetic validation refusal.',
    );
    expect(api.request).toHaveBeenCalledWith(
        'PATCH',
        '/api/synthetic/agreement',
        expect.objectContaining({
            expected_version: agreement.version,
            hourly_rate_amount: 12550,
            retainer_minutes: null,
            retainer_amount: null,
            rollover_months: null,
            first_cycle_proration: null,
            bill_overage_interim: null,
            ends_on: null,
        }),
    );
    expect(done).not.toHaveBeenCalled();
});
