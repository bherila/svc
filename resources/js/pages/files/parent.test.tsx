import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import ParentFiles from '@/pages/files/parent';
import type { AttachmentPanel } from '@/types/files';

const reload = vi.hoisted(() =>
    vi.fn((options?: { onSuccess?: () => void; onFinish?: () => void }) => {
        options?.onSuccess?.();
        options?.onFinish?.();
    }),
);
const currentPage = vi.hoisted(() => ({ url: '/synthetic/first-resource' }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => currentPage,
    Head: () => null,
    router: { reload },
}));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));

afterEach(() => {
    vi.unstubAllGlobals();
    currentPage.url = '/synthetic/first-resource';
});

it('keeps an uncertain upload mounted across parent revisions and resets it when the resource changes', async () => {
    const fetchMock = vi
        .fn<typeof fetch>()
        .mockResolvedValueOnce(
            new Response(
                JSON.stringify({ data: { url: '/synthetic/signed-first' } }),
            ),
        )
        .mockRejectedValueOnce(new Error('Synthetic lost multipart reply'))
        .mockResolvedValueOnce(
            new Response(
                JSON.stringify({ data: { url: '/synthetic/signed-replay' } }),
            ),
        )
        .mockRejectedValueOnce(new Error('Synthetic replay reply also lost'))
        .mockResolvedValueOnce(
            new Response(
                JSON.stringify({ data: { url: '/synthetic/signed-other' } }),
            ),
        )
        .mockResolvedValueOnce(
            new Response(
                JSON.stringify({ data: { id: 'synthetic-new-file' } }),
            ),
        );
    vi.stubGlobal('fetch', fetchMock);
    reload.mockClear();
    const panel: AttachmentPanel = {
        files: [],
        next_href: null,
        parent_version: 'p'.repeat(64),
        upload: {
            href: '/synthetic/first-resource/upload',
            parent_version: 'p'.repeat(64),
        },
    };
    const { rerender } = render(<ParentFiles attachment_panel={panel} />);
    const input = screen.getByLabelText('Upload a file') as HTMLInputElement;
    const file = new File(
        ['Synthetic original file bytes'],
        'synthetic-original.txt',
    );
    await userEvent.upload(input, file);
    fireEvent.submit(input.closest('form')!);
    await screen.findByRole('button', { name: 'Check upload' });
    expect(input).toBeDisabled();
    const original = fetchMock.mock.calls[1][1]!;
    const originalKey = (original.headers as Record<string, string>)[
        'Idempotency-Key'
    ];
    expect(originalKey).toEqual(expect.any(String));
    expect((original.body as FormData).get('file')).toBe(file);
    expect((original.body as FormData).get('expected_version')).toBe(
        'p'.repeat(64),
    );

    rerender(
        <ParentFiles
            attachment_panel={{
                ...panel,
                parent_version: 'q'.repeat(64),
                upload: {
                    href: '/synthetic/first-resource/upload',
                    parent_version: 'q'.repeat(64),
                },
            }}
        />,
    );
    expect(screen.getByLabelText('Upload a file')).toBe(input);
    expect(input).toBeDisabled();
    expect(input.files?.[0]).toBe(file);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    fireEvent.click(screen.getByRole('button', { name: 'Check upload' }));
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(4));
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Check upload' }),
        ).toBeEnabled(),
    );
    expect(fetchMock.mock.calls[2]).toEqual([
        '/synthetic/first-resource/upload',
        expect.objectContaining({ method: 'POST' }),
    ]);
    expect(fetchMock.mock.calls[3][0]).toBe('/synthetic/signed-replay');
    const replay = fetchMock.mock.calls[3][1]!;
    expect((replay.body as FormData).get('file')).toBe(file);
    expect((replay.body as FormData).get('expected_version')).toBe(
        'p'.repeat(64),
    );
    expect((replay.headers as Record<string, string>)['Idempotency-Key']).toBe(
        originalKey,
    );
    expect(input).toBeDisabled();
    expect(reload).not.toHaveBeenCalled();

    currentPage.url = '/synthetic/other-resource';
    rerender(
        <ParentFiles
            attachment_panel={{
                files: [],
                next_href: null,
                parent_version: 'r'.repeat(64),
                upload: {
                    href: '/synthetic/other-resource/upload',
                    parent_version: 'r'.repeat(64),
                },
            }}
        />,
    );
    const otherInput = screen.getByLabelText(
        'Upload a file',
    ) as HTMLInputElement;
    expect(otherInput).not.toBe(input);
    expect(otherInput).toBeEnabled();
    expect(otherInput.files).toHaveLength(0);
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Check upload' }),
    ).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Upload file' })).toBeDisabled();
    const otherFile = new File(
        ['Synthetic other bytes'],
        'synthetic-other.txt',
    );
    await userEvent.upload(otherInput, otherFile);
    fireEvent.submit(otherInput.closest('form')!);
    await waitFor(() => expect(reload).toHaveBeenCalledTimes(1));
    expect(fetchMock.mock.calls[4][0]).toBe('/synthetic/other-resource/upload');
    const next = fetchMock.mock.calls[5][1]!;
    expect((next.body as FormData).get('file')).toBe(otherFile);
    expect((next.body as FormData).get('expected_version')).toBe(
        'r'.repeat(64),
    );
    expect(
        (next.headers as Record<string, string>)['Idempotency-Key'],
    ).not.toBe(originalKey);
});

