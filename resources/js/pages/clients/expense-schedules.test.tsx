import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiRequest } from '@/lib/api';
import ExpenseSchedules from './expense-schedules';

vi.mock('@/lib/api', () => ({
    apiRequest: vi.fn(),
    newIdempotencyKey: () => `synthetic-${Math.random()}`,
}));
beforeEach(() => {
    vi.mocked(router.reload)
        .mockReset()
        .mockImplementation((options) => {
            options?.onSuccess?.({} as never);
            options?.onFinish?.({} as never);
        });
    vi.mocked(apiRequest)
        .mockReset()
        .mockResolvedValue({ ok: true, status: 200, data: {} });
});
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { reload: vi.fn(), on: vi.fn(() => vi.fn()) },
    useForm: function useForm(initial: Record<string, unknown>) {
        const [data, setData] = useState(initial);

        return {
            data,
            errors: {},
            processing: false,
            setData: (key: string, value: unknown) =>
                setData((current) => ({ ...current, [key]: value })),
        };
    },
}));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

describe('recurring expense amount editing', () => {
    it('creates a schedule using the real save button and string amount', async () => {
        render(
            <ExpenseSchedules
                company={{
                    id: 'synthetic-company',
                    name: 'Synthetic create client',
                    version: 'company-version',
                }}
                today="2028-03-31"
                currency="USD"
                urls={{ store: '/synthetic/store' }}
                pagination={{ next: null, previous: null }}
                projects={[]}
                cadences={[{ value: 'monthly', label: 'Monthly' }]}
                schedules={[]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'New schedule' }));
        fireEvent.change(screen.getByLabelText('Description'), {
            target: { value: 'Synthetic new cost' },
        });
        fireEvent.change(screen.getByLabelText('Amount in minor units'), {
            target: { value: '9007199254740993' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save schedule' }));
        await vi.waitFor(() =>
            expect(apiRequest).toHaveBeenCalledWith(
                'POST',
                '/synthetic/store',
                {
                    description: 'Synthetic new cost',
                    amount: '9007199254740993',
                    currency: 'USD',
                    project_id: null,
                    starts_on: '2028-03-31',
                    cadence: 'monthly',
                    company_id: 'synthetic-company',
                    expected_version: 'company-version',
                },
                expect.any(String),
            ),
        );
    });
    it('submits the exact large amount when only the description changes', async () => {
        render(
            <ExpenseSchedules
                company={{
                    id: 'synthetic-company',
                    name: 'Synthetic exact amount client',
                    version: 'company-version',
                }}
                today="2028-03-31"
                currency="USD"
                urls={{ store: '/synthetic/store' }}
                pagination={{ next: null, previous: null }}
                projects={[]}
                cadences={[{ value: 'monthly', label: 'Monthly' }]}
                schedules={[
                    {
                        id: 'synthetic-schedule',
                        amount: 9007199254740992,
                        amount_exact: '9007199254740993',
                        version: 'schedule-version',
                        currency: 'USD',
                        description: 'Synthetic original',
                        project_id: '',
                        starts_on: '2028-01-01',
                        cadence: 'monthly',
                        active: true,
                        status_label: 'Active',
                        next_on: '2028-04-01',
                        pending: false,
                        update_url: '/synthetic/update',
                        generate_url: null,
                    },
                ]}
            />,
        );
        expect(screen.getByText(/90,071,992,547,409\.93/)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Edit schedule' }));
        const amount = screen.getByLabelText(
            'Amount in minor units',
        ) as HTMLInputElement;
        expect(amount.value).toBe('9007199254740993');
        fireEvent.change(screen.getByLabelText('Description'), {
            target: { value: 'Synthetic corrected description' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save schedule' }));
        await vi.waitFor(() =>
            expect(apiRequest).toHaveBeenCalledWith(
                'PATCH',
                '/synthetic/update',
                {
                    amount: '9007199254740993',
                    currency: 'USD',
                    description: 'Synthetic corrected description',
                    project_id: null,
                    active: true,
                    expected_version: 'schedule-version',
                },
                expect.any(String),
            ),
        );
    });
});

const scheduleFixture = {
    id: 'synthetic-schedule',
    amount: 9007199254740992,
    amount_exact: '9007199254740993',
    version: 'schedule-version',
    currency: 'USD',
    description: 'Synthetic original',
    project_id: '',
    starts_on: '2028-01-01',
    cadence: 'monthly',
    active: true,
    status_label: 'Active',
    next_on: '2028-04-01',
    pending: true,
    update_url: '/synthetic/update',
    generate_url: '/synthetic/generate' as string | null,
};
const schedulePage = {
    company: {
        id: 'synthetic-company',
        name: 'Synthetic client',
        version: 'company-version',
    },
    today: '2028-03-31',
    currency: 'USD',
    urls: { store: '/synthetic/store' },
    pagination: { next: null as string | null, previous: null },
    projects: [],
    cadences: [{ value: 'monthly', label: 'Monthly' }],
    schedules: [scheduleFixture],
};

it('keeps an editing snapshot tied to its reviewed URL, amount and version after refreshed props', async () => {
    vi.mocked(apiRequest).mockResolvedValue({
        ok: false,
        status: 409,
        message: 'Synthetic stale version.',
    });
    const { rerender } = render(<ExpenseSchedules {...schedulePage} />);
    fireEvent.click(screen.getByRole('button', { name: 'Edit schedule' }));
    fireEvent.change(screen.getByLabelText('Description'), {
        target: { value: 'Synthetic local correction' },
    });
    rerender(
        <ExpenseSchedules
            {...schedulePage}
            schedules={[
                {
                    ...scheduleFixture,
                    version: 'refreshed-version',
                    amount_exact: '9999999999999999',
                    description: 'Synthetic remote correction',
                    update_url: '/synthetic/new-update',
                },
            ]}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save schedule' }));
    await vi.waitFor(() => expect(apiRequest).toHaveBeenCalledOnce());
    expect(vi.mocked(apiRequest).mock.calls[0].slice(0, 3)).toEqual([
        'PATCH',
        '/synthetic/update',
        {
            description: 'Synthetic local correction',
            amount: '9007199254740993',
            currency: 'USD',
            project_id: null,
            active: true,
            expected_version: 'schedule-version',
        },
    ]);
    await vi.waitFor(() =>
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeEnabled(),
    );
});

it.each([0, 408, 503])(
    'retains an uncertain schedule creation after status %s and later refusal, then retries only a failed saved refresh',
    async (status) => {
        vi.mocked(apiRequest)
            .mockResolvedValueOnce({
                ok: false,
                status,
                message: 'Synthetic uncertain request.',
            })
            .mockResolvedValueOnce({
                ok: false,
                status: 409,
                message: 'Synthetic pending request.',
            })
            .mockResolvedValue({ ok: true, status: 201, data: {} });
        const { rerender } = render(<ExpenseSchedules {...schedulePage} />);
        fireEvent.click(screen.getByRole('button', { name: 'New schedule' }));
        fireEvent.change(screen.getByLabelText('Description'), {
            target: { value: 'Synthetic original creation' },
        });
        fireEvent.change(screen.getByLabelText('Amount in minor units'), {
            target: { value: '9007199254740993' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save schedule' }));
        await screen.findByRole('button', { name: 'Check original request' });
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'New schedule' }),
        ).toBeDisabled();
        expect(screen.getByLabelText('Description')).toBeDisabled();
        const original = vi.mocked(apiRequest).mock.calls[0];
        rerender(
            <ExpenseSchedules
                {...schedulePage}
                company={{
                    ...schedulePage.company,
                    version: 'new-company-version',
                }}
                urls={{ store: '/synthetic/new-store' }}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original request' }),
        );
        await vi.waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(2));
        expect(vi.mocked(apiRequest).mock.calls[1]).toEqual(original);
        expect(screen.getByLabelText('Description')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        await vi.waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Check original request' }),
            ).toBeEnabled(),
        );
        vi.mocked(router.reload).mockImplementationOnce((options) =>
            options?.onFinish?.({} as never),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original request' }),
        );
        await screen.findByRole('button', { name: 'Refresh saved result' });
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(vi.mocked(apiRequest).mock.calls[2]).toEqual(original);
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh saved result' }),
        );
        await vi.waitFor(() =>
            expect(
                screen.queryByLabelText('Description'),
            ).not.toBeInTheDocument(),
        );
        expect(apiRequest).toHaveBeenCalledTimes(3);
        expect(
            screen.getByRole('button', { name: 'New schedule' }),
        ).toBeEnabled();
    },
);

it('keeps the original generation batch and URL when a background update changes its version and removes its action', async () => {
    vi.mocked(apiRequest)
        .mockResolvedValueOnce({
            ok: false,
            status: 408,
            message: 'Synthetic uncertain batch.',
        })
        .mockResolvedValue({ ok: true, status: 200, data: {} });
    const { rerender } = render(<ExpenseSchedules {...schedulePage} />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Generate due drafts' }),
    );
    await screen.findByRole('button', { name: 'Check original generation' });
    const original = vi.mocked(apiRequest).mock.calls[0];
    rerender(
        <ExpenseSchedules
            {...schedulePage}
            schedules={[
                {
                    ...scheduleFixture,
                    version: 'next-batch-version',
                    generate_url: null,
                    next_on: '2028-06-01',
                },
            ]}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Check original generation' }),
    );
    await vi.waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(2));
    expect(vi.mocked(apiRequest).mock.calls[1]).toEqual(original);
    expect(original.slice(0, 3)).toEqual([
        'POST',
        '/synthetic/generate',
        { expected_version: 'schedule-version', confirm: true },
    ]);
    await vi.waitFor(() =>
        expect(
            screen.queryByRole('button', { name: 'Check original generation' }),
        ).not.toBeInTheDocument(),
    );
});

it('allows a definitive validation refusal to be corrected under a new creation key', async () => {
    vi.mocked(apiRequest)
        .mockResolvedValueOnce({
            ok: false,
            status: 422,
            message: 'Synthetic validation refusal.',
        })
        .mockResolvedValue({ ok: true, status: 201, data: {} });
    render(<ExpenseSchedules {...schedulePage} />);
    fireEvent.click(screen.getByRole('button', { name: 'New schedule' }));
    fireEvent.change(screen.getByLabelText('Description'), {
        target: { value: 'Synthetic first creation' },
    });
    fireEvent.change(screen.getByLabelText('Amount in minor units'), {
        target: { value: '1234' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save schedule' }));
    await screen.findByRole('alert');
    expect(screen.getByLabelText('Description')).toBeEnabled();
    fireEvent.change(screen.getByLabelText('Description'), {
        target: { value: 'Synthetic corrected creation' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save schedule' }));
    await vi.waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(2));
    const calls = vi.mocked(apiRequest).mock.calls;
    expect(calls[1][3]).not.toBe(calls[0][3]);
    expect(calls[1][2]).toEqual(
        expect.objectContaining({
            description: 'Synthetic corrected creation',
        }),
    );
});
