import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    backlogTrend,
    DeferredBacklogCard,
} from '@/components/agreements/deferred-backlog';
import type { DeferredBacklog } from '@/components/agreements/deferred-backlog';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

function backlog(overrides: Partial<DeferredBacklog> = {}): DeferredBacklog {
    return {
        through: '2026-05-20',
        hours: 42,
        entry_count: 17,
        oldest_worked_on: '2025-09-03',
        agreement_rate_hours: 40,
        flat_hourly_hours: 2,
        carried_hours: 0,
        amount_at_hourly_rate: { currency: 'USD', amount: 610000 },
        issue: null,
        issue_message: null,
        issue_detail: null,
        net_hours_per_month: 6.5,
        trend: [
            {
                period: '2026-03',
                hours_added: 9,
                hours_applied: 2,
                hours_billed: 0,
                hours_bought_down: 0,
            },
            {
                period: '2026-04',
                hours_added: 8,
                hours_applied: 1,
                hours_billed: 0,
                hours_bought_down: 0,
            },
            {
                period: '2026-05',
                hours_added: 6,
                hours_applied: 0.5,
                hours_billed: 0,
                hours_bought_down: 0,
            },
        ],
        ...overrides,
    };
}

describe('deferred backlog', () => {
    it('states the shelf, its price and its trend in one line', () => {
        render(<DeferredBacklogCard backlog={backlog()} />);

        const line = screen.getByTestId('deferred-backlog-line');
        expect(line.textContent).toContain('42.00 h');
        expect(line.textContent).toContain('$6,100.00');
        expect(line.textContent).toContain('if billed on termination');
        expect(
            screen.getByText(/flat-hourly work at its subcontractor rate/),
        ).toBeTruthy();
        expect(line.textContent).toContain(
            'growing 6.50 h/month over the last 3 months',
        );
        expect(
            screen.getByText(/17 entries, the oldest worked on/),
        ).toBeTruthy();
    });

    it('describes a shrinking, steady or single-month trend', () => {
        expect(backlogTrend(backlog({ net_hours_per_month: -1.25 }))).toBe(
            'shrinking 1.25 h/month over the last 3 months',
        );
        expect(backlogTrend(backlog({ net_hours_per_month: 0 }))).toBe(
            'steady over the last 3 months',
        );
        expect(
            backlogTrend(
                backlog({
                    trend: [
                        {
                            period: '2026-05',
                            hours_added: 1,
                            hours_applied: 0,
                            hours_billed: 0,
                            hours_bought_down: 0,
                        },
                    ],
                    net_hours_per_month: 1,
                }),
            ),
        ).toBe('growing 1.00 h/month this month');
        expect(
            backlogTrend(backlog({ trend: [], net_hours_per_month: null })),
        ).toBeNull();
    });

    it('says when nothing is waiting', () => {
        render(
            <DeferredBacklogCard
                backlog={backlog({
                    hours: 0,
                    entry_count: 0,
                    oldest_worked_on: null,
                    amount_at_hourly_rate: { currency: 'USD', amount: 0 },
                    net_hours_per_month: 0,
                })}
            />,
        );

        expect(
            screen.getByText(/No deferred work is waiting/).textContent,
        ).toContain('steady over the last 3 months');
        expect(screen.queryByTestId('deferred-backlog-line')).toBeNull();
    });

    it('keeps the hours and says why when the amount is unpriced', () => {
        render(
            <DeferredBacklogCard
                backlog={backlog({
                    amount_at_hourly_rate: null,
                    agreement_rate_hours: null,
                    flat_hourly_hours: null,
                    issue: 'unpriced',
                    issue_message:
                        'The deferred hours cannot be priced at the hourly rate as they stand.',
                    issue_detail:
                        'Agreement 00000000-0000-4000-8000-000000000366 states no hourly rate.',
                })}
            />,
        );

        const line = screen.getByTestId('deferred-backlog-line');
        expect(line.textContent).toContain('42.00 h');
        expect(line.textContent).not.toContain('if billed on termination');
        expect(screen.getByText(/states no hourly rate/)).toBeTruthy();
    });

    it('withholds every figure when termination would refuse', () => {
        render(
            <DeferredBacklogCard
                backlog={backlog({
                    hours: null,
                    entry_count: null,
                    oldest_worked_on: null,
                    agreement_rate_hours: null,
                    flat_hourly_hours: null,
                    amount_at_hourly_rate: null,
                    issue: 'project_chain_mismatch',
                    issue_message:
                        'A deferred time entry points to a project outside this client, so the backlog cannot be stated. Correct the entry first.',
                    net_hours_per_month: null,
                    trend: [],
                })}
            />,
        );

        expect(screen.getByText('Withheld.')).toBeTruthy();
        expect(screen.getByText(/project outside this client/)).toBeTruthy();
    });

    it('stays inside the window with an unbreakable refusal', () => {
        const { container } = render(
            <DeferredBacklogCard
                backlog={backlog({
                    hours: 123456.75,
                    amount_at_hourly_rate: {
                        currency: 'USD',
                        amount: 99999999999,
                    },
                    issue: 'unpriced',
                    issue_message:
                        'The deferred hours cannot be priced at the hourly rate as they stand.',
                    issue_detail: `Subcontractor time is costed in EUR but invoice ${'SYNTHETICFIXTURE'.repeat(12)} is in USD.`,
                })}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
