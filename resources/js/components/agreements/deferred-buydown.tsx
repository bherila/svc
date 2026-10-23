import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type { ApiSchema } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { formatDecimalHours } from '@/lib/time';

type Preview = ApiSchema<'DeferredBuyDownPreview'>;

export function DeferredBuyDownForm({
    actions,
    currency,
}: {
    actions: { preview: string; create: string };
    currency: string | null;
}) {
    const [mode, setMode] = useState('hours');
    const [target, setTarget] = useState('');
    const [roundUp, setRoundUp] = useState(false);
    const [discount, setDiscount] = useState('0');
    const [reason, setReason] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [created, setCreated] = useState<string | null>(null);
    const [key, setKey] = useState(newIdempotencyKey);

    function invalidate() {
        setPreview(null);
        setCreated(null);
        setError(null);
        setKey(newIdempotencyKey());
    }

    const input = {
        ...(mode === 'hours'
            ? { hours: Number(target) }
            : { amount: Math.round(Number(target) * 100) }),
        round_up: roundUp,
        discount_amount: Math.round(Number(discount) * 100),
        ...(reason.trim() === '' ? {} : { discount_reason: reason }),
    };

    async function quote() {
        setBusy(true);
        setError(null);
        setCreated(null);
        const result = await apiRequest<Preview>('GET', actions.preview, input);

        if (result.ok) {
            setPreview(result.data);
            setKey(newIdempotencyKey());
        } else {
            setPreview(null);
            setError(result.message);
        }

        setBusy(false);
    }

    async function create() {
        if (preview === null) {
            return;
        }

        setBusy(true);
        setError(null);
        const result = await apiRequest<ApiSchema<'InvoiceMutation'>>(
            'POST',
            actions.create,
            { ...input, expected_backlog_version: preview.backlog_version },
            key,
        );

        if (result.ok) {
            setCreated(result.data.web_url);
            setPreview(null);
            router.reload({ only: ['deferred_backlog'] });
        } else {
            setError(result.message);

            if (result.status === 409) {
                setPreview(null);
            }
        }

        setBusy(false);
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Bill deferred work</CardTitle>
            </CardHeader>
            <CardContent>
                <form
                    className="grid grid-cols-1 gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void quote();
                    }}
                >
                    <p className="text-sm text-muted-foreground">
                        Settle selected deferred work separately. It uses no
                        retainer hours. Preview the whole entries and price
                        before creating a draft.
                    </p>
                    <fieldset
                        disabled={busy}
                        className="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2"
                    >
                        <div className="grid grid-cols-1 gap-2">
                            <Label htmlFor="buydown-mode">Target</Label>
                            <select
                                id="buydown-mode"
                                className="h-9 rounded-md border bg-background px-3 text-sm"
                                value={mode}
                                onChange={(event) => {
                                    setMode(event.target.value);
                                    invalidate();
                                }}
                            >
                                <option value="hours">Hours</option>
                                <option value="amount">
                                    Amount ({currency})
                                </option>
                            </select>
                            <Input
                                aria-label={
                                    mode === 'hours'
                                        ? 'Target hours'
                                        : `Target amount (${currency})`
                                }
                                type="number"
                                required
                                min="0.01"
                                step={mode === 'hours' ? 'any' : '0.01'}
                                value={target}
                                onChange={(event) => {
                                    setTarget(event.target.value);
                                    invalidate();
                                }}
                            />
                        </div>
                        <div className="grid grid-cols-1 gap-2">
                            <Label htmlFor="buydown-discount">
                                Discount ({currency})
                            </Label>
                            <Input
                                id="buydown-discount"
                                type="number"
                                min="0"
                                step="0.01"
                                required
                                value={discount}
                                onChange={(event) => {
                                    setDiscount(event.target.value);
                                    invalidate();
                                }}
                            />
                            <Label htmlFor="buydown-discount-reason">
                                Discount reason
                            </Label>
                            <Input
                                id="buydown-discount-reason"
                                maxLength={1000}
                                required={Number(discount) > 0}
                                value={reason}
                                onChange={(event) => {
                                    setReason(event.target.value);
                                    invalidate();
                                }}
                            />
                        </div>
                        <div className="flex min-w-0 items-center gap-3 sm:col-span-2">
                            <Switch
                                id="buydown-round-up"
                                checked={roundUp}
                                onCheckedChange={(checked) => {
                                    setRoundUp(checked);
                                    invalidate();
                                }}
                            />
                            <Label htmlFor="buydown-round-up">
                                Round up to the next whole entry
                            </Label>
                        </div>
                    </fieldset>
                    {error !== null && (
                        <p
                            role="alert"
                            className="text-sm wrap-anywhere text-destructive"
                        >
                            {error}
                        </p>
                    )}
                    {preview !== null && (
                        <div
                            className="grid grid-cols-1 gap-2 text-sm"
                            aria-live="polite"
                        >
                            <p>
                                {`${formatDecimalHours(preview.hours)} h across ${preview.entry_ids.length} whole ${preview.entry_ids.length === 1 ? 'entry' : 'entries'}: ${formatMoney(preview.total_amount, preview.currency)}.`}
                                {preview.discount_amount > 0 &&
                                    ` Includes a ${formatMoney(preview.discount_amount, preview.currency)} discount.`}
                            </p>
                            <p className="text-muted-foreground">
                                Creating the draft reserves these entries. Issue
                                and send it separately when ready.
                            </p>
                            <Button
                                type="button"
                                className="justify-self-start"
                                disabled={
                                    busy || preview.entry_ids.length === 0
                                }
                                onClick={() => void create()}
                            >
                                Create draft
                            </Button>
                        </div>
                    )}
                    {created !== null && (
                        <p role="status" className="text-sm">
                            Draft created.{' '}
                            <Link className="underline" href={created}>
                                Open draft
                            </Link>
                        </p>
                    )}
                    <Button
                        type="submit"
                        variant="outline"
                        className="justify-self-start"
                        disabled={busy}
                    >
                        Preview deferred work
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}
