import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiRequest } from '@/lib/api';
import PublicPackageShow from '@/pages/packages/public/show';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { requestForm } from '@/test/package-fixtures';
import type { PublicPackageProps } from '@/types/packages';

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
}));
vi.mock('@/lib/api', () => ({ apiRequest: vi.fn() }));

const LONG = 'Unbroken'.repeat(14);

function props(
    overrides: Partial<PublicPackageProps['request']> = {},
): PublicPackageProps {
    return {
        workspace: { name: 'Synthetic Provider' },
        package: {
            name: 'Hosting',
            summary: null,
            currency: 'USD',
            billing_cadence: 'monthly',
            cycle_amount: 4500,
            setup_fee_amount: null,
            minimum_term_months: null,
            url: 'https://svc.example.test/s/tok123/hosting',
            body_markdown: null,
            components: [],
            hourly_rate_amount: null,
            retainer_amount: null,
            retainer_minutes: null,
            rollover_months: null,
            signup: {
                setup_fee_amount: null,
                charges_first_period: false,
                requires_autopay: false,
                autopay_limit_amount: null,
            },
            notice_days: 0,
            cancellation_mode: 'request',
        },
        catalog_url: null,
        sign_in_url: '/login',
        request: requestForm(overrides),
    };
}

async function fill(user: ReturnType<typeof userEvent.setup>) {
    await user.type(screen.getByLabelText('Company name'), 'Lakeside Bakery');
    await user.type(screen.getByLabelText('Your name'), 'Morgan Ortiz');
    await user.type(screen.getByLabelText('Email'), 'morgan@example.test');
    await user.type(
        screen.getByLabelText('Website address'),
        'lakeside.example.test',
    );
}

describe('public request form', () => {
    beforeEach(() => vi.mocked(apiRequest).mockReset());

    it('[PUB-4] posts the typed fields, the token and an empty honeypot', async () => {
        const user = userEvent.setup();
        vi.mocked(apiRequest).mockResolvedValue({
            ok: true,
            status: 201,
            data: { status: 'sent' },
        });
        render(<PublicPackageShow {...props()} />);
        await fill(user);
        await user.click(screen.getByRole('button', { name: 'Send request' }));

        await waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(1));
        expect(vi.mocked(apiRequest).mock.calls[0].slice(0, 3)).toEqual([
            'POST',
            '/s/tok123/synthetic-hosting/request',
            {
                form_token: 'opaque-form-token',
                company_name: 'Lakeside Bakery',
                contact_name: 'Morgan Ortiz',
                email: 'morgan@example.test',
                phone: '',
                answers: { domain: 'lakeside.example.test', goal: '' },
                website_url: '',
            },
        ]);
        expect(
            await screen.findByRole('heading', { name: 'Request sent' }),
        ).toBeInTheDocument();
        expect(screen.getByText(/morgan@example\.test/)).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Send request' }),
        ).not.toBeInTheDocument();
    });

    it('reads an unanswered question keyed like an Object property as empty', async () => {
        const user = userEvent.setup();
        vi.mocked(apiRequest).mockResolvedValue({
            ok: true,
            status: 201,
            data: { status: 'sent' },
        });
        render(
            <PublicPackageShow
                {...props({
                    questions: [
                        ...requestForm().questions,
                        {
                            key: 'constructor',
                            label: 'Constructor',
                            kind: 'short_text',
                            required: false,
                            max_length: 255,
                        },
                        {
                            key: '__proto__',
                            label: 'Proto',
                            kind: 'short_text',
                            required: false,
                            max_length: 255,
                        },
                    ],
                })}
            />,
        );
        await fill(user);
        await user.click(screen.getByRole('button', { name: 'Send request' }));

        await waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(1));
        const body = vi.mocked(apiRequest).mock.calls[0][2] as {
            answers: Record<string, string>;
        };
        expect(Object.hasOwn(body.answers, 'constructor')).toBe(true);
        expect(body.answers.constructor).toBe('');
        expect(
            Object.getOwnPropertyDescriptor(body.answers, '__proto__')?.value,
        ).toBe('');
    });

    it('keeps the honeypot out of sight, out of the tab order and unannounced', () => {
        const { container } = render(<PublicPackageShow {...props()} />);
        const trap = container.querySelector<HTMLInputElement>(
            'input[name="website_url"]',
        );

        expect(trap).not.toBeNull();
        expect(trap).toHaveAttribute('tabindex', '-1');
        expect(trap).toHaveAttribute('autocomplete', 'off');
        expect(trap?.closest('[aria-hidden="true"]')).not.toBeNull();
    });

    it('shows the server’s one generic sentence on a refusal and explains nothing', async () => {
        const user = userEvent.setup();
        vi.mocked(apiRequest).mockResolvedValue({
            ok: false,
            status: 422,
            message: 'We could not send this request. Please try again later.',
        });
        render(<PublicPackageShow {...props()} />);
        await fill(user);
        await user.click(screen.getByRole('button', { name: 'Send request' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'We could not send this request. Please try again later.',
        );
        expect(screen.getByLabelText('Company name')).toHaveValue(
            'Lakeside Bakery',
        );
    });

    it('shows field errors beside their fields', async () => {
        const user = userEvent.setup();
        vi.mocked(apiRequest).mockResolvedValue({
            ok: false,
            status: 422,
            message: 'The email must be a valid email address.',
            errors: { email: ['The email must be a valid email address.'] },
        });
        render(<PublicPackageShow {...props()} />);
        await fill(user);
        await user.click(screen.getByRole('button', { name: 'Send request' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'valid email address',
        );
        expect(screen.getByLabelText('Email')).toHaveAttribute(
            'aria-invalid',
            'true',
        );
    });

    it('does not post while a required field is empty', async () => {
        const user = userEvent.setup();
        render(<PublicPackageShow {...props()} />);
        await user.click(screen.getByRole('button', { name: 'Send request' }));

        expect(apiRequest).not.toHaveBeenCalled();
        expect(screen.getAllByText('Please fill this in.')).toHaveLength(4);
    });

    it('marks optional questions, limits their length and starts from a signed-in visitor', () => {
        render(
            <PublicPackageShow
                {...props({
                    prefill: {
                        contact_name: 'Sam Lee',
                        email: 'sam@example.test',
                    },
                })}
            />,
        );

        expect(screen.getByLabelText('Your name')).toHaveValue('Sam Lee');
        expect(screen.getByLabelText('Email')).toHaveValue('sam@example.test');
        expect(
            screen.getByLabelText(
                'What should the first hours go toward? (optional)',
            ),
        ).toHaveAttribute('maxlength', '2000');
        expect(screen.getByLabelText('Website address')).toHaveAttribute(
            'maxlength',
            '255',
        );
    });

    it('loads nothing from anywhere else', () => {
        const { container } = render(<PublicPackageShow {...props()} />);

        expect(
            container.querySelectorAll('script, iframe, img, link'),
        ).toHaveLength(0);
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('survives an unbroken question label', () => {
        const { container } = render(
            <PublicPackageShow
                {...props({
                    questions: [
                        {
                            key: 'domain',
                            label: LONG,
                            kind: 'short_text',
                            required: true,
                            max_length: 255,
                        },
                    ],
                })}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
