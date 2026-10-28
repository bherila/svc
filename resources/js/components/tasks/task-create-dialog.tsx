import { useState } from 'react';
import { TaskEditor } from '@/components/tasks/task-editor';
import type { TaskEditorProject } from '@/components/tasks/task-editor';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export function TaskCreateDialog({
    projects,
}: {
    projects: TaskEditorProject[];
}) {
    const [open, setOpen] = useState(false);
    const [locked, setLocked] = useState(false);
    const [projectId, setProjectId] = useState(projects[0]?.id ?? '');
    const [openedProject, setOpenedProject] =
        useState<TaskEditorProject | null>(null);
    const availableProject =
        projects.find((item) => item.id === projectId) ?? projects[0];
    const project = open
        ? (openedProject ?? availableProject)
        : availableProject;
    const options =
        project && !projects.some((item) => item.id === project.id)
            ? [project, ...projects]
            : projects;

    if (!project) {
        return null;
    }

    return (
        <>
            <Button
                onClick={() => {
                    setOpenedProject(project);
                    setOpen(true);
                }}
            >
                New task
            </Button>
            <Dialog
                open={open}
                onOpenChange={(next) => {
                    if (!locked) {
                        setOpen(next);
                    }
                }}
            >
                <DialogContent
                    showCloseButton={!locked}
                    className="max-h-[90dvh] overflow-y-auto"
                >
                    <DialogHeader>
                        <DialogTitle>New task</DialogTitle>
                        <DialogDescription>
                            Plan work in a project and choose what the client
                            may see.
                        </DialogDescription>
                    </DialogHeader>
                    <Label htmlFor="new-task-project">Project</Label>
                    <Select
                        disabled={locked}
                        items={options.map((item) => ({
                            value: item.id,
                            label: item.name,
                        }))}
                        value={project.id}
                        onValueChange={(value) => {
                            if (!locked && value !== null) {
                                setProjectId(value);
                                setOpenedProject(
                                    options.find((item) => item.id === value) ??
                                        null,
                                );
                            }
                        }}
                    >
                        <SelectTrigger
                            id="new-task-project"
                            className="w-full min-w-0"
                        >
                            <SelectValue className="min-w-0 truncate" />
                        </SelectTrigger>
                        <SelectContent>
                            {options.map((item) => (
                                <SelectItem
                                    key={item.id}
                                    value={item.id}
                                    className="wrap-anywhere whitespace-normal"
                                >
                                    {item.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <TaskEditor
                        key={`${project.id}:${open}`}
                        project={project}
                        onLockedChange={setLocked}
                        onSaved={() => setOpen(false)}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}
