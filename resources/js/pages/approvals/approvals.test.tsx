import { router } from '@inertiajs/react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Approvals from '@/pages/approvals/index';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { sharedPageProps } from '@/test/shared-page-props';
import type { PortalApprovalGroup } from '@/types/portal';
import type { ApprovalBlock, ApprovalsProps } from '@/types/review';

const page = vi.hoisted(() => ({ errors: {} as Record<string, string> }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { visit: vi.fn(), post: vi.fn() },
    usePage: () => ({
        props: { ...sharedPageProps(), errors: page.errors },
    }),
}));

const LONG = 'Unbroken'.repeat(12);

function group(vendor: string): PortalApprovalGroup {
    return {
        key: '2026-10',
        vendor,
        period: '2026-10',
        period_label: 'October 2026',
        hours: {
            minutes: 90,
            pending_count: 1,
            entries: [
                {
                    id: `time-${vendor}`,
                    worked_on: '2026-10-14',
                    minutes: 90,
                    description: `Work for ${vendor}`,
                    project: 'Synthetic Project',
                    state: 'pending',
                    dispute_note: null,
                    invoice: null,
                },
            ],
        },
        expenses: { pending_count: 0, amount: null, currency: null, items: [] },
    };
}

function block(
    source: 'portal' | 'grant',
    key: string,
    provider: string,
): ApprovalBlock {
    return {
        key,
        source,
        provider,
        client: 'Synthetic Vet',
        pending: 1,
        to_approve: {
            approve_href: `/approvals/${source === 'grant' ? 'grant' : 'company'}/${key}/approve`,
            dispute_href: `/approvals/${source === 'grant' ? 'grant' : 'company'}/${key}/dispute`,
            groups: [group(provider)],
            truncated: false,
        },
    };
}

function props(overrides: Partial<ApprovalsProps> = {}): ApprovalsProps {
    return {
        blocks: [
            block('portal', 'company-1', 'Herila'),
            block('grant', 'grant-1', 'Other Eng'),
        ],
        approval_result: null,
        workspaces_url: '/workspaces',
        ...overrides,
    };
}

describe('cross-workspace to approve', () => {
    beforeEach(() => {
        page.errors = {};
        vi.mocked(router.post).mockReset();
    });

    it('draws one card for each provider, with the provider as its title', () => {
        render(<Approvals {...props()} />);

        expect(
            screen.getByRole('heading', { name: 'To approve', level: 1 }),
        ).toBeInTheDocument();
        expect(screen.getByText('Herila')).toBeInTheDocument();
        expect(screen.getByText('Other Eng')).toBeInTheDocument();
        expect(screen.getByText('Billed to Synthetic Vet')).toBeInTheDocument();
        expect(
            screen.getByText('Review access for Synthetic Vet'),
        ).toBeInTheDocument();
    });

    it('approves a month in the provider that was clicked, at the url the server sent', async () => {
        render(<Approvals {...props()} />);

        await userEvent.click(
            screen.getByRole('button', {
                name: 'Approve October 2026 from Other Eng',
            }),
        );

        expect(router.post).toHaveBeenCalledWith(
            '/approvals/grant/grant-1/approve',
            { period: '2026-10' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('disputes a row through the same card', async () => {
        render(<Approvals {...props()} />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Dispute Work for Other Eng' }),
        );
        await userEvent.type(
            screen.getByLabelText('Note for your provider (optional)'),
            'Which project?',
        );
        await userEvent.click(
            screen.getByRole('button', { name: 'Send dispute' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            '/approvals/grant/grant-1/dispute',
            { kind: 'time', id: 'time-Other Eng', note: 'Which project?' },
            expect.anything(),
        );
    });

    it('shows a decision only in the card it was made in', () => {
        render(
            <Approvals
                {...props({
                    approval_result: {
                        kind: 'approved',
                        time: 3,
                        expenses: 0,
                        skipped_own: 0,
                        key: 'grant-1',
                    },
                })}
            />,
        );

        const statuses = screen.getAllByRole('status');

        expect(statuses).toHaveLength(1);
        expect(statuses[0].textContent).toBe('Approved 3 entries of time.');
        expect(
            within(statuses[0].closest('[data-slot="to-approve"]')!).getByText(
                'Other Eng',
            ),
        ).toBeInTheDocument();
    });

    it('shows a refusal only in the card it belongs to', () => {
        page.errors = {
            approval_company_1: 'You cannot approve your own time.',
        };
        render(
            <Approvals
                {...props({
                    blocks: [
                        block('portal', 'company_1', 'Herila'),
                        block('grant', 'grant-1', 'Other Eng'),
                    ],
                })}
            />,
        );

        const alerts = screen.getAllByRole('alert');

        expect(alerts).toHaveLength(1);
        expect(alerts[0].textContent).toBe('You cannot approve your own time.');
        expect(
            within(alerts[0].closest('[data-slot="to-approve"]')!).getByText(
                'Herila',
            ),
        ).toBeInTheDocument();
    });

    it('says plainly when no provider asks for anything', () => {
        render(<Approvals {...props({ blocks: [] })} />);

        expect(
            screen.getByText('No provider has asked you to approve anything.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Back to your workspaces' }),
        ).toHaveAttribute('href', '/workspaces');
    });

    it('speaks to a reviewer, not a billed client, in an empty card', () => {
        const empty = block('grant', 'grant-1', 'Other Eng');
        empty.to_approve.groups = [];
        render(<Approvals {...props({ blocks: [empty] })} />);

        expect(
            screen.getByText(/this provider has ready to bill/),
        ).toBeInTheDocument();
    });

    it('holds with unbroken names and no overflow risk', () => {
        const hostile = props();
        hostile.blocks = hostile.blocks.map((entry) => ({
            ...entry,
            provider: LONG,
            client: LONG,
            to_approve: {
                ...entry.to_approve,
                groups: [group(LONG)],
            },
        }));

        const { container } = render(<Approvals {...hostile} />);

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
