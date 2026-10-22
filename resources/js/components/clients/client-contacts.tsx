import { useForm } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

export type ClientContact = {
    id: string;
    first_name: string | null;
    last_name: string | null;
    title: string | null;
    email: string | null;
    phone: string | null;
    notes: string | null;
    is_archived: boolean;
    lock_version: number;
    update_action: string;
};

const FIELDS = [
    ['first_name', 'First name'],
    ['last_name', 'Last name'],
    ['title', 'Role or title'],
    ['email', 'Email'],
    ['phone', 'Phone'],
] as const;

function ContactForm({
    contact,
    action,
}: {
    contact?: ClientContact;
    action: string;
}) {
    const form = useForm({
        first_name: contact?.first_name ?? '',
        last_name: contact?.last_name ?? '',
        title: contact?.title ?? '',
        email: contact?.email ?? '',
        phone: contact?.phone ?? '',
        notes: contact?.notes ?? '',
        is_archived: contact?.is_archived ?? false,
        lock_version: contact?.lock_version ?? 1,
    });
    const prefix = `contact-${contact?.id ?? 'new'}`;

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-4"
            onSubmit={(event) => {
                event.preventDefault();

                if (contact) {
                    form.patch(action, { preserveScroll: true });
                } else {
                    form.post(action, {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }
            }}
        >
            <div className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2">
                {FIELDS.map(([field, label]) => (
                    <div key={field} className="grid min-w-0 grid-cols-1 gap-2">
                        <Label htmlFor={`${prefix}-${field}`}>{label}</Label>
                        <Input
                            id={`${prefix}-${field}`}
                            type={field === 'email' ? 'email' : 'text'}
                            value={form.data[field]}
                            onChange={(event) =>
                                form.setData(field, event.target.value)
                            }
                        />
                        {form.errors[field] && (
                            <p className="text-sm text-destructive">
                                {form.errors[field]}
                            </p>
                        )}
                    </div>
                ))}
            </div>
            <div className="grid min-w-0 grid-cols-1 gap-2">
                <Label htmlFor={`${prefix}-notes`}>
                    Internal contact notes
                </Label>
                <Textarea
                    className="wrap-anywhere"
                    id={`${prefix}-notes`}
                    value={form.data.notes}
                    onChange={(event) =>
                        form.setData('notes', event.target.value)
                    }
                />
                {form.errors.notes && (
                    <p className="text-sm text-destructive">
                        {form.errors.notes}
                    </p>
                )}
            </div>
            <div className="flex flex-wrap items-center gap-3">
                <Switch
                    id={`${prefix}-archived`}
                    checked={form.data.is_archived}
                    onCheckedChange={(checked) =>
                        form.setData('is_archived', checked === true)
                    }
                />
                <Label htmlFor={`${prefix}-archived`}>Archived contact</Label>
                <Button type="submit" disabled={form.processing}>
                    {contact ? 'Save contact' : 'Add contact'}
                </Button>
            </div>
        </form>
    );
}

export function ClientContacts({
    contacts,
    createAction,
}: {
    contacts: ClientContact[];
    createAction: string;
}) {
    return (
        <div className="grid min-w-0 grid-cols-1 gap-5">
            <p className="text-sm text-muted-foreground">
                Contacts keep your client’s people and history together. Adding
                a contact does not grant portal access or send an invitation.
            </p>
            {contacts.map((contact) => (
                <details
                    key={contact.id}
                    className="min-w-0 rounded-md border p-4"
                >
                    <summary className="cursor-pointer wrap-anywhere">
                        {[contact.first_name, contact.last_name]
                            .filter(Boolean)
                            .join(' ')}{' '}
                        {contact.is_archived && (
                            <Badge variant="secondary">Archived</Badge>
                        )}
                    </summary>
                    <div className="mt-4">
                        <ContactForm
                            contact={contact}
                            action={contact.update_action}
                        />
                    </div>
                </details>
            ))}
            <div className="grid min-w-0 grid-cols-1 gap-4 border-t pt-4">
                <h3 className="font-medium">New contact</h3>
                <ContactForm action={createAction} />
            </div>
        </div>
    );
}
