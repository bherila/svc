import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useClientChange } from '@/components/clients/use-client-change';
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
    action,
    open,
    onOpenChange,
}: {
    action: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [name, setName] = useState('');
    const [billingEmail, setBillingEmail] = useState('');
    const change = useClientChange();

    const submit = () => {
        void change.run(
            'POST',
            action,
            {
                name,
                billing_email: billingEmail === '' ? null : billingEmail,
            },
            async (result) => {
                const destination = result.location;

                if (!destination) {
                    throw new Error(
                        'The finished client destination is missing.',
                    );
                }

                await new Promise<void>((resolve, reject) => {
                    let loaded = false;
                    router.visit(destination, {
                        onNetworkError: () => false,
                        onHttpException: () => false,
                        onSuccess: () => {
                            loaded = true;
                        },
                        onFinish: () =>
                            loaded
                                ? resolve()
                                : reject(
                                      new Error(
                                          'The client page could not load.',
                                      ),
                                  ),
                    });
                });
                setName('');
                setBillingEmail('');
                onOpenChange(false);
            },
            { requireLocation: true },
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!change.busy) {
                    onOpenChange(next);
                }
            }}
        >
            <DialogContent
                className="sm:max-w-md"
                showCloseButton={!change.busy}
            >
                <DialogHeader>
                    <DialogTitle>Add client</DialogTitle>
                    <DialogDescription>
                        You will be taken to the new client&rsquo;s home.
                    </DialogDescription>
                </DialogHeader>

                <form
                    className="grid grid-cols-1 gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <div className="grid grid-cols-1 gap-2">
                        <Label htmlFor="new-client-name">Name</Label>
                        <Input
                            disabled={change.locked}
                            id="new-client-name"
                            value={name}
                            required
                            maxLength={160}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </div>

                    <div className="grid grid-cols-1 gap-2">
                        <Label htmlFor="new-client-billing-email">
                            Billing email
                        </Label>
                        <Input
                            disabled={change.locked}
                            id="new-client-billing-email"
                            type="email"
                            value={billingEmail}
                            onChange={(event) =>
                                setBillingEmail(event.target.value)
                            }
                        />
                    </div>

                    {change.error !== null && (
                        <p role="alert" className="text-sm text-destructive">
                            {change.error}
                        </p>
                    )}

                    <DialogFooter>
                        <DialogClose
                            render={
                                <Button
                                    variant="outline"
                                    type="button"
                                    disabled={change.busy}
                                >
                                    Cancel
                                </Button>
                            }
                        />
                        <Button
                            type="submit"
                            disabled={change.locked || name === ''}
                        >
                            Add client
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
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
