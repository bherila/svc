import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ClientIndex from '@/pages/clients/index';
import type { CompanyRow, RetainerUsage } from '@/types/clients';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

function retainer(overrides: Partial<RetainerUsage> = {}): RetainerUsage {
    return {
        agreement: 'Synthetic Retainer',
        period_start: '2026-08-01',
        period_end: '2026-08-31',
        capacity_minutes: 600,
        used_minutes: 120,
        remaining_minutes: 480,
        over_minutes: 0,
        ...overrides,
    };
}

function company(overrides: Partial<CompanyRow> = {}): CompanyRow {
    return {
        id: 'company-1',
        name: 'Synthetic Client',
        billing_email: 'billing@synthetic.test',
        is_active: true,
        project_count: 2,
        draft_invoice_count: 1,
        open_invoice_count: 3,
        retainer: retainer(),
        ...overrides,
    };
}

function props(companies: CompanyRow[] = [company()]) {
    return {
        workspace: { id: 'workspace-1', name: 'Synthetic Workspace' },
        companies,
    };
}

function rowFor(name: string): HTMLElement {
    const cell = screen.getByText(name).closest('tr');
    expect(cell).not.toBeNull();

    return cell as HTMLElement;
}

describe('client list', () => {
    it('reports each company’s project and invoice counts', () => {
        render(<ClientIndex {...props()} />);

        const row = rowFor('Synthetic Client');

        expect(within(row).getByText('2')).toBeInTheDocument();
        expect(within(row).getByText('1')).toBeInTheDocument();
        expect(within(row).getByText('3')).toBeInTheDocument();
    });

    it('offers the appearance selector on the workspace client list', () => {
        render(<ClientIndex {...props()} />);

        expect(
            screen.getByRole('combobox', { name: 'Appearance' }),
        ).toBeInTheDocument();
    });

    // The marker that opts this screen into the legacy slate bridge. Pinned
    // because the bridge is opt-in: losing the attribute is silent, and the
    // screen would simply stop responding to a dark root.
    it('opts into the operator appearance bridge', () => {
        render(<ClientIndex {...props()} />);

        expect(screen.getByRole('main')).toHaveAttribute(
            'data-appearance-bridge',
        );
    });

    it('links each company to its detail screen inside this workspace', () => {
        render(<ClientIndex {...props()} />);

        // The workspace segment is the point: a link keyed on the company
        // alone would open the company through whichever workspace the last
        // page happened to be about.
        expect(
            screen.getByRole('link', { name: 'Synthetic Client' }),
        ).toHaveAttribute('href', '/workspaces/workspace-1/clients/company-1');
    });

    it('reads this period’s retainer as used against capacity', () => {
        render(<ClientIndex {...props()} />);

        expect(screen.getByText('2:00 / 10:00')).toBeInTheDocument();
        expect(
            screen.getByText(/8:00 left · 2026-08-01 to 2026-08-31/),
        ).toBeInTheDocument();
    });

    it('says an over-run is over rather than capping it at full', () => {
        render(
            <ClientIndex
                {...props([
                    company({
                        retainer: retainer({
                            used_minutes: 780,
                            remaining_minutes: 0,
                            over_minutes: 180,
                        }),
                    }),
                ])}
            />,
        );

        expect(screen.getByText('13:00 / 10:00')).toBeInTheDocument();
        expect(screen.getByText(/3:00 over/)).toBeInTheDocument();
        expect(screen.queryByText(/left/)).not.toBeInTheDocument();
    });

    it('says so plainly when a company has no agreement in force', () => {
        render(<ClientIndex {...props([company({ retainer: null })])} />);

        expect(screen.getByText('No active retainer')).toBeInTheDocument();
        expect(screen.queryByText(/left/)).not.toBeInTheDocument();
    });

    it('marks an inactive company', () => {
        render(
            <ClientIndex
                {...props([
                    company({ is_active: true }),
                    company({
                        id: 'company-2',
                        name: 'Dormant Client',
                        is_active: false,
                    }),
                ])}
            />,
        );

        expect(
            within(rowFor('Dormant Client')).getByText('Inactive'),
        ).toBeInTheDocument();
        expect(
            within(rowFor('Synthetic Client')).queryByText('Inactive'),
        ).not.toBeInTheDocument();
    });

    it('renders an empty workspace without a headerless table', () => {
        render(<ClientIndex {...props([])} />);

        expect(
            screen.getByText('No client companies in this workspace yet.'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });
});
