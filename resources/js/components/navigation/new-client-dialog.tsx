import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Adding a client, from the control that switches between them.
 *
 * A dialog rather than a page, because creating a client is one field and a
 * redirect: a screen of its own would be a fourth place the application asks
 * "which client", and the whole point of the switcher is that there is one.
 * The server sends the operator straight into the new client's home, so the
 * thing they just made is the thing they are looking at.
 */
export function NewClientDialog({
    workspaceId,
    open,
    onOpenChange,
}: {
    workspaceId: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [name, setName] = useState('');
    const [billingEmail, setBillingEmail] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const submit = () => {
        if (saving) {
            return;
        }

        setSaving(true);
        router.post(
            `/workspaces/${workspaceId}/clients`,
            {
                name,
                billing_email: billingEmail === '' ? null : billingEmail,
            },
            {
                onSuccess: () => {
                    setName('');
                    setBillingEmail('');
                    setError(null);
                    onOpenChange(false);
                },
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'That client could not be created.',
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Add client</DialogTitle>
                    <DialogDescription>
                        You will be taken to the new client&rsquo;s home.
                    </DialogDescription>
                </DialogHeader>

                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="new-client-name">Name</Label>
                        <Input
                            id="new-client-name"
                            value={name}
                            required
                            maxLength={160}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="new-client-billing-email">
                            Billing email
                        </Label>
                        <Input
                            id="new-client-billing-email"
                            type="email"
                            value={billingEmail}
                            onChange={(event) =>
                                setBillingEmail(event.target.value)
                            }
                        />
                    </div>

                    {error !== null && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}

                    <DialogFooter>
                        <DialogClose
                            render={
                                <Button variant="outline" type="button">
                                    Cancel
                                </Button>
                            }
                        />
                        <Button type="submit" disabled={saving || name === ''}>
                            Add client
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
