import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Badge, priorityTone, statusTone } from '@/Components/Ui';

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

function TaskMeta({ task }) {
    return (
        <div className="flex flex-wrap gap-2 text-xs text-slate-500">
            <Badge tone={statusTone[task.status]}>{statusLabels[task.status] ?? task.status}</Badge>
            <Badge tone={priorityTone[task.priority]}>{priorityLabels[task.priority] ?? task.priority}</Badge>
            <span>{task.assignee?.name ?? 'Unassigned'}</span>
            <span>{task.due_date ?? 'No due date'}</span>
        </div>
    );
}

export default function Timeline({ project, range, weeks, tasks, unscheduledTasks }) {
    const hasTimeline = weeks.length > 0;

    return (
        <AuthenticatedLayout title={`${project.name} Timeline`} subtitle={project.workspace?.name}>
            <Head title={`${project.name} Timeline`} />

            <div className="space-y-6">
                <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/60">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Timeline view</p>
                            <h2 className="mt-1 text-xl font-semibold tracking-tight text-slate-950">Project Timeline</h2>
                            <p className="mt-1 text-sm text-slate-500">
                                {project.start_date ?? 'No project start'} to {project.due_date ?? 'no project due date'}
                            </p>
                        </div>
                        <Link
                            href={route('projects.show', project.id)}
                            className="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-950"
                        >
                            Back to Project
                        </Link>
                    </div>
                </section>

                <section className="rounded-lg border border-slate-200 bg-white shadow-sm shadow-slate-200/60">
                    <div className="border-b border-slate-200 p-5">
                        <h3 className="text-base font-semibold text-slate-950">Weekly Plan</h3>
                        <p className="mt-1 text-sm text-slate-500">
                            {range.start && range.end ? `${range.start} through ${range.end}` : 'Add task dates to build a timeline range.'}
                        </p>
                    </div>

                    {!hasTimeline ? (
                        <div className="p-8 text-center text-sm text-slate-500">
                            No dated project or task work exists yet.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <div className="min-w-[920px]">
                                <div className="grid border-b border-slate-200 bg-slate-50" style={{ gridTemplateColumns: `260px repeat(${weeks.length}, minmax(110px, 1fr))` }}>
                                    <div className="border-r border-slate-200 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        Task
                                    </div>
                                    {weeks.map((week) => (
                                        <div key={week.start} className="border-r border-slate-200 px-3 py-3 text-xs font-semibold text-slate-500">
                                            {week.label}
                                        </div>
                                    ))}
                                </div>

                                <div className="divide-y divide-slate-100">
                                    {tasks.length === 0 ? (
                                        <div className="p-5 text-sm text-slate-500">No dated tasks in this project.</div>
                                    ) : (
                                        tasks.map((task) => (
                                            <div key={task.id} className="grid min-h-24" style={{ gridTemplateColumns: `260px repeat(${weeks.length}, minmax(110px, 1fr))` }}>
                                                <Link href={route('tasks.show', task.id)} className="border-r border-slate-200 p-5 transition hover:bg-slate-50">
                                                    <div className="text-sm font-semibold text-slate-950">{task.title}</div>
                                                    <div className="mt-2">
                                                        <TaskMeta task={task} />
                                                    </div>
                                                </Link>
                                                <div className="relative col-span-full col-start-2 row-start-1">
                                                    <div className="absolute inset-0 grid" style={{ gridTemplateColumns: `repeat(${weeks.length}, minmax(110px, 1fr))` }}>
                                                        {weeks.map((week) => (
                                                            <div key={week.start} className="border-r border-slate-100" />
                                                        ))}
                                                    </div>
                                                    <Link
                                                        href={route('tasks.show', task.id)}
                                                        className="absolute top-8 h-8 rounded-lg bg-slate-950 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-slate-800"
                                                        style={{ left: `${task.bar.left}%`, width: `${task.bar.width}%` }}
                                                    >
                                                        <span className="block truncate">{task.title}</span>
                                                    </Link>
                                                </div>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>
                        </div>
                    )}
                </section>

                <section className="rounded-lg border border-slate-200 bg-white shadow-sm shadow-slate-200/60">
                    <div className="border-b border-slate-200 p-5">
                        <h3 className="text-base font-semibold text-slate-950">Unscheduled Tasks</h3>
                        <p className="mt-1 text-sm text-slate-500">Tasks without start or due dates.</p>
                    </div>
                    <div className="divide-y divide-slate-100">
                        {unscheduledTasks.length === 0 ? (
                            <div className="p-5 text-sm text-slate-500">No unscheduled tasks.</div>
                        ) : (
                            unscheduledTasks.map((task) => (
                                <Link key={task.id} href={route('tasks.show', task.id)} className="block p-5 transition hover:bg-slate-50">
                                    <div className="text-sm font-semibold text-slate-950">{task.title}</div>
                                    <div className="mt-2">
                                        <TaskMeta task={task} />
                                    </div>
                                </Link>
                            ))
                        )}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
