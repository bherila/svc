import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type {
    AttachmentPanel,
    AttachmentSharing,
    SharedAttachment,
} from '@/types/files';

function SharingControl({
    file,
    onChanged,
}: {
    file: SharedAttachment;
    onChanged: (sharing: AttachmentSharing) => void;
}) {
    const [sharing, setSharing] = useState(file.sharing);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const attempt = useRef<{ visible: boolean; key: string } | null>(null);

    if (!sharing) {
        return null;
    }

    async function change(visible: boolean) {
        if (!sharing) {
            return;
        }

        const current =
            attempt.current?.visible === visible
                ? attempt.current
                : { visible, key: newIdempotencyKey() };
        attempt.current = current;
        setBusy(true);
        const result = await apiRequest<
            Omit<AttachmentSharing, 'update_href'> & { id: string }
        >(
            'PATCH',
            sharing.update_href,
            {
                is_visible_to_client: visible,
                expected_version: sharing.version,
                expected_parent_version: sharing.parent_version,
            },
            current.key,
        );
        setBusy(false);

        if (result.ok) {
            const updated = {
                ...result.data,
                update_href: sharing.update_href,
            };
            setSharing(updated);
            onChanged(updated);
            attempt.current = null;
            setError(null);
        } else {
            setError(result.message);

            if (result.status !== 0 && result.status < 500) {
                attempt.current = null;
            }
        }
    }

    return (
        <div className="grid min-w-0 grid-cols-1 gap-1 text-sm">
            <label className="flex min-w-0 items-center gap-2">
                <input
                    type="checkbox"
                    checked={sharing.is_visible_to_client}
                    disabled={busy}
                    onChange={(event) => void change(event.target.checked)}
                />
                Visible to client
            </label>
            {sharing.is_visible_to_client &&
                !sharing.effective_visible_to_client && (
                    <p className="wrap-anywhere text-muted-foreground">
                        {sharing.sharing_note}
                    </p>
                )}
            {error && (
                <p role="alert" className="wrap-anywhere text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

function RemoveAttachment({ file }: { file: SharedAttachment }) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const attempt = useRef<string | null>(null);

    if (!file.delete_href || !file.sharing) {
        return null;
    }

    async function remove() {
        if (!file.delete_href || !file.sharing) {
            return;
        }

        attempt.current ??= newIdempotencyKey();
        setBusy(true);
        const result = await apiRequest(
            'DELETE',
            file.delete_href,
            {
                confirm: true,
                expected_version: file.sharing.version,
                expected_parent_version: file.sharing.parent_version,
            },
            attempt.current,
        );
        setBusy(false);

        if (result.ok) {
            router.reload({ preserveScroll: true });
        } else {
            setError(result.message);

            if (result.status !== 0 && result.status < 500) {
                attempt.current = null;
            }
        }
    }

    return (
        <div className="grid min-w-0 grid-cols-1 gap-2">
            {confirming ? (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm">Remove this file?</span>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={busy}
                        onClick={() => void remove()}
                    >
                        Confirm removal
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        onClick={() => setConfirming(false)}
                    >
                        Cancel
                    </Button>
                </div>
            ) : (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => setConfirming(true)}
                >
                    Remove file
                </Button>
            )}
            {error && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {error}
                </p>
            )}
        </div>
    );
}

function UploadAttachment({
    upload,
}: {
    upload: NonNullable<AttachmentPanel['upload']>;
}) {
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const attempt = useRef<string | null>(null);

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-2"
            onSubmit={async (event) => {
                event.preventDefault();

                if (!file) {
                    return;
                }

                if (file.size > 52428800) {
                    setError('Choose a file up to 50 MiB.');

                    return;
                }

                attempt.current ??= newIdempotencyKey();
                const body = new FormData();
                body.set('file', file);
                body.set('expected_version', upload.parent_version);
                setBusy(true);
                const result = await apiRequest(
                    'POST',
                    upload.href,
                    body,
                    attempt.current,
                );
                setBusy(false);

                if (result.ok) {
                    router.reload({ preserveScroll: true });
                } else {
                    setError(result.message);

                    if (result.status !== 0 && result.status < 500) {
                        attempt.current = null;
                    }
                }
            }}
        >
            <label className="grid min-w-0 grid-cols-1 gap-1 text-sm">
                Upload a file
                <input
                    type="file"
                    className="w-full min-w-0 rounded-md border p-2 text-sm"
                    disabled={busy}
                    required
                    onChange={(event) => {
                        setFile(event.target.files?.[0] ?? null);
                        attempt.current = null;
                        setError(null);
                    }}
                />
            </label>
            <p className="text-sm text-muted-foreground">
                New files are private. Choose “Visible to client” after
                uploading to share a file.
            </p>
            <div>
                <Button type="submit" disabled={busy || file === null}>
                    Upload file
                </Button>
            </div>
            {error && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {error}
                </p>
            )}
        </form>
    );
}

/** Receives initial authorized data from the page; only explicit continuation fetches. */
export default function AttachmentSharingPanel({
    panel,
    title = 'Files',
}: {
    panel: AttachmentPanel;
    title?: string;
}) {
    const [pages, setPages] = useState({
        source: panel,
        files: panel.files,
        next: panel.next_href,
    });

    // Inertia refreshes supply a new authorized seed after upload/delete or parent changes.
    if (pages.source !== panel) {
        setPages({ source: panel, files: panel.files, next: panel.next_href });
    }

    const { files, next } = pages;
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    return (
        <section aria-label={title} className="grid min-w-0 grid-cols-1 gap-4">
            <h2 className="text-lg font-semibold wrap-anywhere">{title}</h2>
            {files.length === 0 && (
                <p className="text-sm text-muted-foreground">No files.</p>
            )}
            <ul className="grid min-w-0 grid-cols-1 gap-3">
                {files.map((file) => (
                    <li
                        key={file.id}
                        className="grid min-w-0 grid-cols-1 gap-3 rounded-lg border p-3"
                    >
                        <a
                            className="min-w-0 text-sm wrap-anywhere underline"
                            href={file.download_href}
                        >
                            {file.filename}
                        </a>
                        <p className="text-sm text-muted-foreground">
                            {file.bytes.toLocaleString()} bytes
                        </p>
                        <SharingControl
                            key={file.sharing?.version ?? 'read-only'}
                            file={file}
                            onChanged={(sharing) => {
                                setPages((current) => ({
                                    ...current,
                                    files: current.files.map((row) =>
                                        row.id === file.id
                                            ? { ...row, sharing }
                                            : row,
                                    ),
                                }));
                            }}
                        />
                        <RemoveAttachment file={file} />
                    </li>
                ))}
            </ul>
            {panel.upload && <UploadAttachment upload={panel.upload} />}
            {next && (
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        onClick={async () => {
                            setBusy(true);
                            const result = await apiRequest<AttachmentPanel>(
                                'GET',
                                next,
                                {},
                            );
                            setBusy(false);

                            if (result.ok) {
                                setPages((current) => ({
                                    ...current,
                                    files: [
                                        ...current.files,
                                        ...result.data.files.filter(
                                            (file) =>
                                                !current.files.some(
                                                    (existing) =>
                                                        existing.id === file.id,
                                                ),
                                        ),
                                    ],
                                    next: result.data.next_href,
                                }));
                                setError(null);
                            } else {
                                setError(result.message);
                            }
                        }}
                    >
                        Load more files
                    </Button>
                </div>
            )}
            {error && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {error}
                </p>
            )}
        </section>
    );
}
