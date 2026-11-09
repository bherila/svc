import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ClientAgreements from '@/pages/clients/agreements';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(
        (options: { onSuccess?: () => void; onFinish?: () => void }) => {
            options.onSuccess?.();
            options.onFinish?.();
        },
    ),
}));
const requestFetch = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        className,
    }: {
        href: string;
        children: ReactNode;
        className?: string;
    }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
    router: inertia,
}));

vi.mock('@/layouts/workspace-shell', () => ({
    default: ({
        children,
        activeModule,
    }: {
        children: ReactNode;
        activeModule?: string;
    }) => <div data-active-module={activeModule}>{children}</div>,
}));

beforeEach(() => {
    requestFetch.mockReset();
    requestFetch.mockImplementation(
        async () => new Response(JSON.stringify({ data: {} }), { status: 200 }),
    );
    vi.stubGlobal('fetch', requestFetch);
});

afterEach(() => vi.unstubAllGlobals());

function jsonRequestBody(body: RequestInit['body']): unknown {
    if (typeof body !== 'string') {
        throw new TypeError('Expected a JSON request body');
    }

    return JSON.parse(body);
}

const version = 'a'.repeat(64);

function props(overrides: Record<string, unknown> = {}) {
    return {
        company: { id: 'company-1', name: 'Synthetic Client' },
        version: 'c'.repeat(64),
        timezone: 'UTC',
        create_proposal_href: '/api/v1/workspaces/workspace-1/proposals',
        proposals: [
            {
                id: 'proposal-1',
                version: 'p'.repeat(64),
                href: '/workspaces/workspace-1/clients/company-1/proposals/proposal-1',
                send_href:
                    '/api/v1/workspaces/workspace-1/proposals/proposal-1/send',
                title: 'Synthetic Proposal',
                status: 'draft',
                total_amount: 120000,
                currency: 'USD',
            },
        ],
        agreements: [
            {
                id: 'agreement-1',
                version,
                href: '/workspaces/workspace-1/clients/company-1/agreements/agreement-1',
                activate_href:
                    '/api/v1/workspaces/workspace-1/agreements/agreement-1/activate',
                activate_enrollment: null,
                create_schedule_href: null,
                title: 'Synthetic Agreement',
                status: 'draft',
                billing_cadence: 'monthly',
                currency: 'USD',
            },
            {
                id: 'agreement-2',
                version,
                href: '/workspaces/workspace-1/clients/company-1/agreements/agreement-2',
                activate_href: null,
                activate_enrollment: null,
                create_schedule_href:
                    '/api/v1/workspaces/workspace-1/billing-schedules',
                title: 'Active Agreement',
                status: 'active',
                billing_cadence: 'quarterly',
                currency: 'USD',
            },
        ],
        billing_schedules: [
            {
                id: 'schedule-1',
                version: 's'.repeat(64),
                generate_href:
                    '/api/v1/workspaces/workspace-1/billing-schedules/schedule-1/generate',
                agreement_id: 'agreement-2',
                cadence: 'monthly',
                next_run_on: '2026-12-01',
                is_active: true,
            },
        ],
        ...overrides,
    };
}

