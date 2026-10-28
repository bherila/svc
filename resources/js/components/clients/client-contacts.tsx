import { useState } from 'react';
import { useClientChange } from '@/components/clients/use-client-change';
import { useClientDraft } from '@/components/clients/use-client-draft';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { refreshPageProps } from '@/lib/refresh-page-props';

export type ClientContact = {
    id: string;
    first_name: string | null;
    last_name: string | null;
    title: string | null;
    email: string | null;
    phone: string | null;
    notes: string | null;
    is_archived: boolean;
    version: string;
    archive_action: string;
    restore_action: string;
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
    onCreated,
}: {
    contact?: ClientContact;
    action: string;
    onCreated?: () => void;
}) {
    const form = useClientDraft(
        {
            first_name: contact?.first_name ?? '',
            last_name: contact?.last_name ?? '',
            title: contact?.title ?? '',
            email: contact?.email ?? '',
            phone: contact?.phone ?? '',
            notes: contact?.notes ?? '',
        },
        contact?.version ?? 'new-contact',
    );
    const [newArchived, setNewArchived] = useState(false);
    const change = useClientChange();
    const [confirming, setConfirming] = useState<{ version: string } | null>(
        null,
    );
    const refresh = async () => {
        await refreshPageProps({ only: ['contacts'], requireSuccess: true });
    };
    const prefix = `contact-${contact?.id ?? 'new'}`;

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-4"
            onSubmit={(event) => {
                event.preventDefault();

                const submitted = { ...form.data };
                const fields = submitted;
                const details = Object.fromEntries(
                    Object.entries(fields).map(([field, value]) => [
                        field,
                        value === '' ? null : value,
                    ]),
                );
                void change.run(
                    contact ? 'PATCH' : 'POST',
                    action,
                    contact
                        ? { ...details, expected_version: form.version }
                        : { ...details, is_archived: newArchived },
                    async (result) => {
                        if (contact) {
                            form.saved(submitted, result.data.version);
                        }

                        await refresh();

                        if (!contact) {
                            onCreated?.();
                        }
                    },
                );
            }}
        >
            <div className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2">
                {FIELDS.map(([field, label]) => (
                    <div key={field} className="grid min-w-0 grid-cols-1 gap-2">
                        <Label htmlFor={`${prefix}-${field}`}>{label}</Label>
                        <Input
                            disabled={change.locked}
                            id={`${prefix}-${field}`}
                            type={field === 'email' ? 'email' : 'text'}
                            value={form.data[field]}
                            onChange={(event) =>
                                form.setData(field, event.target.value)
                            }
                        />
                        {change.errors[field]?.[0] && (
                            <p className="text-sm text-destructive">
                                {change.errors[field]?.[0]}
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
                    disabled={change.locked}
                    className="wrap-anywhere"
                    id={`${prefix}-notes`}
                    value={form.data.notes}
                    onChange={(event) =>
                        form.setData('notes', event.target.value)
                    }
                />
                {change.errors.notes?.[0] && (
                    <p className="text-sm text-destructive">
                        {change.errors.notes?.[0]}
                    </p>
                )}
            </div>
            {change.error && !confirming && (
                <p role="alert" className="text-sm text-destructive">
                    {change.error}
                </p>
            )}
            <div className="flex flex-wrap items-center gap-3">
                {!contact && (
                    <>
                        <Switch
                            disabled={change.locked}
                            id={`${prefix}-archived`}
                            checked={newArchived}
                            onCheckedChange={(checked) =>
                                setNewArchived(checked === true)
                            }
                        />
                        <Label htmlFor={`${prefix}-archived`}>
                            Archived contact
                        </Label>
                    </>
                )}
                <Button type="submit" disabled={change.locked}>
                    {contact ? 'Save contact' : 'Add contact'}
                </Button>
                {change.unresolved && !confirming && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={change.busy}
                        onClick={() => void change.check()}
                    >
                        Check original change
                    </Button>
                )}
                {contact && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={change.locked}
                        onClick={() => {
                            if (contact.is_archived) {
                                void change.run(
                                    'POST',
                                    contact.restore_action,
                                    { expected_version: contact.version },
                                    refresh,
                                );
                            } else {
                                setConfirming({ version: contact.version });
                            }
                        }}
                    >
                        {contact.is_archived
                            ? 'Restore contact'
                            : 'Archive contact'}
                    </Button>
                )}
                {contact && (
                    <AlertDialog
                        open={confirming !== null}
                        onOpenChange={(open) => {
                            if (!open && !change.locked) {
                                setConfirming(null);
                            }
                        }}
                    >
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Archive contact?
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    The contact stays in the client history.
                                    Their sign-in and portal access are
                                    unchanged.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            {change.error && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {change.error}
                                </p>
                            )}
                            <AlertDialogFooter>
                                <AlertDialogCancel disabled={change.locked}>
                                    Cancel
                                </AlertDialogCancel>
                                <Button
                                    type="button"
                                    disabled={change.locked}
                                    onClick={() => {
                                        if (!confirming) {
                                            return;
                                        }

                                        void change.run(
                                            'POST',
                                            contact.archive_action,
                                            {
                                                expected_version:
                                                    confirming.version,
                                                confirm: true,
                                            },
                                            async () => {
                                                await refresh();
                                                setConfirming(null);
                                            },
                                        );
                                    }}
                                >
                                    Archive contact
                                </Button>
                                {change.unresolved && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={change.busy}
                                        onClick={() => void change.check()}
                                    >
                                        Check original change
                                    </Button>
                                )}
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                )}
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
    const [newContact, setNewContact] = useState(0);

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
                <ContactForm
                    key={newContact}
                    action={createAction}
                    onCreated={() => setNewContact((current) => current + 1)}
                />
            </div>
        </div>
    );
}
