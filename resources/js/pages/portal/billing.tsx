import { Head } from '@inertiajs/react';
import { PortalAutopayCard } from '@/components/billing/autopay-portal';
import type { PortalAutopay } from '@/components/billing/autopay-portal';
import { PaymentMethodsCard } from '@/components/billing/payment-methods-portal';
import type {
    MethodSetup,
    SavedMethod,
} from '@/components/billing/payment-methods-portal';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';

/**
 * The client's billing page: autopay, and the payment methods saved for it.
 *
 * A contact adds a card or bank account through Stripe's own form, chooses
 * which method is the default - what autopay charges - and removes methods. Everything here is read from the server: the
 * viewer's ability to change autopay arrives as URLs, or null.
 */
export default function PortalBilling({
    provider_name: providerName,
    autopay,
    autopay_available: available,
    payment_methods: methods,
    payment_method_setup: setup,
    payment_methods_notice: notice,
    method_result: methodResult = null,
    result = null,
    company,
}: {
    company: { id: string; name: string };
    provider_name: string;
    home_href: string;
    autopay_available: boolean;
    autopay: PortalAutopay;
    payment_methods: SavedMethod[];
    payment_method_setup: MethodSetup | null;
    payment_methods_notice: string | null;
    method_result?: string | null;
    result?: string | null;
}) {
    return (
        <WorkspaceShell activeModule="billing">
            <Head title="Billing" />
            <main
                className={cn(
                    SHELL_CONTAINER,
                    'grid grid-cols-1 items-start gap-6 py-8 lg:grid-cols-2',
                )}
            >
                <h1 className="sr-only">Billing</h1>
                <PortalAutopayCard
                    providerName={providerName}
                    autopay={autopay}
                    available={available}
                    result={result}
                />

                <PaymentMethodsCard
                    providerName={providerName}
                    companyName={company.name}
                    methods={methods}
                    setup={setup}
                    notice={notice}
                    autopayEnabled={autopay.enabled}
                    result={methodResult}
                />
            </main>
        </WorkspaceShell>
    );
}
