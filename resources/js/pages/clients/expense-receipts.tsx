import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    AgreementFileUpload,
    formatBytes,
} from '@/components/agreements/agreement-editor';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatDay } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { AgreementFile } from '@/types/agreement';

type Props = {
    expense: {
        description: string;
        spent_on: string;
        amount: number;
        currency: string;
        status: string;
    };
    files: AgreementFile[];
    upload_href: string;
};

export default function ExpenseReceipts({
    expense,
    files,
    upload_href,
}: Props) {
    const [removing, setRemoving] = useState<AgreementFile | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    return (
        <WorkspaceShell activeModule="expenses">
            <Head title="Expense receipts" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <Card>
                    <CardHeader>
                        <CardTitle>Expense receipts</CardTitle>
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
                        {error && (
                            <p
                                role="alert"
                                className="wrap-anywhere text-destructive"
                            >
                                {error}
                            </p>
                        )}
                        {files.length === 0 ? (
                            <p>No receipts attached.</p>
                        ) : (
                            <ul className="grid grid-cols-1 gap-3">
                                {files.map((file) => (
                                    <li
                                        key={file.id}
                                        className="flex min-w-0 flex-wrap items-center gap-3"
                                    >
                                        <a
                                            href={file.download_href}
                                            className="min-w-0 wrap-anywhere underline"
                                        >
                                            {file.filename}
                                        </a>
                                        <span className="text-sm text-muted-foreground">
                                            {formatBytes(file.bytes)}
                                        </span>
                                        {file.delete_href && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setRemoving(file)
                                                }
                                            >
                                                Remove
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <AgreementFileUpload uploadHref={upload_href} />
                    </CardContent>
                </Card>
            </main>
            <AlertDialog
                open={removing !== null}
                onOpenChange={(open) => {
                    if (!open && !busy) {
                        setRemoving(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Remove receipt?</AlertDialogTitle>
                        <AlertDialogDescription className="wrap-anywhere">
                            {removing?.filename} will no longer be available to
                            download.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={busy}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={busy}
                            onClick={(event) => {
                                event.preventDefault();

                                if (!removing?.delete_href) {
                                    return;
                                }

                                setBusy(true);
                                setError(null);
                                router.delete(removing.delete_href, {
                                    preserveScroll: true,
                                    onSuccess: () => setRemoving(null),
                                    onError: (errors) =>
                                        setError(
                                            Object.values(errors)[0] ??
                                                'Receipt could not be removed.',
                                        ),
                                    onFinish: () => setBusy(false),
                                });
                            }}
                        >
                            Remove receipt
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </WorkspaceShell>
    );
}