it('retains an unknown upload while its capability disappears, then checks the same file/version/key when authority returns', async () => {
    const mocked = vi
        .fn<typeof fetch>()
        .mockResolvedValueOnce(
            new Response(
                JSON.stringify({ data: { url: '/synthetic/original-signed' } }),
            ),
        )
        .mockRejectedValueOnce(new Error('Synthetic original reply lost'))
        .mockResolvedValueOnce(
            new Response(
                JSON.stringify({
                    message: 'Synthetic current permission refusal',
                }),
                { status: 403 },
            ),
        )
        .mockResolvedValueOnce(
            new Response(
                JSON.stringify({ data: { url: '/synthetic/restored-signed' } }),
            ),
        )
        .mockResolvedValueOnce(
            new Response(
                JSON.stringify({ data: { id: 'synthetic-original' } }),
                { status: 201 },
            ),
        );
    vi.stubGlobal('fetch', mocked);
    const panel: AttachmentPanel = {
        files: [],
        next_href: null,
        upload: {
            href: '/synthetic/original-preparation',
            parent_version: 'p'.repeat(64),
        },
    };
    const view = render(<ParentFiles attachment_panel={panel} />);
    const input = screen.getByLabelText('Upload a file') as HTMLInputElement;
    const file = new File(
        ['Synthetic capability recovery bytes'],
        'synthetic-capability.txt',
    );
    await userEvent.upload(input, file);
    fireEvent.submit(input.closest('form')!);
    await screen.findByRole('button', { name: 'Check upload' });
    const original = mocked.mock.calls[1][1]!;
    view.rerender(
        <ParentFiles attachment_panel={{ ...panel, upload: null }} />,
    );
    expect(screen.getByLabelText('Upload a file')).toBe(input);
    expect(input).toBeDisabled();
    expect(input.files?.[0]).toBe(file);
    fireEvent.click(screen.getByRole('button', { name: 'Check upload' }));
    await screen.findByText('Synthetic current permission refusal');
    expect(mocked.mock.calls[2][0]).toBe('/synthetic/original-preparation');
    expect(input).toBeDisabled();
    view.rerender(
        <ParentFiles
            attachment_panel={{
                ...panel,
                upload: { ...panel.upload!, parent_version: 'q'.repeat(64) },
            }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Check upload' }));
    await waitFor(() => expect(input).toBeEnabled());
    expect(mocked).toHaveBeenCalledTimes(5);
    const checked = mocked.mock.calls[4][1]!;
    expect(checked.headers).toEqual(original.headers);
    expect((checked.body as FormData).get('file')).toBe(file);
    expect((checked.body as FormData).get('expected_version')).toBe(
        'p'.repeat(64),
    );
    expect(input.files).toHaveLength(0);
    view.rerender(
        <ParentFiles attachment_panel={{ ...panel, upload: null }} />,
    );
    expect(screen.queryByLabelText('Upload a file')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Upload file' }),
    ).not.toBeInTheDocument();
});

it('retains only an accepted refresh receipt after upload capability is removed', async () => {
    const mocked = vi
        .fn<typeof fetch>()
        .mockResolvedValueOnce(
            new Response(
                JSON.stringify({ data: { url: '/synthetic/signed' } }),
            ),
        )
        .mockResolvedValueOnce(
            new Response(
                JSON.stringify({ data: { id: 'synthetic-accepted' } }),
                { status: 201 },
            ),
        );
    vi.stubGlobal('fetch', mocked);
    reload.mockImplementationOnce((options) => {
        options?.onFinish?.();
    });
    const panel: AttachmentPanel = {
        files: [],
        next_href: null,
        upload: {
            href: '/synthetic/preparation',
            parent_version: 'p'.repeat(64),
        },
    };
    const view = render(<ParentFiles attachment_panel={panel} />);
    const input = screen.getByLabelText('Upload a file') as HTMLInputElement;
    await userEvent.upload(
        input,
        new File(['Synthetic accepted bytes'], 'synthetic-accepted.txt'),
    );
    fireEvent.submit(input.closest('form')!);
    await screen.findByText(
        'The upload was recorded, but this page could not refresh. Check the original upload to refresh it.',
    );
    view.rerender(
        <ParentFiles attachment_panel={{ ...panel, upload: null }} />,
    );
    expect(screen.getByLabelText('Upload a file')).toBe(input);
    expect(input).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Check upload' }));
    await waitFor(() =>
        expect(
            screen.queryByLabelText('Upload a file'),
        ).not.toBeInTheDocument(),
    );
    expect(mocked).toHaveBeenCalledTimes(2);
});
