import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { DurationField } from '@/components/time/duration-field';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import type { CompanyOption, TimeEntry } from '@/types/time-sheet';

type Draft = {
    project_id: string;
    task_id: string;
    worked_on: string;
    minutes: number;
    description: string;
    is_billable: boolean;
    is_deferred: boolean;
    is_visible_to_client: boolean;
    client_visible_description: string;
};

function draftFor(company: CompanyOption, entry: TimeEntry | null): Draft {
    if (entry !== null) {
        return {
            project_id: entry.project.id,
            task_id: entry.task?.id ?? '',
            worked_on: entry.worked_on,
            minutes: entry.minutes,
            description: entry.description,
            is_billable: entry.is_billable,
            is_deferred: entry.is_deferred,
            is_visible_to_client: entry.is_visible_to_client,
            client_visible_description: entry.client_visible_description ?? '',
        };
    }

    const loggable = company.projects.find((project) => project.can_log_time);

    return {
        project_id: loggable?.id ?? '',
        task_id: '',
        worked_on: new Date().toISOString().slice(0, 10),
        minutes: 30,
        description: '',
        is_billable: true,
        is_deferred: false,
        is_visible_to_client: false,
        client_visible_description: '',
    };
}

/**
 * Log or amend one entry.
 *
 * Creation and amendment are the same form because they describe the same
 * thing; only the endpoint differs. Amendment sends the version it read, so a
 * row changed elsewhere is refused rather than silently overwritten.
 */
export function TimeEntryDialog({
    workspaceId,
    company,
    entry,
    open,
    onOpenChange,
}: {
    workspaceId: string;
    company: CompanyOption;
    entry: TimeEntry | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [draft, setDraft] = useState<Draft>(() => draftFor(company, entry));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const project = useMemo(
        () =>
            company.projects.find(
                (candidate) => candidate.id === draft.project_id,
            ),
        [company.projects, draft.project_id],
    );

    const set = <K extends keyof Draft>(key: K, value: Draft[K]) => {
        setDraft((current) => ({ ...current, [key]: value }));
    };

    const clientDescriptionMissing =
        draft.is_visible_to_client &&
        draft.client_visible_description.trim() === '';

    const submit = () => {
        if (clientDescriptionMissing) {
            setError('Client-visible time needs a client-facing description.');

            return;
        }

        setError(null);
        setSaving(true);

        const finish = {
            onSuccess: () => onOpenChange(false),
            onError: (errors: Record<string, string>) => {
                setError(
                    Object.values(errors)[0] ?? 'The entry could not be saved.',
                );
            },
            onFinish: () => setSaving(false),
            preserveScroll: true,
        };

        const shared = {
            worked_on: draft.worked_on,
            minutes: draft.minutes,
            description: draft.description,
            is_billable: draft.is_billable,
            is_deferred: draft.is_deferred,
            is_visible_to_client: draft.is_visible_to_client,
            client_visible_description: draft.is_visible_to_client
                ? draft.client_visible_description
                : null,
        };

        if (entry !== null) {
            router.patch(
                `/workspaces/${workspaceId}/time-entries/${entry.id}`,
                { ...shared, expected_version: entry.version },
                finish,
            );

            return;
        }

        router.post(
            `/workspaces/${workspaceId}/projects/${draft.project_id}/time-entries`,
            { ...shared, task_id: draft.task_id === '' ? null : draft.task_id },
            finish,
        );
    };

    const loggable = company.projects.filter(
        (candidate) => candidate.can_log_time,
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {entry === null ? 'Log time' : 'Edit time entry'}
                    </DialogTitle>
                    <DialogDescription>
                        {entry === null
                            ? `Against ${company.name}.`
                            : 'Only entries that have not been sent to a client can be changed.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    {entry === null && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="project">Project</Label>
                            <Select
                                value={draft.project_id}
                                onValueChange={(value: string | null) => {
                                    set('project_id', value ?? '');
                                    set('task_id', '');
                                }}
                            >
                                <SelectTrigger id="project" className="w-full">
                                    <SelectValue placeholder="Choose a project" />
                                </SelectTrigger>
                                <SelectContent>
                                    {loggable.map((candidate) => (
                                        <SelectItem
                                            key={candidate.id}
                                            value={candidate.id}
                                        >
                                            {candidate.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    {entry === null &&
                        project !== undefined &&
                        project.tasks.length > 0 && (
                            <div className="grid gap-1.5">
                                <Label htmlFor="task">Task</Label>
                                <Select
                                    value={draft.task_id}
                                    onValueChange={(value: string | null) =>
                                        set('task_id', value ?? '')
                                    }
                                >
                                    <SelectTrigger id="task" className="w-full">
                                        <SelectValue placeholder="No task" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {project.tasks.map((task) => (
                                            <SelectItem
                                                key={task.id}
                                                value={task.id}
                                            >
                                                {task.title}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="worked_on">Date</Label>
                            <Input
                                id="worked_on"
                                type="date"
                                value={draft.worked_on}
                                onChange={(event) =>
                                    set('worked_on', event.target.value)
                                }
                            />
                        </div>
                        <DurationField
                            minutes={draft.minutes}
                            onChange={(minutes) => set('minutes', minutes)}
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="description">Work performed</Label>
                        <Textarea
                            id="description"
                            rows={3}
                            value={draft.description}
                            onChange={(event) =>
                                set('description', event.target.value)
                            }
                            placeholder="What was done"
                        />
                    </div>

                    <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
                        <Label className="flex items-center gap-2 font-normal">
                            <Switch
                                checked={draft.is_billable}
                                onCheckedChange={(checked: boolean) =>
                                    set('is_billable', checked)
                                }
                            />
                            Billable
                        </Label>
                        <Label className="flex items-center gap-2 font-normal">
                            <Switch
                                checked={draft.is_deferred}
                                onCheckedChange={(checked: boolean) =>
                                    set('is_deferred', checked)
                                }
                            />
                            Deferred
                        </Label>
                        <Label className="flex items-center gap-2 font-normal">
                            <Switch
                                checked={draft.is_visible_to_client}
                                onCheckedChange={(checked: boolean) =>
                                    set('is_visible_to_client', checked)
                                }
                            />
                            Visible to client
                        </Label>
                    </div>

                    {draft.is_visible_to_client && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="client_description">
                                Client-facing description
                            </Label>
                            <Textarea
                                id="client_description"
                                rows={2}
                                value={draft.client_visible_description}
                                onChange={(event) =>
                                    set(
                                        'client_visible_description',
                                        event.target.value,
                                    )
                                }
                                placeholder="What the client sees instead of the internal note"
                            />
                            <p className="text-xs text-muted-foreground">
                                Required. Without it the internal note would be
                                the only thing to show, and it is not written
                                for a client.
                            </p>
                        </div>
                    )}

                    {error !== null && (
                        <p className="text-sm text-destructive" role="alert">
                            {error}
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <DialogClose
                        render={
                            <Button variant="outline" type="button">
                                Cancel
                            </Button>
                        }
                    />
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={
                            saving ||
                            draft.description.trim() === '' ||
                            (entry === null && draft.project_id === '')
                        }
                    >
                        {entry === null ? 'Log time' : 'Save changes'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
