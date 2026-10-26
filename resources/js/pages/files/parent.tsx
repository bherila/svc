import { Head } from '@inertiajs/react';
import AttachmentSharingPanel from '@/components/files/attachment-sharing-panel';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { AttachmentPanel } from '@/types/files';

export default function ParentFiles({
    attachment_panel,
    legacy_upload_href,
}: {
    attachment_panel: AttachmentPanel;
    legacy_upload_href?: string | null;
}) {
    return (
        <WorkspaceShell>
            <Head title="Files" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <h1 className="text-2xl font-semibold">Files</h1>
                <AttachmentSharingPanel
                    key={attachment_panel.parent_version}
                    panel={attachment_panel}
                    title="Attached files"
                    legacyForms={
                        legacy_upload_href === undefined
                            ? undefined
                            : { upload_href: legacy_upload_href }
                    }
                />
            </main>
        </WorkspaceShell>
    );
}
