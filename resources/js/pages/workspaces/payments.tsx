import { Head } from '@inertiajs/react';
import { PaymentConnections } from '@/components/workspaces/payment-connections';
import type { PaymentProcessorView } from '@/components/workspaces/payment-connections';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';

interface PaymentsProps {
    workspaceName: string;
    canManage: boolean;
    processors: PaymentProcessorView[];
}

export default function WorkspacePayments({
    workspaceName,
    canManage,
    processors,
}: PaymentsProps) {
    return (
        <WorkspaceShell>
            <Head title="Payment connections" />
            <main className={cn(SHELL_CONTAINER, 'py-8')}>
                <div className="grid min-w-0 grid-cols-1 gap-6">
                    <section className="grid grid-cols-1 gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Payment connections
                        </h1>
                        <p className="wrap-anywhere text-muted-foreground">
                            {canManage
                                ? `Invoices from ${workspaceName} are paid into the accounts connected here. Keys are stored encrypted and shown only as their last four characters.`
                                : `Only an owner or admin of ${workspaceName} can change these connections.`}
                        </p>
                    </section>
                    <PaymentConnections processors={processors} />
                </div>
            </main>
        </WorkspaceShell>
    );
}
