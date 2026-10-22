import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
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
    const form = useForm({
        expected_version: content.version,
        is_visible_to_client: content.is_visible_to_client ?? false,
    });

    if (!content.visibility_update_href) {
        return null;
    }

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.patch(content.visibility_update_href!, {
                    preserveScroll: true,
                });
            }}
        >
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
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
            <CollaborationErrors errors={form.errors} />
            <Button
                type="submit"
                variant="outline"
                className="justify-self-start"
                disabled={form.processing}
            >
                Save sharing
            </Button>
        </form>
    );
}

export function RemoveContent({ content }: { content: CollaborationContent }) {
    const [confirming, setConfirming] = useState(false);
    const form = useForm({ expected_version: content.version });

    if (!content.delete_href) {
        return null;
    }

    if (!confirming) {
        return (
            <Button
                type="button"
                variant="outline"
                onClick={() => setConfirming(true)}
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
                form.delete(content.delete_href!, { preserveScroll: true });
            }}
        >
            <p className="text-sm wrap-anywhere">
                Remove this content? Its text will no longer be shown.
            </p>
            <CollaborationErrors errors={form.errors} />
            <div className="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    variant="destructive"
                    disabled={form.processing}
                >
                    Confirm removal
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => setConfirming(false)}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}