describe('client agreements page', () => {
    it('lights the Agreements tab and lists proposals, agreements and schedules', () => {
        const { container } = render(<ClientAgreements {...props()} />);

        expect(
            container.querySelector('[data-active-module="agreements"]'),
        ).not.toBeNull();
        expect(
            screen.getByRole('link', { name: 'Synthetic Proposal' }),
        ).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/proposals/proposal-1',
        );
        expect(screen.getByText(/\$1,200\.00/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Synthetic Agreement' }),
        ).toBeInTheDocument();
        // A schedule is named by its agreement.
        expect(screen.getAllByText('Active Agreement').length).toBeGreaterThan(
            0,
        );
        expect(screen.getByText(/next/)).toBeInTheDocument();
    });

    it('offers no control to a viewer without the server capability', () => {
        render(
            <ClientAgreements
                {...props({
                    create_proposal_href: null,
                    proposals: [
                        {
                            ...props().proposals[0],
                            send_href: null,
                        },
                    ],
                    agreements: [
                        {
                            ...props().agreements[0],
                            activate_href: null,
                        },
                    ],
                    billing_schedules: [
                        {
                            ...props().billing_schedules[0],
                            generate_href: null,
                        },
                    ],
                })}
            />,
        );

        expect(screen.queryByRole('button')).toBeNull();
        expect(screen.queryByLabelText('Proposal title')).toBeNull();
    });

    it('shows an overlap refusal beside the confirmed API activation control', async () => {
        const message =
            'This agreement cannot overlap another active agreement. Ask an operator to verify its terms.';
        requestFetch.mockResolvedValueOnce(
            new Response(
                JSON.stringify({ message, errors: { engagement: [message] } }),
                { status: 422 },
            ),
        );
        render(<ClientAgreements {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Activate' }));
        const dialog = await screen.findByRole('dialog');
        expect(requestFetch).not.toHaveBeenCalled();
        fireEvent.click(
            within(dialog).getByRole('button', {
                name: 'Activate',
            }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(message);
        expect(requestFetch.mock.calls[0][0]).toBe(
            '/api/v1/workspaces/workspace-1/agreements/agreement-1/activate',
        );
        expect(jsonRequestBody(requestFetch.mock.calls[0][1].body)).toEqual({
            expected_version: version,
            confirm: true,
        });
    });

    it('starts a service package agreement through its enrollment, never its own activation', async () => {
        const enrollmentVersion = 'e'.repeat(64);
        render(
            <ClientAgreements
                {...props({
                    agreements: [
                        {
                            ...props().agreements[0],
                            activate_href: null,
                            activate_enrollment: {
                                href: '/api/v1/workspaces/workspace-1/enrollments/enrollment-1/activate',
                                version: enrollmentVersion,
                            },
                        },
                    ],
                })}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Activate' })).toBeNull();
        fireEvent.click(
            screen.getByRole('button', { name: 'Activate service' }),
        );
        const dialog = await screen.findByRole('dialog');
        expect(requestFetch).not.toHaveBeenCalled();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Activate service' }),
        );
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(1));
        expect(requestFetch.mock.calls[0][0]).toBe(
            '/api/v1/workspaces/workspace-1/enrollments/enrollment-1/activate',
        );
        expect(jsonRequestBody(requestFetch.mock.calls[0][1].body)).toEqual({
            expected_version: enrollmentVersion,
            confirm: true,
        });
    });

    it('confirms before sending a proposal and posts the rendered version', async () => {
        render(<ClientAgreements {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        const dialog = await screen.findByRole('dialog');
        expect(requestFetch).not.toHaveBeenCalled();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Send' }));
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(1));
        expect(requestFetch.mock.calls[0][0]).toBe(
            '/api/v1/workspaces/workspace-1/proposals/proposal-1/send',
        );
        expect(jsonRequestBody(requestFetch.mock.calls[0][1].body)).toEqual({
            expected_version: 'p'.repeat(64),
            confirm: true,
        });
    });

    it('confirms before generating due invoices from a schedule', async () => {
        render(<ClientAgreements {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Generate due' }));
        const dialog = await screen.findByRole('dialog');
        expect(requestFetch).not.toHaveBeenCalled();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Generate due' }),
        );
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(1));
        expect(requestFetch.mock.calls[0][0]).toBe(
            '/api/v1/workspaces/workspace-1/billing-schedules/schedule-1/generate',
        );
        expect(jsonRequestBody(requestFetch.mock.calls[0][1].body)).toEqual({
            expected_version: 's'.repeat(64),
            confirm: true,
        });
    });

    it('drafts a proposal and adds a schedule against the finished URLs', async () => {
        render(<ClientAgreements {...props()} />);

        const proposalForm = within(
            screen.getByRole('form', { name: 'Draft proposal' }),
        );
        fireEvent.change(proposalForm.getByLabelText('Proposal title'), {
            target: { value: 'Synthetic new proposal' },
        });
        fireEvent.change(proposalForm.getByLabelText('Line description'), {
            target: { value: 'Synthetic line' },
        });
        fireEvent.change(proposalForm.getByLabelText('Price (USD)'), {
            target: { value: '10.50' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Draft proposal' }));
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(1));
        expect(requestFetch.mock.calls[0][0]).toBe(
            '/api/v1/workspaces/workspace-1/proposals',
        );
        expect(jsonRequestBody(requestFetch.mock.calls[0][1].body)).toEqual(
            expect.objectContaining({
                company_id: 'company-1',
                expected_version: 'c'.repeat(64),
                title: 'Synthetic new proposal',
            }),
        );

        const scheduleForm = within(
            screen.getByRole('form', { name: /^Add billing schedule for / }),
        );
        fireEvent.change(scheduleForm.getByLabelText('Line description'), {
            target: { value: 'Synthetic hosting' },
        });
        fireEvent.change(scheduleForm.getByLabelText(/^Amount \(/), {
            target: { value: '5' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add schedule' }));
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(2));
        expect(requestFetch.mock.calls[1][0]).toBe(
            '/api/v1/workspaces/workspace-1/billing-schedules',
        );
        expect(jsonRequestBody(requestFetch.mock.calls[1][1].body)).toEqual(
            expect.objectContaining({
                company_id: 'company-1',
                client_agreement: 'agreement-2',
                cadence: 'quarterly',
            }),
        );
    });
});
