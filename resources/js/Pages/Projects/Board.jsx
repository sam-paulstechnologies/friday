import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Badge, Panel, priorityTone, secondaryButton } from '@/Components/Ui';

const statusLabels = {
    todo: 'To do',
    in_progress: 'In progress',
    blocked: 'Blocked',
    review: 'Review',
    completed: 'Completed',
};

const priorityLabels = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    urgent: 'Urgent',
};

const priorityClasses = {
    low: 'bg-slate-100 text-slate-600',
    medium: 'bg-blue-50 text-blue-700 ring-1 ring-blue-100',
    high: 'bg-amber-50 text-amber-700 ring-1 ring-amber-100',
    urgent: 'bg-rose-50 text-rose-700 ring-1 ring-rose-100',
};

function TaskCard({ task, onDragStart }) {
    return (
        <Link
            href={route('tasks.show', task.id)}
            draggable
            onDragStart={(event) => onDragStart(event, task.id)}
            className="block rounded-lg border border-slate-200 bg-white p-3 shadow-sm shadow-slate-200/60 transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md"
        >
            {task.section && (
                <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
                    {task.section}
                </div>
            )}
            <div className="text-sm font-semibold leading-5 text-slate-950">{task.title}</div>
            <div className="mt-3 flex flex-wrap items-center gap-2">
                <Badge tone={priorityTone[task.priority]}>{priorityLabels[task.priority]}</Badge>
                <span className="text-xs text-slate-500">{task.due_date ?? 'No due date'}</span>
            </div>
            <div className="mt-3 flex items-center gap-2 text-xs text-slate-500">
                <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-emerald-100 font-bold text-emerald-800 ring-1 ring-emerald-200">
                    {(task.assignee?.name ?? 'U').charAt(0).toUpperCase()}
                </span>
                <span className="truncate">{task.assignee?.name ?? 'Unassigned'}</span>
            </div>
        </Link>
    );
}

export default function Board({ project, columns, statuses }) {
    const [activeColumn, setActiveColumn] = useState(null);

    const columnCounts = useMemo(
        () => statuses.reduce((counts, status) => ({ ...counts, [status]: columns[status]?.length ?? 0 }), {}),
        [columns, statuses],
    );

    const startDrag = (event, taskId) => {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(taskId));
    };

    const dropTask = (event, status) => {
        event.preventDefault();
        setActiveColumn(null);

        const taskId = event.dataTransfer.getData('text/plain');

        if (!taskId) {
            return;
        }

        router.patch(
            route('tasks.status', taskId),
            { status },
            {
                preserveScroll: true,
                preserveState: false,
            },
        );
    };

    return (
        <AuthenticatedLayout title={`${project.name} Board`} subtitle={project.workspace?.name}>
            <Head title={`${project.name} Board`} />

            <div className="space-y-5">
                <Panel className="overflow-hidden">
                    <div className="flex flex-col gap-3 bg-gradient-to-br from-white via-slate-50 to-emerald-50/70 p-5 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p className="text-sm font-semibold uppercase tracking-wide text-slate-500">Kanban board</p>
                        <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950">{project.name}</h2>
                        <p className="mt-1 text-sm text-slate-500">
                            Drag cards between status columns to update task status.
                        </p>
                    </div>
                    <Link
                        href={route('projects.show', project.id)}
                        className={secondaryButton}
                    >
                        Project Overview
                    </Link>
                    </div>
                </Panel>

                <section className="overflow-x-auto pb-2">
                    <div className="grid min-w-[1100px] grid-cols-5 gap-4">
                        {statuses.map((status) => (
                            <div
                                key={status}
                                onDragOver={(event) => {
                                    event.preventDefault();
                                    setActiveColumn(status);
                                }}
                                onDragLeave={() => setActiveColumn(null)}
                                onDrop={(event) => dropTask(event, status)}
                                className={`min-h-[560px] rounded-3xl border p-3 transition ${
                                    activeColumn === status
                                        ? 'border-slate-400 bg-slate-100 ring-2 ring-slate-200'
                                        : 'border-slate-200 bg-white/70'
                                }`}
                            >
                                <div className="mb-3 flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-3 py-2">
                                    <h3 className="text-sm font-bold text-slate-950">{statusLabels[status]}</h3>
                                    <span className="rounded-md bg-white px-2 py-1 text-xs font-semibold text-slate-500 ring-1 ring-slate-200">
                                        {columnCounts[status]}
                                    </span>
                                </div>

                                <div className="space-y-3">
                                    {(columns[status] ?? []).length === 0 ? (
                                        <div className="rounded-2xl border border-dashed border-slate-300 bg-white/80 p-5 text-center text-sm text-slate-500">
                                            Drop tasks here to move them to {statusLabels[status]}.
                                        </div>
                                    ) : (
                                        columns[status].map((task) => (
                                            <TaskCard key={task.id} task={task} onDragStart={startDrag} />
                                        ))
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
