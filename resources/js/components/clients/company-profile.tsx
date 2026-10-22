import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

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
}: {
    profile: CompanyProfileData;
    action: string;
}) {
    const form = useForm({
        billing_address: profile.billing_address ?? '',
        phone: profile.phone ?? '',
        website_url: profile.website_url ?? '',
        tax_id: profile.tax_id ?? '',
        currency: profile.currency ?? '',
        notes: profile.notes ?? '',
    });

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.patch(action, { preserveScroll: true });
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
                    {form.errors[field] && (
                        <p className="text-sm text-destructive">
                            {form.errors[field]}
                        </p>
                    )}
                </div>
            ))}
            <p className="text-sm text-muted-foreground">
                Company details do not change previously issued invoices.
            </p>
            <div>
                <Button type="submit" disabled={form.processing}>
                    Save company profile
                </Button>
            </div>
        </form>
    );
}
