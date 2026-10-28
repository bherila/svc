import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AttachmentSharingPanel from '@/components/files/attachment-sharing-panel';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import type { AttachmentPanel } from '@/types/files';

const reload = vi.hoisted(() =>
    vi.fn((options?: { onSuccess?: () => void; onFinish?: () => void }) => {
        options?.onSuccess?.();
        options?.onFinish?.();
    }),
);
vi.mock('@inertiajs/react', () => ({ router: { reload } }));

const panel: AttachmentPanel = {
    files: [
        {
            id: 'synthetic-file',
            filename:
                '<script>synthetic</script>' +
                'LongSyntheticFilename'.repeat(40),
            media_type: 'text/plain',
            bytes: 123,
            uploaded_at: null,
            download_href: '/synthetic/download',
            delete_href: '/synthetic/delete',
            deletion: {
                expected_version: 'v'.repeat(64),
                expected_parent_version: 'p'.repeat(64),
            },
            sharing: {
                is_visible_to_client: false,
                effective_visible_to_client: false,
                sharing_note: 'The parent is not currently visible to clients.',
                version: 'v'.repeat(64),
                parent_version: 'p'.repeat(64),
                update_href: '/synthetic/sharing',
            },
        },
    ],
    upload: { href: '/synthetic/upload', parent_version: 'p'.repeat(64) },
    next_href: '/synthetic/next',
};

afterEach(() => vi.unstubAllGlobals());

function respond(body: unknown, status = 200) {
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValue(new Response(JSON.stringify(body), { status })),
    );
}

