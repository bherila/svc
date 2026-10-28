import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useUnknownWriteGuard } from '@/hooks/use-unknown-write-guard';
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
    const [state, setState] = useState({
        source: file.sharing,
        sharing: file.sharing,
    });
    const [busy, setBusy] = useState(false);
    const running = useRef(false);
    const [error, setError] = useState<string | null>(null);
    const [stale, setStale] = useState(false);
    const [unresolved, setUnresolved] = useState(false);
    const attempt = useRef<{
        href: string;
        body: {
            is_visible_to_client: boolean;
            expected_version: string;
            expected_parent_version: string;
        };
        key: string;
        uncertain: boolean;
    } | null>(null);
    useUnknownWriteGuard(
        () => attempt.current !== null,
        () =>
            setError(
                'Check the original sharing change before leaving this page.',
            ),
    );

    if (state.source !== file.sharing && !busy && !unresolved) {
        setState({ source: file.sharing, sharing: file.sharing });
        setStale(false);
    }

    const sharing = state.sharing;

    if (!sharing) {
        return null;
    }

    async function change(visible: boolean) {
        if (running.current || !sharing) {
            return;
        }

        running.current = true;
        attempt.current ??= {
            href: sharing.update_href,
            body: {
                is_visible_to_client: visible,
                expected_version: sharing.version,
                expected_parent_version: sharing.parent_version,
            },
            key: newIdempotencyKey(),
            uncertain: false,
        };
        const original = attempt.current;
        setBusy(true);
        setError(null);
        const result = await apiRequest<
            Omit<AttachmentSharing, 'update_href'> & { id: string }
        >('PATCH', original.href, original.body, original.key);

        if (result.ok) {
            const updated = { ...result.data, update_href: original.href };
            attempt.current = null;
            setUnresolved(false);
            setStale(false);
            setState({ source: file.sharing, sharing: updated });
            onChanged(updated);
        } else {
            original.uncertain ||=
                result.status === 0 ||
                result.status === 408 ||
                result.status >= 500;
            setError(result.message);
            setUnresolved(original.uncertain);
            setStale(!original.uncertain && result.status === 409);

            if (!original.uncertain) {
                attempt.current = null;
            }
        }

        running.current = false;
        setBusy(false);
    }

    return (
        <div className="grid min-w-0 grid-cols-1 gap-1 text-sm">
            <label className="flex min-w-0 items-center gap-2">
                <input
                    type="checkbox"
                    checked={sharing.is_visible_to_client}
                    disabled={busy || stale || unresolved}
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
            {unresolved && (
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        onClick={() =>
                            void change(sharing.is_visible_to_client)
                        }
                    >
                        Check sharing
                    </Button>
                </div>
            )}
            {error && <MutationError message={error} stale={stale} />}
        </div>
    );
}

