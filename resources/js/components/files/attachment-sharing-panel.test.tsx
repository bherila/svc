import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AttachmentSharingPanel from '@/components/files/attachment-sharing-panel';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import type { AttachmentPanel } from '@/types/files';

const reload = vi.hoisted(() => vi.fn());
const legacy = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        router: { reload, delete: legacy.delete },
        useForm(initial: { file: File | null }) {
            const [data, setData] = useState(initial);

            return {
                data,
                errors: {},
                processing: false,
                setData: (field: string, value: unknown) =>
                    setData((prior) => ({ ...prior, [field]: value })),
                post: (href: string, options: object) =>
                    legacy.post(href, data, options),
                reset: () => setData(initial),
            };
        },
    };
});

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
        respond({ data: { id: 'synthetic-new' } });
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
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        const body = vi.mocked(fetch).mock.calls[0][1]?.body as FormData;
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

    it('retains legacy native form upload and confirmed deletion when sharing controls are unavailable', async () => {
        vi.stubGlobal('fetch', vi.fn());
        render(
            <AttachmentSharingPanel
                panel={{
                    ...panel,
                    files: panel.files.map((file) => ({
                        ...file,
                        sharing: null,
                    })),
                    upload: null,
                    next_href: null,
                }}
                legacyForms={{ upload_href: '/synthetic/legacy-upload' }}
            />,
        );
        const file = new File(['Synthetic bytes'], 'synthetic-legacy.txt');
        await userEvent.upload(screen.getByLabelText('Upload a file'), file);
        fireEvent.submit(
            screen.getByLabelText('Upload a file').closest('form')!,
        );
        expect(legacy.post).toHaveBeenCalledWith(
            '/synthetic/legacy-upload',
            { file },
            expect.objectContaining({ forceFormData: true }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Remove file' }));
        expect(legacy.delete).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm removal' }),
        );
        expect(legacy.delete).toHaveBeenCalledWith(
            '/synthetic/delete',
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(fetch).not.toHaveBeenCalled();
    });
});
