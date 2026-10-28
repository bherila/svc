import { useClientChange } from '@/components/clients/use-client-change';
import { useClientDraft } from '@/components/clients/use-client-draft';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { refreshPageProps } from '@/lib/refresh-page-props';

export type CompanyProfileData = {
    billing_address: string | null;
    phone: string | null;
    website_url: string | null;
    tax_id: string | null;
    currency: string | null;
    notes: string | null;
};

const FIELDS = [
    ['billing_address', 'Billing address', true],
    ['phone', 'Phone', false],
    ['website_url', 'Website', false],
    ['tax_id', 'Tax ID', false],
    ['currency', 'Preferred currency', false],
    ['notes', 'Internal company notes', true],
] as const;

export function CompanyProfile({
    profile,
    action,
    version,
}: {
    profile: CompanyProfileData;
    action: string;
    version: string;
}) {
    const form = useClientDraft(
        {
            billing_address: profile.billing_address ?? '',
            phone: profile.phone ?? '',
            website_url: profile.website_url ?? '',
            tax_id: profile.tax_id ?? '',
            currency: profile.currency ?? '',
            notes: profile.notes ?? '',
        },
        version,
    );

    const change = useClientChange();

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                const submitted = { ...form.data };
                void change.run<{ version: string }>(
                    'PATCH',
                    action,
                    {
                        ...Object.fromEntries(
                            Object.entries(submitted).map(([field, value]) => [
                                field,
                                value === '' ? null : value,
                            ]),
                        ),
                        expected_version: form.version,
                    },
                    async (result) => {
                        form.saved(submitted, result.data.version);
                        await refreshPageProps({
                            only: [
                                'company',
                                'company_profile',
                                'project_create',
                            ],
                        });
                    },
                );
            }}
        >
            {FIELDS.map(([field, label, multiline]) => (
                <div
                    key={field}
                    className="grid max-w-xl min-w-0 grid-cols-1 gap-2"
                >
                    <Label htmlFor={`profile-${field}`}>{label}</Label>
                    {multiline ? (
                        <Textarea
                            className="wrap-anywhere"
                            id={`profile-${field}`}
                            value={form.data[field]}
                            onChange={(event) =>
                                form.setData(field, event.target.value)
                            }
                        />
                    ) : (
                        <Input
                            id={`profile-${field}`}
                            value={form.data[field]}
                            type={field === 'website_url' ? 'url' : 'text'}
                            maxLength={field === 'currency' ? 3 : 191}
                            placeholder={
                                field === 'currency'
                                    ? 'For example, USD'
                                    : undefined
                            }
                            onChange={(event) =>
                                form.setData(
                                    field,
                                    field === 'currency'
                                        ? event.target.value.toUpperCase()
                                        : event.target.value,
                                )
                            }
                        />
                    )}
                    {change.errors[field]?.[0] && (
                        <p className="text-sm text-destructive">
                            {change.errors[field]?.[0]}
                        </p>
                    )}
                </div>
            ))}
            <p className="text-sm text-muted-foreground">
                Company details do not change previously issued invoices.
            </p>
            {change.error && (
                <p role="alert" className="text-sm text-destructive">
                    {change.error}
                </p>
            )}
            <div>
                <Button type="submit" disabled={change.busy}>
                    Save company profile
                </Button>
            </div>
        </form>
    );
}
