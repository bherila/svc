import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ExpenseSchedules from './expense-schedules';

const submitted = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: vi.fn() },
    useForm: function useForm(initial: Record<string, unknown>) {
        const [data, setData] = useState(initial);
        let transform = (value: Record<string, unknown>) => value;

        return {
            data,
            errors: {},
            processing: false,
            setData: (key: string, value: unknown) =>
                setData((current) => ({ ...current, [key]: value })),
            transform: (callback: typeof transform) => {
                transform = callback;
            },
            patch: (url: string) => submitted(url, transform(data)),
            post: (url: string) => submitted(url, transform(data)),
        };
    },
}));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

describe('recurring expense amount editing', () => {
    it('creates a schedule using the real save button and string amount', () => {
        render(
            <ExpenseSchedules
                company={{ name: 'Synthetic create client' }}
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
        expect(submitted).toHaveBeenCalledWith('/synthetic/store', {
            description: 'Synthetic new cost',
            amount: '9007199254740993',
            currency: 'USD',
            project_id: null,
            starts_on: '2028-03-31',
            cadence: 'monthly',
        });
    });
    it('submits the exact large amount when only the description changes', () => {
        render(
            <ExpenseSchedules
                company={{ name: 'Synthetic exact amount client' }}
                today="2028-03-31"
                currency="USD"
                urls={{ store: '/synthetic/store' }}
                pagination={{ next: null, previous: null }}
                projects={[]}
                cadences={[{ value: 'monthly', label: 'Monthly' }]}
                schedules={[
                    {
                        id: 'synthetic-schedule',
                        amount: '9007199254740993',
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
        expect(submitted).toHaveBeenCalledWith('/synthetic/update', {
            amount: '9007199254740993',
            currency: 'USD',
            description: 'Synthetic corrected description',
            project_id: null,
            active: true,
        });
    });
});
