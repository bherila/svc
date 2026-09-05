import { Head, router } from '@inertiajs/react';
import { PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { useState } from 'react';
import { ExpenseDialog } from '@/components/expenses/expense-dialog';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatDay } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { ClientExpenseRow, ExpensesPageProps } from '@/types/expenses';

/** What the row's badge should say, and how loudly. */
function badgeOf(status: string): {
    variant: 'default' | 'secondary' | 'outline';
    className?: string;
} {
    if (status === 'invoiced') {
        return {
            variant: 'secondary',
            className:
                'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
        };
    }

    if (status === 'approved') {
        return { variant: 'secondary' };
    }

    return { variant: 'outline' };
}

/**
 * A client's reimbursable expenses, and the manager's gate on them.
 *
 * An expense reaches an invoice at cost, so the amount on this screen is the
 * amount the client is billed and the approval is the only thing between the
 * two. That is why the lifecycle is the spine of the page rather than a column
 * on it: what a row offers is what the server says may be done to it, and a
 * status this screen does not recognise offers nothing rather than guessing.
 *
 * Editing is a draft-only move, and the screen says so rather than letting a
 * manager discover it through a refusal: an approved row's edit control is
 * replaced by "Return to draft", which is the move that makes editing possible
 * again and which the row records.
 */
export default function ClientExpenses({
    company,
    permissions,
    urls,
    projects,
    expenses,
}: ExpensesPageProps) {
    const [dialogExpense, setDialogExpense] = useState<ClientExpenseRow | null>(
        null,
    );
    const [dialogOpen, setDialogOpen] = useState(false);
    const [pendingDiscard, setPendingDiscard] =
        useState<ClientExpenseRow | null>(null);
    const [busy, setBusy] = useState(false);

    const open = (expense: ClientExpenseRow | null) => {
        setDialogExpense(expense);
        setDialogOpen(true);
    };

    const move = (url: string) => {
        setBusy(true);
        router.post(
            url,
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <WorkspaceShell activeModule="expenses">
            <Head title={`${company.name} expenses`} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <Card>
                    <CardHeader className="gap-3">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <CardTitle>Expenses</CardTitle>
                            {permissions.record && (
                                <Button size="sm" onClick={() => open(null)}>
                                    <PlusIcon className="size-4" />
                                    Record expense
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        {expenses.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No expenses recorded for this client.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Date</TableHead>
                                            <TableHead className="min-w-64">
                                                Description
                                            </TableHead>
                                            <TableHead>Project</TableHead>
                                            <TableHead className="text-right">
                                                Amount
                                            </TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="text-right">
                                                Actions
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {expenses.map((expense) => {
                                            const badge = badgeOf(
                                                expense.status,
                                            );

                                            return (
                                                <TableRow key={expense.id}>
                                                    <TableCell className="whitespace-nowrap text-muted-foreground">
                                                        {formatDay(
                                                            expense.spent_on,
                                                        )}
                                                    </TableCell>
                                                    {/*
                                                     * The one prose column, so
                                                     * it is the one that wraps.
                                                     * Unmarked it pushes every
                                                     * column right of it off
                                                     * the screen.
                                                     */}
                                                    <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                                        {expense.description}
                                                        {expense.approved_by !==
                                                            null && (
                                                            <span className="block text-xs text-muted-foreground">
                                                                Approved by{' '}
                                                                {
                                                                    expense.approved_by
                                                                }
                                                                {expense.approved_at !==
                                                                    null &&
                                                                    ` · ${formatDay(expense.approved_at)}`}
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {expense.project
                                                            ?.name ?? '—'}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {formatMoney(
                                                            expense.amount,
                                                            expense.currency,
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant={
                                                                badge.variant
                                                            }
                                                            className={
                                                                badge.className
                                                            }
                                                        >
                                                            {statusLabel(
                                                                expense.status,
                                                            )}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-1">
                                                            {expense.can_approve && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    disabled={
                                                                        busy
                                                                    }
                                                                    onClick={() =>
                                                                        move(
                                                                            expense
                                                                                .urls
                                                                                .approve,
                                                                        )
                                                                    }
                                                                >
                                                                    Approve
                                                                </Button>
                                                            )}
                                                            {/*
                                                             * The way back to
                                                             * editing, offered
                                                             * where the edit
                                                             * control would be
                                                             * refused. A
                                                             * manager should
                                                             * not have to
                                                             * discover the
                                                             * draft-only rule
                                                             * by hitting it.
                                                             */}
                                                            {expense.can_unapprove && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    disabled={
                                                                        busy
                                                                    }
                                                                    onClick={() =>
                                                                        move(
                                                                            expense
                                                                                .urls
                                                                                .unapprove,
                                                                        )
                                                                    }
                                                                >
                                                                    Return to
                                                                    draft
                                                                </Button>
                                                            )}
                                                            {expense.can_edit && (
                                                                <Button
                                                                    size="icon"
                                                                    variant="ghost"
                                                                    aria-label="Edit expense"
                                                                    onClick={() =>
                                                                        open(
                                                                            expense,
                                                                        )
                                                                    }
                                                                >
                                                                    <PencilIcon className="size-4" />
                                                                </Button>
                                                            )}
                                                            {expense.can_discard && (
                                                                <Button
                                                                    size="icon"
                                                                    variant="ghost"
                                                                    aria-label="Discard expense"
                                                                    onClick={() =>
                                                                        setPendingDiscard(
                                                                            expense,
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2Icon className="size-4" />
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </main>

            <ExpenseDialog
                // Remounted per row, so its draft comes from the expense being
                // edited rather than from whatever was open last.
                key={dialogExpense?.id ?? 'new'}
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                storeUrl={urls.store}
                projects={projects}
                expense={dialogExpense}
            />

            <AlertDialog
                open={pendingDiscard !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setPendingDiscard(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Discard this expense?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            It stops appearing here and can no longer be billed.
                            The record is kept for the audit trail.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep it</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                if (pendingDiscard === null) {
                                    return;
                                }

                                router.delete(pendingDiscard.urls.discard, {
                                    preserveScroll: true,
                                    onFinish: () => setPendingDiscard(null),
                                });
                            }}
                        >
                            Discard
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </WorkspaceShell>
    );
}
