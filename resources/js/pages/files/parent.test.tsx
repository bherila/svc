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
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { reload },
}));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));

afterEach(() => vi.unstubAllGlobals());

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