function RemoveAttachment({ file }: { file: SharedAttachment }) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const running = useRef(false);
    const [error, setError] = useState<string | null>(null);
    const [staleVersions, setStaleVersions] = useState<{
        attachment: string;
        parent?: string;
    } | null>(null);
    const [unresolved, setUnresolved] = useState(false);
    const [accepted, setAccepted] = useState(false);
    const attempt = useRef<{
        href: string;
        body: {
            confirm: true;
            expected_version: string;
            expected_parent_version?: string;
        };
        key: string;
        accepted: boolean;
        uncertain: boolean;
    } | null>(null);
    useUnknownWriteGuard(
        () => attempt.current !== null && !attempt.current.accepted,
        () =>
            setError(
                'Check the original file change before leaving this page.',
            ),
    );
    const stale =
        staleVersions !== null &&
        staleVersions.attachment === file.deletion?.expected_version &&
        staleVersions.parent === file.deletion?.expected_parent_version;

    if ((!file.delete_href || !file.deletion) && !busy && !unresolved) {
        return null;
    }

    async function remove() {
        if (
            running.current ||
            (!attempt.current && (!file.delete_href || !file.deletion))
        ) {
            return;
        }

        running.current = true;
        attempt.current ??= {
            href: file.delete_href!,
            body: { confirm: true, ...file.deletion! },
            key: newIdempotencyKey(),
            accepted: false,
            uncertain: false,
        };
        const original = attempt.current;
        setBusy(true);
        setError(null);

        if (!original.accepted) {
            const result = await apiRequest(
                'DELETE',
                original.href,
                original.body,
                original.key,
            );

            if (!result.ok) {
                original.uncertain ||=
                    result.status === 0 ||
                    result.status === 408 ||
                    result.status >= 500;
                setError(result.message);
                setUnresolved(original.uncertain);
                setStaleVersions(
                    !original.uncertain && result.status === 409
                        ? {
                              attachment: original.body.expected_version,
                              parent: original.body.expected_parent_version,
                          }
                        : null,
                );

                if (!original.uncertain) {
                    attempt.current = null;
                }

                running.current = false;
                setBusy(false);

                return;
            }

            original.accepted = true;
            setAccepted(true);
            setUnresolved(true);
        }

        try {
            await refreshPageProps({
                only: [
                    'files',
                    'files_next_href',
                    'files_previous_href',
                    'upload',
                    'attachment_upload',
                    'attachment_panel',
                    'clients',
                    'topic',
                    'thread',
                    'agreement',
                    'proposal',
                    'invoice',
                    'expense',
                ],
                requireSuccess: true,
            });
            attempt.current = null;
            setUnresolved(false);
            setAccepted(false);
            setConfirming(false);
        } catch {
            setError(
                'The file was removed, but this page could not refresh. Refresh the removed file before another removal.',
            );
        } finally {
            running.current = false;
            setBusy(false);
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
                        {accepted
                            ? 'Refresh removed file'
                            : unresolved
                              ? 'Check removal'
                              : 'Confirm removal'}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy || stale || unresolved}
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
    const [staleVersion, setStaleVersion] = useState<string | null>(null);
    const stale = staleVersion === upload.parent_version;
    const [unresolved, setUnresolved] = useState(false);
    const attempt = useRef<{
        file: File;
        parentVersion: string;
        method: 'GET' | 'POST';
        href: string;
        key: string;
        accepted: boolean;
        uncertain: boolean;
    } | null>(null);

    const running = useRef(false);
    useUnknownWriteGuard(
        () => attempt.current !== null && !attempt.current.accepted,
        () =>
            setError(
                'Check the original file change before leaving this page.',
            ),
    );

    async function refreshRecorded(original: { uncertain: boolean }) {
        try {
            await refreshPageProps({
                only: [
                    'files',
                    'files_next_href',
                    'files_previous_href',
                    'upload',
                    'attachment_upload',
                    'attachment_panel',
                    'clients',
                    'topic',
                    'thread',
                    'agreement',
                    'proposal',
                    'invoice',
                    'expense',
                ],
                requireSuccess: true,
            });
            attempt.current = null;
            setFile(null);

            if (input.current) {
                input.current.value = '';
            }

            setUnresolved(false);
        } catch {
            original.uncertain = true;
            setUnresolved(true);
            setError(
                'The upload was recorded, but this page could not refresh. Check the original upload to refresh it.',
            );
        }
    }

    async function send() {
        if (running.current || !file) {
            return;
        }

        if (file.size > 52428800) {
            setError('Choose a file up to 50 MiB.');

            return;
        }

        attempt.current ??= {
            file,
            parentVersion: upload.parent_version,
            method: upload.method ?? 'POST',
            href: upload.href,
            key: newIdempotencyKey(),
            accepted: false,
            uncertain: false,
        };
        const original = attempt.current;
        running.current = true;
        setBusy(true);
        setError(null);

        if (original.accepted) {
            await refreshRecorded(original);
            running.current = false;
            setBusy(false);

            return;
        }

        const prepared = await apiRequest<{
            url?: string;
            upload_url?: string;
        }>(original.method, original.href, {});
        let result;
        let attemptedUpload = false;

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
                attemptedUpload = true;
                result = await apiRequest(
                    'POST',
                    destination,
                    body,
                    original.key,
                );
            }
        }

        if (result.ok) {
            original.accepted = true;
            await refreshRecorded(original);
        } else {
            setError(result.message);
            const uncertain =
                original.uncertain ||
                (attemptedUpload &&
                    (result.status === 0 ||
                        result.status === 408 ||
                        result.status >= 500));
            original.uncertain = uncertain;
            setStaleVersion(
                !uncertain && result.status === 409
                    ? original.parentVersion
                    : null,
            );
            setUnresolved(uncertain);

            if (!uncertain) {
                attempt.current = null;
            }
        }

        running.current = false;
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
            {error && (staleVersion === null || stale) && (
                <MutationError message={error} stale={stale} />
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
                            key={`sharing:${file.id}`}
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
                            key={`remove:${file.id}`}
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
