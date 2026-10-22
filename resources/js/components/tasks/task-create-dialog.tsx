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
    const [projectId, setProjectId] = useState(projects[0]?.id ?? '');
    const project =
        projects.find((item) => item.id === projectId) ?? projects[0];

    if (!project) {
        return null;
    }

    return (
        <>
            <Button onClick={() => setOpen(true)}>New task</Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90dvh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>New task</DialogTitle>
                        <DialogDescription>
                            Plan work in a project and choose what the client
                            may see.
                        </DialogDescription>
                    </DialogHeader>
                    <Label htmlFor="new-task-project">Project</Label>
                    <Select
                        items={projects.map((item) => ({
                            value: item.id,
                            label: item.name,
                        }))}
                        value={project.id}
                        onValueChange={(value) => {
                            if (value !== null) {
                                setProjectId(value);
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
                            {projects.map((item) => (
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
                        onSaved={() => setOpen(false)}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}
