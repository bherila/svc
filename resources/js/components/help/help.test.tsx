import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { HelpPanel } from '@/components/help/help-panel';
import { setHelpOpen } from '@/components/help/help-store';
import { TermTip } from '@/components/help/term-tip';
import type { HelpArticle } from '@/help/front-matter';

const page = vi.hoisted(() => ({ props: { routeName: 'clients.time' } }));

vi.mock('@inertiajs/react', () => ({ usePage: () => page }));

const ARTICLES: HelpArticle[] = [
    {
        slug: 'time',
        title: 'Time',
        audience: ['operator'],
        routes: ['clients.time'],
        terms: [],
        body: 'Intro text.\n\n## Approving\n\nSee [invoices](help:invoices).',
    },
    {
        slug: 'invoices',
        title: 'Invoices',
        audience: ['operator'],
        routes: ['clients.invoices'],
        terms: [],
        body: 'Invoices explained.',
    },
];

afterEach(() => act(() => setHelpOpen(false)));

describe('TermTip', () => {
    it('opens on click', async () => {
        render(<TermTip term="Retainer" />);

        await userEvent.click(
            screen.getByRole('button', { name: 'What is Retainer?' }),
        );

        expect(await screen.findByRole('tooltip')).toHaveTextContent(
            /block of hours/,
        );
    });

    it('opens on keyboard focus', async () => {
        render(<TermTip term="Overage" />);

        await userEvent.tab();

        expect(await screen.findByRole('tooltip')).toHaveTextContent(
            /beyond what the retainer covers/,
        );
    });

    it('renders nothing for a term the glossary lacks', () => {
        const { container } = render(<TermTip term="Nonexistent" />);

        expect(container).toBeEmptyDOMElement();
    });
});

describe('HelpPanel', () => {
    it('renders nothing while closed', () => {
        render(<HelpPanel articles={ARTICLES} />);

        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('opens with Ctrl+/ , lists this page first and moves focus in', async () => {
        render(<HelpPanel articles={ARTICLES} />);

        await userEvent.keyboard('{Control>}/{/Control}');

        const dialog = await screen.findByRole('dialog', { name: 'Help' });

        expect(dialog).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Help' })).toHaveFocus();

        const here = screen.getByRole('region', { name: 'On this page' });

        expect(here).toHaveTextContent('Time');
        expect(here).not.toHaveTextContent('Invoices');
    });

    it('searches across everything and opens an article', async () => {
        render(<HelpPanel articles={ARTICLES} />);
        act(() => setHelpOpen(true));

        await userEvent.type(screen.getByLabelText('Search help'), 'explained');

        expect(
            screen.queryByRole('region', { name: 'On this page' }),
        ).toBeNull();

        await userEvent.click(screen.getByRole('button', { name: 'Invoices' }));

        expect(screen.getByText('Invoices explained.')).toBeInTheDocument();
    });

    it('follows a link between articles and closes with Escape', async () => {
        render(<HelpPanel articles={ARTICLES} />);
        act(() => setHelpOpen(true));

        await userEvent.click(screen.getByRole('button', { name: 'Time' }));
        await userEvent.click(screen.getByRole('link', { name: 'invoices' }));

        expect(screen.getByText('Invoices explained.')).toBeInTheDocument();

        await userEvent.keyboard('{Escape}');

        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    });
});
