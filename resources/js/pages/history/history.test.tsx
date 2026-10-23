import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import HistoryIndex from '@/pages/history/index';
import HistoryShow from '@/pages/history/show';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import type { HistoryDetail } from '@/types/history';

const search = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        Head: () => null,
        Link: ({
            children,
            ...props
        }: {
            children: ReactNode;
            href: string;
        }) => <a {...props}>{children}</a>,
        useForm: function useForm(initial: Record<string, string>) {
            const [data, setData] = useState(initial);

            return {
                data,
                processing: false,
                setData: (field: string, value: string) =>
                    setData((prior) => ({ ...prior, [field]: value })),
                get: (href: string) => search.get(href, data),
            };
        },
    };
});

const entry: HistoryDetail = {
    id: 'synthetic-history',
    title: 'SyntheticHistoricalTitle'.repeat(15),
    kind: 'Discussion',
    body:
        '<script>never execute</script>\n' +
        'SyntheticHistoricalBody'.repeat(30),
    author: 'SyntheticAuthor'.repeat(12),
    occurred_on: '2020-03-04',
    href: '/synthetic/history/detail',
    fields: [
        {
            label: 'Original billing name',
            value: 'SyntheticOriginalName'.repeat(15),
        },
    ],
    tables: [
        {
            label: 'Original invoice items',
            columns: ['Description', 'Discount'],
            rows: [['SyntheticItemDescription'.repeat(30), '0.00400']],
        },
    ],
    company: { name: 'SyntheticCompany'.repeat(10), href: '/synthetic/client' },
    parent: null,
    children: [
        {
            id: 'synthetic-comment',
            title: 'SyntheticCommentTitle'.repeat(15),
            kind: 'Comment',
            author: null,
            occurred_on: null,
            href: '/synthetic/comment',
        },
    ],
    children_href: '/synthetic/all-comments',
    download_href: '/synthetic/download',
};

describe('historical library', () => {
    it('preserves source text and related links without rendering source HTML or active billing controls', () => {
        const view = render(<HistoryShow entry={entry} />);
        expect(view.container.querySelector('script')).toBeNull();
        expect(
            screen.getByText(/<script>never execute<\/script>/),
        ).toBeVisible();
        expect(screen.getByText('0.00400')).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Download preserved file' }),
        ).toHaveAttribute('href', '/synthetic/download');
        expect(
            screen.getByRole('link', { name: 'All related records' }),
        ).toHaveAttribute('href', '/synthetic/all-comments');
        expect(screen.queryByRole('button')).toBeNull();
        expect(horizontalOverflowRisks(view.container)).toEqual([]);
    });

    it('searches through the supplied URL while retaining company and parent filters', () => {
        const view = render(
            <HistoryIndex
                entries={[entry]}
                search_href="/synthetic/history-search"
                filters={{
                    q: '',
                    kind: '',
                    company: 'synthetic-client',
                    parent: 'synthetic-thread',
                }}
                kinds={[{ value: 'discussion', label: 'Discussion' }]}
                next_href="/synthetic/next"
                previous_href={null}
            />,
        );
        fireEvent.change(screen.getByLabelText('Search history'), {
            target: { value: 'PO/50%' },
        });
        fireEvent.change(screen.getByLabelText('Record type'), {
            target: { value: 'discussion' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));
        expect(search.get).toHaveBeenLastCalledWith(
            '/synthetic/history-search',
            {
                q: 'PO/50%',
                kind: 'discussion',
                company: 'synthetic-client',
                parent: 'synthetic-thread',
            },
        );
        expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute(
            'href',
            '/synthetic/next',
        );
        expect(horizontalOverflowRisks(view.container)).toEqual([]);
    });
});
