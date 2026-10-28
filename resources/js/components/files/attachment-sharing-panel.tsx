import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import { refreshPageProps } from '@/lib/refresh-page-props';
import type {
    AttachmentPanel,
    AttachmentSharing,
    SharedAttachment,
} from '@/types/files';

function MutationError({
    message,
    stale,
}: {
    message: string;
    stale: boolean;
}) {
    return (
        <div className="grid min-w-0 grid-cols-1 gap-2">
            <p role="alert" className="text-sm wrap-anywhere text-destructive">
                {message}
            </p>
            {stale && (
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => router.reload()}
                    >
                        Reload current files
                    </Button>
                </div>
            )}
        </div>
    );
}

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
    const [stale, setStale] = useState(false);
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
            setStale(result.status === 409);

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
                    disabled={busy || stale}
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
            {error && <MutationError message={error} stale={stale} />}
        </div>
    );
}

function RemoveAttachment({ file }: { file: SharedAttachment }) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [stale, setStale] = useState(false);
    const attempt = useRef<string | null>(null);

    if (!file.delete_href || !file.deletion) {
        return null;
    }

    async function remove() {
        if (!file.delete_href) {
            return;
        }

        if (!file.deletion) {
            return;
        }

        attempt.current ??= newIdempotencyKey();
        setBusy(true);
        const result = await apiRequest(
            'DELETE',
            file.delete_href,
            {
                confirm: true,
                ...file.deletion,
            },
            attempt.current,
        );
        setBusy(false);

        if (result.ok) {
            router.reload();
        } else {
            setError(result.message);
            setStale(result.status === 409);

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
                        disabled={busy || stale}
                        onClick={() => void remove()}
                    >
                        Confirm removal
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy || stale}
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
            {error && <MutationError message={error} stale={stale} />}
        </div>
    );
}

export function UploadAttachment({
    upload,
}: {
    upload: NonNullable<AttachmentPanel['upload']>;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [stale, setStale] = useState(false);
    const [unresolved, setUnresolved] = useState(false);
    const attempt = useRef<{
        file: File;
        parentVersion: string;
        href: string;
        key: string;
    } | null>(null);

    async function send() {
        if (busy || !file) {
            return;
        }

        if (file.size > 52428800) {
            setError('Choose a file up to 50 MiB.');

            return;
        }

        attempt.current ??= {
            file,
            parentVersion: upload.parent_version,
            href: upload.href,
            key: newIdempotencyKey(),
        };
        const original = attempt.current;
        setBusy(true);
        setError(null);
        const prepared = await apiRequest<{
            url?: string;
            upload_url?: string;
        }>('POST', original.href, {});
        let result;

        if (!prepared.ok) {
            result = prepared;
        } else {
            const destination = prepared.data?.url ?? prepared.data?.upload_url;

            if (typeof destination !== 'string' || destination === '') {
                result = {
                    ok: false as const,
                    status: 0,
                    message:
                        'The upload destination could not be read. Retry the original upload.',
                };
            } else {
                const body = new FormData();
                body.set('file', original.file);
                body.set('expected_version', original.parentVersion);
                result = await apiRequest(
                    'POST',
                    destination,
                    body,
                    original.key,
                );
            }
        }

        if (result.ok) {
            try {
                await refreshPageProps({ requireSuccess: true });
                attempt.current = null;
                setFile(null);

                if (input.current) {
                    input.current.value = '';
                }

                setUnresolved(false);
            } catch {
                setUnresolved(true);
                setError(
                    'The upload was recorded, but this page could not refresh. Check the original upload to refresh it.',
                );
            }
        } else {
            setError(result.message);
            setStale(result.status === 409);
            const uncertain =
                result.status === 0 ||
                result.status === 408 ||
                result.status >= 500;
            setUnresolved(uncertain);

            if (!uncertain) {
                attempt.current = null;
            }
        }

        setBusy(false);
    }

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                void send();
            }}
        >
            <label className="grid min-w-0 grid-cols-1 gap-1 text-sm">
                Upload a file
                <input
                    ref={input}
                    type="file"
                    className="w-full min-w-0 rounded-md border p-2 text-sm"
                    disabled={busy || stale || unresolved}
                    required
                    onChange={(event) => {
                        setFile(event.target.files?.[0] ?? null);
                        attempt.current = null;
                        setError(null);
                    }}
                />
            </label>
            <p className="text-sm text-muted-foreground">
                {upload.description ??
                    'New files are private. Choose “Visible to client” after uploading to share a file.'}
            </p>
            <div>
                <Button type="submit" disabled={busy || stale || file === null}>
                    {unresolved ? 'Check upload' : 'Upload file'}
                </Button>
            </div>
            {error && <MutationError message={error} stale={stale} />}
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
        busy: false,
        error: null as string | null,
    });

    // Inertia refreshes supply a new authorized seed after upload/delete or parent changes.
    if (pages.source !== panel) {
        setPages({
            source: panel,
            files: panel.files,
            next: panel.next_href,
            busy: false,
            error: null,
        });
    }

    const { files, next, busy, error } = pages;

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
                            key={`sharing:${JSON.stringify(file.sharing)}`}
                            file={file}
                            onChanged={(sharing) => {
                                setPages((current) =>
                                    current.source !== panel
                                        ? current
                                        : {
                                              ...current,
                                              files: current.files.map((row) =>
                                                  row.id === file.id
                                                      ? {
                                                            ...row,
                                                            sharing,
                                                            deletion:
                                                                row.deletion
                                                                    ? {
                                                                          ...row.deletion,
                                                                          expected_version:
                                                                              sharing.version,
                                                                          ...(row
                                                                              .deletion
                                                                              .expected_parent_version ===
                                                                          undefined
                                                                              ? {}
                                                                              : {
                                                                                    expected_parent_version:
                                                                                        sharing.parent_version,
                                                                                }),
                                                                      }
                                                                    : null,
                                                        }
                                                      : row,
                                              ),
                                          },
                                );
                            }}
                        />
                        <RemoveAttachment
                            key={`remove:${JSON.stringify(file.deletion)}`}
                            file={file}
                        />
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
                            setPages((current) => ({ ...current, busy: true }));
                            const result = await apiRequest<AttachmentPanel>(
                                'GET',
                                next,
                                {},
                            );
                            setPages((current) => {
                                if (current.source !== panel) {
                                    return current;
                                }

                                if (!result.ok) {
                                    return {
                                        ...current,
                                        busy: false,
                                        error: result.message,
                                    };
                                }

                                return {
                                    ...current,
                                    busy: false,
                                    error: null,
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
                                };
                            });
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
