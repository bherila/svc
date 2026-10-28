import { Head } from '@inertiajs/react';
import { AttachmentPageLinks } from '@/components/files/attachment-page-links';
import AttachmentSharingPanel from '@/components/files/attachment-sharing-panel';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatDay } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { AttachmentPanel, SharedAttachment } from '@/types/files';

type Props = {
    expense: {
        description: string;
        spent_on: string;
        amount: number;
        currency: string;
        status: string;
    };
    files: SharedAttachment[];
    files_next_href?: string | null;
    files_previous_href?: string | null;
    upload: NonNullable<AttachmentPanel['upload']>;
};

export default function ExpenseReceipts({
    expense,
    files,
    files_next_href: nextFilesHref = null,
    files_previous_href: previousFilesHref = null,
    upload,
}: Props) {
    return (
        <WorkspaceShell activeModule="expenses">
            <Head title="Expense receipts" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <Card>
                    <CardHeader>
                        <CardTitle id="files">Expense receipts</CardTitle>
                    </CardHeader>
                    <CardContent className="grid min-w-0 grid-cols-1 gap-4">
                        <p className="wrap-anywhere">{expense.description}</p>
                        <p className="text-sm wrap-anywhere text-muted-foreground">
                            {formatDay(expense.spent_on)} ·{' '}
                            {formatMoney(expense.amount, expense.currency)} ·{' '}
                            {statusLabel(expense.status)}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Receipts are available to workspace managers only.
                        </p>
                        <AttachmentSharingPanel
                            panel={{ files, upload, next_href: null }}
                            title="Receipts"
                        />
                        <AttachmentPageLinks
                            nextHref={nextFilesHref}
                            previousHref={previousFilesHref}
                        />
                    </CardContent>
                </Card>
            </main>
        </WorkspaceShell>
    );
}
