import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    EngagementAction,
    ProposalDraftForm,
    ScheduleDraftForm,
} from '@/components/agreements/engagement-actions';

const api = vi.hoisted(() => ({
    request: vi.fn(),
    refresh: vi.fn().mockResolvedValue(undefined),
    key: vi.fn().mockReturnValue('synthetic-receipt'),
}));
vi.mock('@/lib/api', () => ({
    apiRequest: api.request,
    newIdempotencyKey: api.key,
}));
vi.mock('@/lib/refresh-page-props', () => ({ refreshPageProps: api.refresh }));
const version = 'a'.repeat(64);
beforeEach(() => {
    vi.clearAllMocks();
});

describe('engagement API controls', () => {
    it('requires confirmation and sends the exact rendered version to the finished URL', async () => {
        api.request.mockResolvedValue({
            ok: false,
            status: 422,
            message: 'Synthetic overlap refusal.',
        });
        render(
            <EngagementAction
                href="/api/synthetic/activate"
                version={version}
                label="Activate"
                description="Synthetic current terms."
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Activate' }));
        expect(api.request).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Activate' }).at(-1)!,
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Synthetic overlap refusal.',
        );
        expect(api.request).toHaveBeenCalledWith(
            'POST',
            '/api/synthetic/activate',
            { expected_version: version, confirm: true },
            'synthetic-receipt',
        );
    });

    it('retries an uncertain outcome with its original receipt and payload', async () => {
        api.request
            .mockResolvedValueOnce({
                ok: false,
                status: 0,
                message: 'Synthetic connection loss.',
            })
            .mockResolvedValueOnce({ ok: true, data: {} });
        render(
            <EngagementAction
                href="/api/synthetic/send"
                version={version}
                label="Send"
                description="Synthetic client review."
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Send' }).at(-1)!,
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Retry confirmed action',
            }),
        );
        await waitFor(() => expect(api.refresh).toHaveBeenCalledOnce());
        expect(api.request.mock.calls[0]).toEqual(api.request.mock.calls[1]);
        expect(api.key).toHaveBeenCalledOnce();
    });

    it('locks draft inputs after a lost response and offers a receipt replay', async () => {
        api.request.mockResolvedValue({
            ok: false,
            status: 0,
            message: 'Synthetic connection loss.',
        });
        render(
            <ProposalDraftForm
                href="/api/synthetic/proposals"
                companyId="synthetic-company"
                version={version}
            />,
        );
        fireEvent.change(screen.getByLabelText('Proposal title'), {
            target: { value: 'Synthetic draft' },
        });
        fireEvent.change(screen.getByLabelText('Proposal line description'), {
            target: { value: 'Synthetic line' },
        });
        fireEvent.change(screen.getByLabelText('Proposal price'), {
            target: { value: '12.50' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Draft proposal' }));
        expect(
            await screen.findByRole('button', { name: 'Retry draft proposal' }),
        ).toBeEnabled();
        expect(screen.getByLabelText('Proposal title')).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry draft proposal' }),
        );
        await waitFor(() => expect(api.request).toHaveBeenCalledTimes(2));
        expect(api.request.mock.calls[0]).toEqual(api.request.mock.calls[1]);
        expect(api.request.mock.calls[0][2]).toMatchObject({
            company_id: 'synthetic-company',
            expected_version: version,
            items: [{ unit_amount: 1250 }],
        });
    });

    it('refreshes stale schedule terms and sends the agreement version', async () => {
        api.request.mockResolvedValue({
            ok: false,
            status: 409,
            message: 'Synthetic changed terms.',
        });
        render(
            <ScheduleDraftForm
                href="/api/synthetic/schedules"
                companyId="synthetic-company"
                timezone="UTC"
                agreement={{
                    id: 'synthetic-agreement',
                    version,
                    billing_cadence: 'quarterly',
                }}
            />,
        );
        fireEvent.change(screen.getByLabelText('Recurring line description'), {
            target: { value: 'Synthetic recurring line' },
        });
        fireEvent.change(screen.getByLabelText('Recurring amount'), {
            target: { value: '25.00' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add schedule' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Synthetic changed terms.',
        );
        await waitFor(() => expect(api.refresh).toHaveBeenCalledOnce());
        expect(api.request.mock.calls[0][2]).toMatchObject({
            company_id: 'synthetic-company',
            client_agreement: 'synthetic-agreement',
            expected_version: version,
            cadence: 'quarterly',
            line_template: [{ unit_amount: 2500 }],
        });
    });
});
