import { useState } from 'react';
import { useCollaborationMutation } from '@/components/collaboration/use-collaboration-mutation';
import { Button } from '@/components/ui/button';
import { useVersionedDraft } from '@/hooks/use-versioned-draft';
import type { CollaborationContent } from '@/types/collaboration';

export function CollaborationErrors({
    errors,
}: {
    errors: Record<string, string | undefined>;
}) {
    const messages = Object.values(errors).filter(Boolean);

    return messages.length ? (
        <div
            role="alert"
            className="grid min-w-0 grid-cols-1 gap-1 text-sm wrap-anywhere text-destructive"
        >
            {messages.map((message, index) => (
                <p key={index}>{message}</p>
            ))}
        </div>
    ) : null;
}

export function VisibilityControl({
    content,
}: {
    content: CollaborationContent;
}) {
    const form = useVersionedDraft(
        { is_visible_to_client: content.is_visible_to_client ?? false },
        content.version,
        false,
    );
    const mutation = useCollaborationMutation();

    if (!content.visibility_update_href) {
        return null;
    }

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                const submitted = { ...form.data };
                void mutation.run(
                    'PATCH',
                    content.visibility_update_href!,
                    { ...submitted, expected_version: form.version },
                    (saved) => form.saved(submitted, saved.version),
                );
            }}
        >
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    disabled={mutation.locked}
                    checked={form.data.is_visible_to_client}
                    onChange={(event) =>
                        form.setData(
                            'is_visible_to_client',
                            event.target.checked,
                        )
                    }
                />
                Visible to client
            </label>
            {content.is_visible_to_client &&
                !content.effective_visible_to_client && (
                    <p className="text-sm wrap-anywhere text-muted-foreground">
                        Hidden from clients because a parent is private.
                    </p>
                )}
            <CollaborationErrors errors={mutation.errors} />
            <Button
                type="submit"
                variant="outline"
                className="justify-self-start"
                disabled={mutation.pending}
            >
                {mutation.unresolved ? 'Check original change' : 'Save sharing'}
            </Button>
        </form>
    );
}

export function RemoveContent({ content }: { content: CollaborationContent }) {
    const [confirmedVersion, setConfirmedVersion] = useState<string | null>(
        null,
    );
    const mutation = useCollaborationMutation();

    if (!content.delete_href) {
        return null;
    }

    if (confirmedVersion === null) {
        return (
            <Button
                type="button"
                variant="outline"
                onClick={() => setConfirmedVersion(content.version)}
            >
                Remove
            </Button>
        );
    }

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                void mutation.run('DELETE', content.delete_href!, {
                    expected_version: confirmedVersion,
                    confirm: true,
                });
            }}
        >
            <p className="text-sm wrap-anywhere">
                Remove this content? Its text will no longer be shown.
            </p>
            <CollaborationErrors errors={mutation.errors} />
            <div className="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    variant="destructive"
                    disabled={mutation.pending}
                >
                    {mutation.unresolved
                        ? 'Check original change'
                        : 'Confirm removal'}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    disabled={mutation.locked}
                    onClick={() => setConfirmedVersion(null)}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}