describe('attachment sharing panel', () => {
    it('renders the authorized initial seed without a request and hides absent controls', () => {
        vi.stubGlobal('fetch', vi.fn());
        const { container } = render(
            <AttachmentSharingPanel
                panel={{
                    ...panel,
                    files: panel.files.map((file) => ({
                        ...file,
                        sharing: null,
                        delete_href: null,
                    })),
                    upload: null,
                    next_href: null,
                }}
            />,
        );
        expect(fetch).not.toHaveBeenCalled();
        expect(
            screen.getByRole('link', { name: panel.files[0].filename }),
        ).toHaveAttribute('href', '/synthetic/download');
        expect(
            screen.queryByLabelText('Visible to client'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Upload a file')).not.toBeInTheDocument();
        expect(container.querySelector('script')).toBeNull();
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('sends an explicit boolean and both versions, then uses the new version for confirmed deletion', async () => {
        respond({
            data: {
                id: 'synthetic-file',
                is_visible_to_client: true,
                effective_visible_to_client: false,
                sharing_note: 'The parent is not currently visible to clients.',
                version: 'n'.repeat(64),
                parent_version: 'p'.repeat(64),
            },
        });
        render(<AttachmentSharingPanel panel={panel} />);
        fireEvent.click(screen.getByLabelText('Visible to client'));
        await screen.findByText(
            'The parent is not currently visible to clients.',
        );
        expect(
            JSON.parse(String(vi.mocked(fetch).mock.calls[0][1]?.body)),
        ).toEqual({
            is_visible_to_client: true,
            expected_version: 'v'.repeat(64),
            expected_parent_version: 'p'.repeat(64),
        });
        respond({ data: { status: 'deleting' } });
        fireEvent.click(screen.getByRole('button', { name: 'Remove file' }));
        expect(fetch).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm removal' }),
        );
        await waitFor(() => expect(reload).toHaveBeenCalled());
        expect(
            JSON.parse(String(vi.mocked(fetch).mock.calls[0][1]?.body)),
        ).toEqual({
            confirm: true,
            expected_version: 'n'.repeat(64),
            expected_parent_version: 'p'.repeat(64),
        });
    });

    it('uploads private-first multipart bytes and only fetches continuation on request', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            data: { url: '/synthetic/signed-upload' },
                        }),
                    ),
                )
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({ data: { id: 'synthetic-new' } }),
                    ),
                ),
        );
        render(<AttachmentSharingPanel panel={panel} />);
        const file = new File(['Synthetic bytes'], 'synthetic-new.txt');
        await userEvent.upload(screen.getByLabelText('Upload a file'), file);
        expect(
            screen.getByRole('button', { name: 'Upload file' }),
        ).toBeEnabled();
        expect(
            (screen.getByLabelText('Upload a file') as HTMLInputElement)
                .files?.[0],
        ).toBe(file);
        // jsdom reports a required file input invalid despite its populated FileList.
        fireEvent.submit(
            screen.getByLabelText('Upload a file').closest('form')!,
        );
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
        expect(String(vi.mocked(fetch).mock.calls[0][0])).toBe(
            '/synthetic/upload',
        );
        const body = vi.mocked(fetch).mock.calls[1][1]?.body as FormData;
        expect(body.get('file')).toBe(file);
        expect(body.get('expected_version')).toBe('p'.repeat(64));
        expect(body.has('is_visible_to_client')).toBe(false);
        respond({
            files: [
                {
                    ...panel.files[0],
                    id: 'synthetic-more',
                    filename: 'Synthetic continuation file',
                },
            ],
            upload: null,
            next_href: null,
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Load more files' }),
        );
        await screen.findByRole('link', {
            name: 'Synthetic continuation file',
        });
        expect(String(vi.mocked(fetch).mock.calls[0][0])).toContain(
            '/synthetic/next',
        );
        expect(
            screen.queryByRole('button', { name: 'Load more files' }),
        ).not.toBeInTheDocument();
    });

    it('prepares an expense receipt with GET and uploads to its signed upload_url', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            data: { upload_url: '/synthetic/signed-receipt' },
                        }),
                    ),
                )
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            data: { id: 'synthetic-receipt' },
                        }),
                    ),
                ),
        );
        render(
            <AttachmentSharingPanel
                panel={{
                    ...panel,
                    upload: {
                        href: '/synthetic/receipt-preparation',
                        method: 'GET',
                        parent_version: 'p'.repeat(64),
                    },
                }}
            />,
        );
        const file = new File(
            ['Synthetic receipt bytes'],
            'synthetic-receipt.txt',
        );
        await userEvent.upload(screen.getByLabelText('Upload a file'), file);
        fireEvent.submit(
            screen.getByLabelText('Upload a file').closest('form')!,
        );
        await waitFor(() =>
            expect(
                (screen.getByLabelText('Upload a file') as HTMLInputElement)
                    .files,
            ).toHaveLength(0),
        );
        expect(vi.mocked(fetch).mock.calls[0]).toEqual([
            expect.stringMatching(/\/synthetic\/receipt-preparation$/),
            expect.objectContaining({ method: 'GET', body: undefined }),
        ]);
        expect(vi.mocked(fetch).mock.calls[1][0]).toBe(
            '/synthetic/signed-receipt',
        );
        const request = vi.mocked(fetch).mock.calls[1][1]!;
        expect(request.method).toBe('POST');
        expect((request.body as FormData).get('file')).toBe(file);
        expect((request.body as FormData).get('expected_version')).toBe(
            'p'.repeat(64),
        );
        expect((request.body as FormData).has('is_visible_to_client')).toBe(
            false,
        );
        expect(
            (request.headers as Record<string, string>)['Idempotency-Key'],
        ).toBeTruthy();
        expect(
            (screen.getByLabelText('Upload a file') as HTMLInputElement).files,
        ).toHaveLength(0);
    });

    it('retries an ambiguous receipt upload with the original file, preparation method, version and key', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            data: { upload_url: '/synthetic/signed-receipt' },
                        }),
                    ),
                )
                .mockRejectedValueOnce(
                    new Error('Synthetic lost upload answer'),
                )
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            data: {
                                upload_url: '/synthetic/renewed-signed-receipt',
                            },
                        }),
                    ),
                )
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({ data: { id: 'synthetic-receipt' } }),
                    ),
                ),
        );
        const { rerender } = render(
            <AttachmentSharingPanel
                panel={{
                    ...panel,
                    upload: {
                        href: '/synthetic/receipt-preparation',
                        method: 'GET',
                        parent_version: 'p'.repeat(64),
                    },
                }}
            />,
        );
        const file = new File(
            ['Synthetic original receipt'],
            'synthetic-original-receipt.txt',
        );
        await userEvent.upload(screen.getByLabelText('Upload a file'), file);
        fireEvent.submit(
            screen.getByLabelText('Upload a file').closest('form')!,
        );
        await screen.findByRole('alert');
        expect(screen.getByLabelText('Upload a file')).toBeDisabled();
        expect(
            (screen.getByLabelText('Upload a file') as HTMLInputElement)
                .files?.[0],
        ).toBe(file);
        const original = vi.mocked(fetch).mock.calls[1][1]!;
        rerender(
            <AttachmentSharingPanel
                panel={{
                    ...panel,
                    upload: {
                        href: '/synthetic/different-preparation',
                        method: 'POST',
                        parent_version: 'q'.repeat(64),
                    },
                }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Check upload' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Upload file' }),
            ).toBeDisabled(),
        );
        expect(vi.mocked(fetch).mock.calls[2]).toEqual([
            expect.stringMatching(/\/synthetic\/receipt-preparation$/),
            expect.objectContaining({ method: 'GET', body: undefined }),
        ]);
        expect(vi.mocked(fetch).mock.calls[3][0]).toBe(
            '/synthetic/renewed-signed-receipt',
        );
        const retried = vi.mocked(fetch).mock.calls[3][1]!;
        expect(Array.from((retried.body as FormData).entries())).toEqual(
            Array.from((original.body as FormData).entries()),
        );
        expect((retried.body as FormData).get('file')).toBe(file);
        expect(
            (retried.headers as Record<string, string>)['Idempotency-Key'],
        ).toBe((original.headers as Record<string, string>)['Idempotency-Key']);
        expect(screen.getByLabelText('Upload a file')).toBeEnabled();
        expect(
            (screen.getByLabelText('Upload a file') as HTMLInputElement).files,
        ).toHaveLength(0);
    });

    it('keeps a recorded upload attempt through refresh failure and releases it only after reconciliation', async () => {
        reload.mockImplementationOnce((options) => {
            options?.onFinish?.();
        });
        vi.stubGlobal(
            'fetch',
            vi.fn().mockImplementation((href: string) =>
                Promise.resolve(
                    new Response(
                        JSON.stringify({
                            data: href.includes('preparation')
                                ? { upload_url: '/synthetic/signed-receipt' }
                                : { id: 'synthetic-receipt' },
                        }),
                    ),
                ),
            ),
        );
        const { rerender } = render(
            <AttachmentSharingPanel
                panel={{
                    ...panel,
                    upload: {
                        href: '/synthetic/receipt-preparation',
                        method: 'GET',
                        parent_version: 'p'.repeat(64),
                    },
                }}
            />,
        );
        const file = new File(
            ['Synthetic recorded receipt'],
            'synthetic-recorded-receipt.txt',
        );
        await userEvent.upload(screen.getByLabelText('Upload a file'), file);
        fireEvent.submit(
            screen.getByLabelText('Upload a file').closest('form')!,
        );
        await screen.findByText(
            'The upload was recorded, but this page could not refresh. Check the original upload to refresh it.',
        );
        const original = vi.mocked(fetch).mock.calls[1][1]!;
        expect(screen.getByLabelText('Upload a file')).toBeDisabled();
        expect(
            (screen.getByLabelText('Upload a file') as HTMLInputElement)
                .files?.[0],
        ).toBe(file);
        rerender(
            <AttachmentSharingPanel
                panel={{
                    ...panel,
                    upload: {
                        href: '/synthetic/current-preparation',
                        method: 'POST',
                        parent_version: 'q'.repeat(64),
                    },
                }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Check upload' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Upload file' }),
            ).toBeDisabled(),
        );
        expect(fetch).toHaveBeenCalledTimes(2);
        const originalKey = (original.headers as Record<string, string>)[
            'Idempotency-Key'
        ];
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Upload a file')).toBeEnabled();
        const nextFile = new File(
            ['Synthetic next receipt'],
            'synthetic-next-receipt.txt',
        );
        await userEvent.upload(
            screen.getByLabelText('Upload a file'),
            nextFile,
        );
        fireEvent.submit(
            screen.getByLabelText('Upload a file').closest('form')!,
        );
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(4));
        expect(vi.mocked(fetch).mock.calls[2]).toEqual([
            '/synthetic/current-preparation',
            expect.objectContaining({ method: 'POST' }),
        ]);
        const next = vi.mocked(fetch).mock.calls[3][1]!;
        expect((next.body as FormData).get('file')).toBe(nextFile);
        expect((next.body as FormData).get('expected_version')).toBe(
            'q'.repeat(64),
        );
        expect(
            (next.headers as Record<string, string>)['Idempotency-Key'],
        ).not.toBe(originalKey);
    });

    it('keeps a timeout upload frozen after a later refusal and checks the same original bytes and key', async () => {
        let uploads = 0;
        vi.stubGlobal(
            'fetch',
            vi.fn(async (_href: string, options: RequestInit) => {
                if (options.method === 'GET') {
                    return new Response(
                        JSON.stringify({
                            data: { upload_url: '/synthetic/signed-latched' },
                        }),
                    );
                }

                uploads++;

                if (uploads === 1) {
                    return new Response(
                        JSON.stringify({ message: 'Synthetic timeout' }),
                        { status: 408 },
                    );
                }

                if (uploads === 2) {
                    return new Response(
                        JSON.stringify({
                            message: 'Synthetic current permission refusal',
                        }),
                        { status: 403 },
                    );
                }

                return new Response(
                    JSON.stringify({
                        data: { id: 'synthetic-original-upload' },
                    }),
                    { status: 201 },
                );
            }),
        );
        const upload = {
            href: '/synthetic/original-preparation',
            method: 'GET' as const,
            parent_version: 'p'.repeat(64),
        };
        const view = render(
            <AttachmentSharingPanel panel={{ ...panel, upload }} />,
        );
        const file = new File(
            ['Synthetic immutable bytes'],
            'synthetic-immutable.txt',
        );
        await userEvent.upload(screen.getByLabelText('Upload a file'), file);
        fireEvent.submit(
            screen.getByLabelText('Upload a file').closest('form')!,
        );
        await screen.findByText('Synthetic timeout');
        expect(screen.getByLabelText('Upload a file')).toBeDisabled();
        view.rerender(
            <AttachmentSharingPanel
                panel={{
                    ...panel,
                    upload: {
                        ...upload,
                        href: '/synthetic/changed-preparation',
                        parent_version: 'q'.repeat(64),
                    },
                }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Check upload' }));
        await screen.findByText('Synthetic current permission refusal');
        expect(screen.getByLabelText('Upload a file')).toBeDisabled();
        const original = vi.mocked(fetch).mock.calls[1][1]!;
        expect(vi.mocked(fetch).mock.calls[3][1]!.headers).toEqual(
            original.headers,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Check upload' }));
        await waitFor(() =>
            expect(screen.getByLabelText('Upload a file')).toBeEnabled(),
        );
        expect(fetch).toHaveBeenCalledTimes(6);

        for (const index of [3, 5]) {
            const checked = vi.mocked(fetch).mock.calls[index][1]!;
            expect((checked.body as FormData).get('file')).toBe(file);
            expect((checked.body as FormData).get('expected_version')).toBe(
                'p'.repeat(64),
            );
            expect(checked.headers).toEqual(original.headers);
        }
    });

    it('recovers a stale upload after refreshed expense props without discarding uncertain attempts', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            data: { upload_url: '/synthetic/signed-receipt' },
                        }),
                    ),
                )
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({ message: 'Synthetic stale expense' }),
                        { status: 409 },
                    ),
                )
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            data: {
                                upload_url: '/synthetic/current-signed-receipt',
                            },
                        }),
                    ),
                )
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({ data: { id: 'synthetic-receipt' } }),
                    ),
                ),
        );
        const upload = {
            href: '/synthetic/receipt-preparation',
            method: 'GET' as const,
            parent_version: 'p'.repeat(64),
        };
        const { rerender } = render(
            <AttachmentSharingPanel panel={{ ...panel, upload }} />,
        );
        const file = new File(
            ['Synthetic stale receipt'],
            'synthetic-stale-receipt.txt',
        );
        await userEvent.upload(screen.getByLabelText('Upload a file'), file);
        fireEvent.submit(
            screen.getByLabelText('Upload a file').closest('form')!,
        );
        await screen.findByRole('alert');
        expect(screen.getByLabelText('Upload a file')).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Upload file' }),
        ).toBeDisabled();
        const original = vi.mocked(fetch).mock.calls[1][1]!;
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload current files' }),
        );
        rerender(
            <AttachmentSharingPanel
                panel={{
                    ...panel,
                    upload: { ...upload, parent_version: 'q'.repeat(64) },
                }}
            />,
        );
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Upload a file')).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Upload file' }),
        ).toBeEnabled();
        fireEvent.submit(
            screen.getByLabelText('Upload a file').closest('form')!,
        );
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(4));
        const current = vi.mocked(fetch).mock.calls[3][1]!;
        expect((current.body as FormData).get('file')).toBe(file);
        expect((current.body as FormData).get('expected_version')).toBe(
            'q'.repeat(64),
        );
        expect(
            (current.headers as Record<string, string>)['Idempotency-Key'],
        ).not.toBe(
            (original.headers as Record<string, string>)['Idempotency-Key'],
        );
    });

    it('deletes through the canonical API when sharing controls are unavailable', async () => {
        respond({ data: { status: 'deleting' } });
        render(
            <AttachmentSharingPanel
                panel={{
                    ...panel,
                    files: panel.files.map((file) => ({
                        ...file,
                        sharing: null,
                        deletion: { expected_version: 'v'.repeat(64) },
                    })),
                    upload: null,
                    next_href: null,
                }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Remove file' }));
        expect(fetch).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm removal' }),
        );
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        expect(
            JSON.parse(String(vi.mocked(fetch).mock.calls[0][1]?.body)),
        ).toEqual({ expected_version: 'v'.repeat(64), confirm: true });
    });
    it('keeps a lost-answer sharing retry latched after a later conflict', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockRejectedValue(new Error('Synthetic lost answer')),
        );
        render(<AttachmentSharingPanel panel={panel} />);
        fireEvent.click(screen.getByLabelText('Visible to client'));
        await screen.findByRole('alert');
        const firstKey = (
            vi.mocked(fetch).mock.calls[0][1]?.headers as Record<string, string>
        )['Idempotency-Key'];
        vi.mocked(fetch).mockResolvedValue(
            new Response(
                JSON.stringify({ message: 'Synthetic version conflict' }),
                { status: 409 },
            ),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Check sharing' }));
        await screen.findByText('Synthetic version conflict');
        expect(
            (
                vi.mocked(fetch).mock.calls[1][1]?.headers as Record<
                    string,
                    string
                >
            )['Idempotency-Key'],
        ).toBe(firstKey);
        expect(screen.getByLabelText('Visible to client')).toBeDisabled();
        expect(
            screen.queryByRole('button', { name: 'Reload current files' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Check sharing' }),
        ).toBeEnabled();
    });

    it('replays the original sharing body after props change and a later permission refusal', async () => {
        const responses = [
            new Response(JSON.stringify({ message: 'Synthetic timeout' }), {
                status: 408,
            }),
            new Response(
                JSON.stringify({ message: 'Synthetic permission refusal' }),
                { status: 403 },
            ),
            new Response(
                JSON.stringify({
                    data: {
                        ...panel.files[0].sharing,
                        is_visible_to_client: true,
                        version: 'n'.repeat(64),
                    },
                }),
            ),
        ];
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => responses.shift()!),
        );
        const view = render(<AttachmentSharingPanel panel={panel} />);
        fireEvent.click(screen.getByLabelText('Visible to client'));
        await screen.findByText('Synthetic timeout');
        const original = vi.mocked(fetch).mock.calls[0];
        view.rerender(
            <AttachmentSharingPanel
                panel={{
                    ...panel,
                    files: panel.files.map((file) => ({
                        ...file,
                        sharing: {
                            ...file.sharing!,
                            update_href: '/synthetic/changed-sharing',
                            parent_version: 'q'.repeat(64),
                        },
                    })),
                }}
            />,
        );
        expect(screen.getByLabelText('Visible to client')).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Check sharing' }));
        await screen.findByText('Synthetic permission refusal');
        expect(screen.getByLabelText('Visible to client')).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Check sharing' }));
        await waitFor(() =>
            expect(screen.getByLabelText('Visible to client')).toBeEnabled(),
        );
        expect(fetch).toHaveBeenCalledTimes(3);
        expect(vi.mocked(fetch).mock.calls[1]).toEqual(original);
        expect(vi.mocked(fetch).mock.calls[2]).toEqual(original);
    });

    it('keeps removal immutable across timeout, changed versions and later conflict, then retries only the accepted refresh', async () => {
        const responses = [
            new Response(
                JSON.stringify({ message: 'Synthetic delete timeout' }),
                { status: 408 },
            ),
            new Response(
                JSON.stringify({ message: 'Synthetic delete refusal' }),
                { status: 409 },
            ),
            new Response(JSON.stringify({ data: { status: 'deleted' } })),
        ];
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => responses.shift()!),
        );
        const view = render(<AttachmentSharingPanel panel={panel} />);
        fireEvent.click(screen.getByRole('button', { name: 'Remove file' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm removal' }),
        );
        await screen.findByText('Synthetic delete timeout');
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        const original = vi.mocked(fetch).mock.calls[0];
        const before = new CustomEvent('inertia:before', {
            cancelable: true,
            detail: {
                visit: { method: 'get', url: '/synthetic/elsewhere', only: [] },
            },
        });
        document.dispatchEvent(before);
        expect(before.defaultPrevented).toBe(true);
        view.rerender(
            <AttachmentSharingPanel
                panel={{
                    ...panel,
                    files: panel.files.map((file) => ({
                        ...file,
                        delete_href: '/synthetic/changed-delete',
                        deletion: { expected_version: 'q'.repeat(64) },
                    })),
                }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Check removal' }));
        await screen.findByText('Synthetic delete refusal');
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        reload.mockImplementationOnce((options) => {
            options?.onFinish?.();
        });
        fireEvent.click(screen.getByRole('button', { name: 'Check removal' }));
        await screen.findByText(
            'The file was removed, but this page could not refresh. Refresh the removed file before another removal.',
        );
        expect(vi.mocked(fetch).mock.calls[1]).toEqual(original);
        expect(vi.mocked(fetch).mock.calls[2]).toEqual(original);
        const after = new CustomEvent('inertia:before', {
            cancelable: true,
            detail: {
                visit: { method: 'get', url: '/synthetic/elsewhere', only: [] },
            },
        });
        document.dispatchEvent(after);
        expect(after.defaultPrevented).toBe(false);
        const unload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unload);
        expect(unload.defaultPrevented).toBe(false);
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh removed file' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Remove file' }),
            ).toBeEnabled(),
        );
        expect(fetch).toHaveBeenCalledTimes(3);
    });

    it('blocks navigation and unload while upload is in flight, then permits navigation after acceptance despite failed refresh', async () => {
        let finish: (response: Response) => void = () => {};
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            data: { url: '/synthetic/signed-upload' },
                        }),
                    ),
                )
                .mockImplementationOnce(
                    () =>
                        new Promise<Response>((resolve) => {
                            finish = resolve;
                        }),
                ),
        );
        reload.mockImplementationOnce((options) => {
            options?.onFinish?.();
        });
        render(<AttachmentSharingPanel panel={panel} />);
        await userEvent.upload(
            screen.getByLabelText('Upload a file'),
            new File(['Synthetic guard bytes'], 'synthetic-guard.txt'),
        );
        fireEvent.submit(
            screen.getByLabelText('Upload a file').closest('form')!,
        );
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
        const before = new CustomEvent('inertia:before', {
            cancelable: true,
            detail: {
                visit: { method: 'get', url: '/synthetic/elsewhere', only: [] },
            },
        });
        document.dispatchEvent(before);
        expect(before.defaultPrevented).toBe(true);
        const unload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unload);
        expect(unload.defaultPrevented).toBe(true);
        finish(
            new Response(JSON.stringify({ data: { id: 'synthetic-upload' } }), {
                status: 201,
            }),
        );
        await screen.findByText(
            'The upload was recorded, but this page could not refresh. Check the original upload to refresh it.',
        );
        const after = new CustomEvent('inertia:before', {
            cancelable: true,
            detail: {
                visit: { method: 'get', url: '/synthetic/elsewhere', only: [] },
            },
        });
        document.dispatchEvent(after);
        expect(after.defaultPrevented).toBe(false);
        fireEvent.click(screen.getByRole('button', { name: 'Check upload' }));
        await waitFor(() =>
            expect(screen.getByLabelText('Upload a file')).toBeEnabled(),
        );
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it.each(['expense', 'collaboration'])(
        'recovers a %s removal when only its parent version advances',
        async (kind) => {
            const local = {
                ...panel,
                files: panel.files.map((file) => ({
                    ...file,
                    delete_href: `/synthetic/${kind}/delete`,
                })),
            };
            respond({ message: 'Synthetic stale parent' }, 409);
            const view = render(<AttachmentSharingPanel panel={local} />);
            fireEvent.click(
                screen.getByRole('button', { name: 'Remove file' }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Confirm removal' }),
            );
            await screen.findByText('Synthetic stale parent');
            expect(
                screen.getByRole('button', { name: 'Confirm removal' }),
            ).toBeDisabled();
            expect(
                screen.getByRole('button', { name: 'Cancel' }),
            ).toBeDisabled();
            const first = vi.mocked(fetch).mock.calls[0][1]!;
            fireEvent.click(
                screen.getByRole('button', { name: 'Reload current files' }),
            );
            view.rerender(
                <AttachmentSharingPanel
                    panel={{
                        ...local,
                        files: local.files.map((file) => ({
                            ...file,
                            deletion: {
                                ...file.deletion!,
                                expected_parent_version: 'q'.repeat(64),
                            },
                        })),
                    }}
                />,
            );
            expect(
                screen.getByRole('button', { name: 'Confirm removal' }),
            ).toBeEnabled();
            expect(
                screen.getByRole('button', { name: 'Cancel' }),
            ).toBeEnabled();
            respond({ data: { status: 'deleted' } });
            fireEvent.click(
                screen.getByRole('button', { name: 'Confirm removal' }),
            );
            await waitFor(() =>
                expect(
                    screen.getByRole('button', { name: 'Remove file' }),
                ).toBeVisible(),
            );
            const second = vi.mocked(fetch).mock.calls[0][1]!;
            expect(JSON.parse(String(second.body))).toEqual({
                confirm: true,
                expected_version: 'v'.repeat(64),
                expected_parent_version: 'q'.repeat(64),
            });
            expect(second.headers).not.toEqual(first.headers);
        },
    );

    it.each([0, 408, 503])(
        'keeps preparation failure %s editable without an unknown multipart write',
        async (status) => {
            const prepare = vi.fn();

            if (status === 0) {
                prepare.mockRejectedValueOnce(
                    new Error('Synthetic preparation response lost'),
                );
            } else {
                prepare.mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            message: 'Synthetic preparation failure',
                        }),
                        { status },
                    ),
                );
            }

            prepare
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            data: {
                                upload_url:
                                    '/synthetic/signed-after-preparation',
                            },
                        }),
                    ),
                )
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            data: { id: 'synthetic-after-preparation' },
                        }),
                        { status: 201 },
                    ),
                );
            vi.stubGlobal('fetch', prepare);
            render(
                <AttachmentSharingPanel
                    panel={{
                        ...panel,
                        upload: {
                            href: '/synthetic/preparation',
                            method: 'GET',
                            parent_version: 'p'.repeat(64),
                        },
                    }}
                />,
            );
            await userEvent.upload(
                screen.getByLabelText('Upload a file'),
                new File(['Synthetic first choice'], 'synthetic-first.txt'),
            );
            fireEvent.submit(
                screen.getByLabelText('Upload a file').closest('form')!,
            );
            await screen.findByRole('alert');
            expect(fetch).toHaveBeenCalledTimes(1);
            expect(screen.getByLabelText('Upload a file')).toBeEnabled();
            expect(
                screen.queryByRole('button', { name: 'Check upload' }),
            ).not.toBeInTheDocument();
            const before = new CustomEvent('inertia:before', {
                cancelable: true,
                detail: {
                    visit: {
                        method: 'get',
                        url: '/synthetic/elsewhere',
                        only: [],
                    },
                },
            });
            document.dispatchEvent(before);
            expect(before.defaultPrevented).toBe(false);
            const unload = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(unload);
            expect(unload.defaultPrevented).toBe(false);
            const next = new File(
                ['Synthetic changed choice'],
                'synthetic-next.txt',
            );
            await userEvent.upload(
                screen.getByLabelText('Upload a file'),
                next,
            );
            fireEvent.submit(
                screen.getByLabelText('Upload a file').closest('form')!,
            );
            await waitFor(() =>
                expect(screen.getByLabelText('Upload a file')).toBeEnabled(),
            );
            expect(fetch).toHaveBeenCalledTimes(3);
            expect(
                (prepare.mock.calls[2][1]!.body as FormData).get('file'),
            ).toBe(next);
        },
    );

    it('keeps earlier multipart uncertainty through a later preparation failure', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            data: { upload_url: '/synthetic/original-signed' },
                        }),
                    ),
                )
                .mockRejectedValueOnce(
                    new Error('Synthetic lost multipart answer'),
                )
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            message: 'Synthetic later preparation failure',
                        }),
                        { status: 503 },
                    ),
                )
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            data: { upload_url: '/synthetic/renewed-signed' },
                        }),
                    ),
                )
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({ data: { id: 'synthetic-recovered' } }),
                        { status: 201 },
                    ),
                ),
        );
        render(
            <AttachmentSharingPanel
                panel={{
                    ...panel,
                    upload: {
                        href: '/synthetic/preparation',
                        method: 'GET',
                        parent_version: 'p'.repeat(64),
                    },
                }}
            />,
        );
        const file = new File(
            ['Synthetic retained multipart bytes'],
            'synthetic-retained.txt',
        );
        await userEvent.upload(screen.getByLabelText('Upload a file'), file);
        fireEvent.submit(
            screen.getByLabelText('Upload a file').closest('form')!,
        );
        await screen.findByRole('button', { name: 'Check upload' });
        const original = vi.mocked(fetch).mock.calls[1][1]!;
        fireEvent.click(screen.getByRole('button', { name: 'Check upload' }));
        await screen.findByText('Synthetic later preparation failure');
        expect(screen.getByLabelText('Upload a file')).toBeDisabled();
        const before = new CustomEvent('inertia:before', {
            cancelable: true,
            detail: {
                visit: { method: 'get', url: '/synthetic/elsewhere', only: [] },
            },
        });
        document.dispatchEvent(before);
        expect(before.defaultPrevented).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Check upload' }));
        await waitFor(() =>
            expect(screen.getByLabelText('Upload a file')).toBeEnabled(),
        );
        const replayed = vi.mocked(fetch).mock.calls[4][1]!;
        expect(replayed.headers).toEqual(original.headers);
        expect((replayed.body as FormData).get('file')).toBe(file);
        expect((replayed.body as FormData).get('expected_version')).toBe(
            'p'.repeat(64),
        );
    });

    it('replaces parent-derived sharing state and captured versions when the authorized seed changes', async () => {
        vi.stubGlobal('fetch', vi.fn());
        const { rerender } = render(<AttachmentSharingPanel panel={panel} />);
        const refreshed: AttachmentPanel = {
            ...panel,
            files: panel.files.map((file) => ({
                ...file,
                sharing: {
                    ...file.sharing!,
                    is_visible_to_client: true,
                    effective_visible_to_client: false,
                    sharing_note: 'Synthetic parent changed.',
                    parent_version: 'q'.repeat(64),
                },
            })),
        };
        rerender(<AttachmentSharingPanel panel={refreshed} />);
        expect(screen.getByLabelText('Visible to client')).toBeChecked();
        expect(
            screen.getByText('Synthetic parent changed.'),
        ).toBeInTheDocument();
        respond({
            data: {
                id: 'synthetic-file',
                ...refreshed.files[0].sharing,
                version: 'z'.repeat(64),
            },
        });
        fireEvent.click(screen.getByLabelText('Visible to client'));
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        expect(
            JSON.parse(String(vi.mocked(fetch).mock.calls[0][1]?.body))
                .expected_parent_version,
        ).toBe('q'.repeat(64));
    });

    it('ignores continuation results from a prior authorized seed', async () => {
        let finish: (response: Response) => void = () => {};
        vi.stubGlobal(
            'fetch',
            vi.fn().mockImplementation(
                () =>
                    new Promise<Response>((resolve) => {
                        finish = resolve;
                    }),
            ),
        );
        const { rerender } = render(<AttachmentSharingPanel panel={panel} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Load more files' }),
        );
        const refreshed: AttachmentPanel = {
            files: [],
            upload: null,
            next_href: null,
        };
        rerender(<AttachmentSharingPanel panel={refreshed} />);
        finish(
            new Response(
                JSON.stringify({
                    files: [
                        { ...panel.files[0], filename: 'Stale synthetic file' },
                    ],
                    upload: null,
                    next_href: '/stale',
                }),
            ),
        );
        await waitFor(() =>
            expect(screen.getByText('No files.')).toBeInTheDocument(),
        );
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Load more files' }),
        ).not.toBeInTheDocument();
    });
});
