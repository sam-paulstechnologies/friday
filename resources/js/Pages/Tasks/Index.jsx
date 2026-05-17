import { Badge, EmptyState, Toolbar, inputClass, primaryButton, priorityTone, statusTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { priorityLabels, statusLabels } from './Partials/TaskForm';

function dateBucket(task) {
    if (!task.due_date) return 'No due date';
    const today = new Date().toISOString().slice(0, 10);
    if (task.due_date < today && task.status !== 'completed') return 'Overdue';
    if (task.due_date === today) return 'Today';
    return 'Upcoming';
}

function TaskRow({ task }) {
    return (
        <Link href={route('tasks.show', task.id)} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60 transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md lg:grid-cols-[1fr_140px_120px_150px] lg:items-center">
            <div className="min-w-0">
                <div className="flex items-start gap-3">
                    <span className={`mt-1 h-4 w-4 rounded-full border-2 ${task.status === 'completed' ? 'border-emerald-500 bg-emerald-500' : 'border-slate-300 bg-white'}`} />
                    <div className="min-w-0">
                        <div className="truncate font-semibold text-slate-950">{task.title}</div>
                        <div className="mt-1 truncate text-sm text-slate-500">
                            {task.project?.name ?? task.section ?? 'No project'}
                            {task.area?.name ? ` / ${task.area.name}` : ''}
                            {task.portfolio?.name ? ` / ${task.portfolio.name}` : ''}
                        </div>
                    </div>
                </div>
            </div>
            <Badge tone={statusTone[task.status]}>{statusLabels[task.status]}</Badge>
            <div className="flex flex-wrap gap-2">
                <Badge tone={priorityTone[task.priority]}>{priorityLabels[task.priority]}</Badge>
                {task.task_type && <Badge>{task.task_type.replace('_', ' ')}</Badge>}
            </div>
            <div className="text-sm font-medium text-slate-600 lg:text-right">{task.due_date ?? 'No due date'}</div>
        </Link>
    );
}

export default function Index({ tasks, filters, statuses, priorities, projects }) {
    const [values, setValues] = useState(filters);
    const grouped = useMemo(() => {
        return tasks.reduce((groups, task) => {
            const key = dateBucket(task);
            return { ...groups, [key]: [...(groups[key] ?? []), task] };
        }, {});
    }, [tasks]);

    useEffect(() => {
        const timeout = setTimeout(() => {
            router.get(route('tasks.index'), values, { preserveState: true, replace: true });
        }, 250);
        return () => clearTimeout(timeout);
    }, [values]);

    return (
        <AuthenticatedLayout title="My Tasks" subtitle="A focused workspace for assigned and reported work.">
            <Head title="My Tasks" />

            <div className="space-y-5">
                <div className="flex flex-col gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/70 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 className="text-xl font-bold tracking-tight text-slate-950">Task queue</h2>
                        <p className="mt-1 text-sm text-slate-500">Grouped by due date from the tasks already loaded on this page.</p>
                    </div>
                    <Link href={route('tasks.create')} className={primaryButton}>Create Task</Link>
                </div>

                <Toolbar>
                    <div className="grid gap-3 md:grid-cols-4">
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
                </Toolbar>

                {tasks.length === 0 ? (
                    <div className="rounded-3xl border border-slate-200 bg-white">
                        <EmptyState title="No tasks found" description="Create a task or adjust filters to see work here." />
                    </div>
                ) : (
                    ['Overdue', 'Today', 'Upcoming', 'No due date'].map((group) => (
                        <section key={group} className="space-y-3">
                            <div className="flex items-center gap-3">
                                <h3 className="text-sm font-bold uppercase tracking-[0.16em] text-slate-400">{group}</h3>
                                <span className="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-bold text-slate-600">{grouped[group]?.length ?? 0}</span>
                            </div>
                            {(grouped[group] ?? []).length === 0 ? (
                                <div className="rounded-2xl border border-dashed border-slate-200 bg-white/70 p-4 text-sm text-slate-500">No tasks in this group.</div>
                            ) : (
                                <div className="space-y-3">{grouped[group].map((task) => <TaskRow key={task.id} task={task} />)}</div>
                            )}
                        </section>
                    ))
                )}
            </div>
        </AuthenticatedLayout>
    );
}
