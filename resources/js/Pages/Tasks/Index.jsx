import { Badge, DueDate, EmptyState, FilterBar, PriorityDot, inputClass, primaryButton, priorityTone, statusTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { priorityLabels, statusLabels } from './Partials/TaskForm';

export default function Index({ tasks, taskGroups = null, taskCounts = null, defaultTab = 'upcoming', filters, statuses, priorities, projects }) {
    const [values, setValues] = useState(filters);
    const [tab, setTab] = useState(defaultTab);
    const groups = taskGroups ?? { upcoming: [], overdue: [], completed: [], all: tasks };
    const counts = taskCounts ?? {
        upcoming: groups.upcoming?.length ?? 0,
        overdue: groups.overdue?.length ?? 0,
        completed: groups.completed?.length ?? 0,
        all: groups.all?.length ?? 0,
    };

    useEffect(() => {
        const timeout = setTimeout(() => {
            router.get(route('tasks.index'), values, { preserveState: true, replace: true });
        }, 250);
        return () => clearTimeout(timeout);
    }, [values]);

    const visibleTasks = groups[tab] ?? tasks;

    return (
        <AuthenticatedLayout title="My Tasks" subtitle="A compact command list for assigned and reported work.">
            <Head title="My Tasks" />

            <div className="space-y-4">
                <div className="flex flex-col gap-3 border-b border-slate-200 pb-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="flex flex-wrap gap-1">
                        {[
                            ['upcoming', 'Upcoming'],
                            ['overdue', 'Overdue'],
                            ['completed', 'Completed'],
                            ['all', 'All'],
                        ].map(([key, label]) => (
                            <button key={key} type="button" onClick={() => setTab(key)} className={`rounded-md px-3 py-2 text-sm font-semibold ${tab === key ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950'}`}>
                                {label} <span className="ml-1 text-xs opacity-70">{counts[key] ?? 0}</span>
                            </button>
                        ))}
                    </div>
                    <Link href={route('tasks.create')} className={primaryButton}>+ Add task</Link>
                </div>

                <FilterBar>
                    <div className="grid gap-2 md:grid-cols-4">
                        <input type="search" value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder="Search tasks" className={inputClass} />
                        <select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })} className={inputClass}>
                            <option value="">All statuses</option>
                            {statuses.map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}
                        </select>
                        <select value={values.priority} onChange={(event) => setValues({ ...values, priority: event.target.value })} className={inputClass}>
                            <option value="">All priorities</option>
                            {priorities.map((priority) => <option key={priority} value={priority}>{priorityLabels[priority]}</option>)}
                        </select>
                        <select value={values.project_id} onChange={(event) => setValues({ ...values, project_id: event.target.value })} className={inputClass}>
                            <option value="">All projects</option>
                            {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                        </select>
                    </div>
                </FilterBar>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                    <div className="grid hidden gap-3 border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 lg:grid-cols-[minmax(0,1fr)_130px_130px_150px_120px_100px]">
                        <div>Task</div>
                        <div>Status</div>
                        <div>Priority</div>
                        <div>Assignee</div>
                        <div>Due</div>
                        <div></div>
                    </div>
                    {visibleTasks.length === 0 ? (
                        <EmptyState title="No tasks found" description="Create a task or adjust filters to see work here." />
                    ) : (
                        <>
                            <Link href={route('tasks.create')} className="flex items-center gap-3 border-b border-slate-100 px-4 py-2.5 text-sm text-slate-500 hover:bg-slate-50">
                                <span className="flex h-4 w-4 items-center justify-center rounded-full border border-dashed border-slate-300 text-xs">+</span>
                                Add task
                            </Link>
                            {visibleTasks.map((task) => <TaskListRow key={task.id} task={task} />)}
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function TaskListRow({ task }) {
    const completed = task.status === 'completed';
    const openTask = () => router.visit(route('tasks.show', task.id));
    const openTaskFromKeyboard = (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openTask();
        }
    };
    const complete = (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (completed) return;

        router.patch(route('tasks.status', task.id), { status: 'completed' }, {
            preserveScroll: true,
            preserveState: false,
        });
    };

    return (
        <div role="link" tabIndex="0" onClick={openTask} onKeyDown={openTaskFromKeyboard} className={`group grid cursor-pointer gap-3 border-b border-slate-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-slate-50 lg:grid-cols-[minmax(0,1fr)_130px_130px_150px_120px_100px] lg:items-center ${completed ? 'bg-slate-50/70' : ''}`}>
            <div className="flex min-w-0 items-center gap-3">
                <button type="button" onClick={complete} disabled={completed} aria-label={completed ? 'Task completed' : 'Mark task complete'} className={`h-4 w-4 shrink-0 rounded-full border ${completed ? 'border-emerald-500 bg-emerald-500' : 'border-slate-300 bg-white group-hover:border-emerald-500'}`} />
                <div className="min-w-0">
                    <div className={`truncate font-medium text-slate-950 ${completed ? 'text-slate-500 line-through decoration-slate-300' : ''}`}>{task.title}</div>
                    <div className="mt-0.5 truncate text-xs text-slate-500">
                        {task.project?.name ?? task.section ?? 'No project'}{task.portfolio?.name ? ` / ${task.portfolio.name}` : ''}{task.area?.name ? ` / ${task.area.name}` : ''}
                    </div>
                    {task.labels?.length > 0 && (
                        <div className="mt-1 flex flex-wrap gap-1">
                            {task.labels.map((label) => (
                                <span key={label.id} className="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-600">
                                    <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: label.color ?? '#475569' }} />
                                    {label.name}
                                </span>
                            ))}
                        </div>
                    )}
                </div>
            </div>
            <Badge tone={statusTone[task.status]}>{statusLabels[task.status]}</Badge>
            <span className="inline-flex items-center gap-2 text-xs font-medium text-slate-600"><PriorityDot priority={task.priority} />{priorityLabels[task.priority]}</span>
            <span className="truncate text-xs font-medium text-slate-600">{task.assignee?.name ?? 'Unassigned'}</span>
            <DueDate date={task.due_date} status={task.status} />
            <span className="text-xs font-semibold text-slate-400 opacity-0 group-hover:opacity-100">Open</span>
        </div>
    );
}
