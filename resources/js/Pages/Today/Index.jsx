import { Badge, EmptyState, Panel, PageSection, primaryButton, secondaryButton, priorityTone, statusTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';

const titles = {
    overdue: 'Overdue',
    due_today: 'Due Today',
    upcoming: 'Upcoming Next 7 Days',
    no_due_date: 'No Due Date',
    blocked: 'Blocked',
    waiting: 'Waiting',
};

const statusLabels = {
    todo: 'To do',
    in_progress: 'In progress',
    blocked: 'Blocked',
    review: 'Review',
    completed: 'Completed',
    archived: 'Archived',
    waiting: 'Waiting',
};

const priorityLabels = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    urgent: 'Urgent',
};

const typeLabels = {
    task: 'Task',
    follow_up: 'Follow up',
    waiting_for: 'Waiting for',
    decision: 'Decision',
    blocker: 'Blocker',
    risk: 'Risk',
    approval: 'Approval',
    habit: 'Habit',
    admin: 'Admin',
};

function SummaryCard({ label, value, tone }) {
    return (
        <Panel className="p-5">
            <div className="text-sm font-semibold text-slate-500">{label}</div>
            <div className={`mt-3 text-3xl font-bold tracking-tight ${tone}`}>{value}</div>
        </Panel>
    );
}

function TaskNoteForm({ task }) {
    const { data, setData, post, processing, reset } = useForm({ body: '' });

    const submit = (event) => {
        event.preventDefault();
        post(route('tasks.comments.store', task.id), {
            preserveScroll: true,
            onSuccess: () => reset('body'),
        });
    };

    return (
        <form onSubmit={submit} className="mt-3 flex gap-2">
            <input
                value={data.body}
                onChange={(event) => setData('body', event.target.value)}
                placeholder="Add a note..."
                className="min-w-0 flex-1 rounded-xl border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500"
            />
            <button type="submit" disabled={processing || !data.body} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-sm disabled:opacity-50">
                Note
            </button>
        </form>
    );
}

function TaskRow({ task }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <Link href={route('tasks.show', task.id)} className="font-bold text-slate-950 hover:text-slate-700">
                        {task.title}
                    </Link>
                    <div className="mt-2 flex flex-wrap gap-2 text-xs text-slate-500">
                        <span>{task.project?.name ?? 'No project'}</span>
                        <span>{task.workspace?.name ?? 'No workspace'}</span>
                        <span>{task.area?.name ?? 'No area'}</span>
                        <span>{task.portfolio?.name ?? 'No portfolio'}</span>
                        <span>{task.due_date ?? 'No due date'}</span>
                    </div>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Badge tone={statusTone[task.status]}>{statusLabels[task.status] ?? task.status}</Badge>
                        <Badge tone={priorityTone[task.priority]}>{priorityLabels[task.priority] ?? task.priority}</Badge>
                        {task.task_type && <Badge>{typeLabels[task.task_type] ?? task.task_type}</Badge>}
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={() => router.patch(route('tasks.complete', task.id), {}, { preserveScroll: true })} className={primaryButton}>
                        Mark done
                    </button>
                    <button type="button" onClick={() => router.patch(route('today.tasks.tomorrow', task.id), {}, { preserveScroll: true })} className={secondaryButton}>
                        Tomorrow
                    </button>
                    <Link href={route('tasks.show', task.id)} className={secondaryButton}>
                        Open
                    </Link>
                </div>
            </div>
            <TaskNoteForm task={task} />
        </div>
    );
}

function TaskSection({ title, tasks }) {
    return (
        <PageSection title={title} description={`${tasks.length} task(s)`}>
            {tasks.length === 0 ? (
                <div className="p-5 text-sm text-slate-500">Nothing in this section.</div>
            ) : (
                <div className="space-y-3 p-4">
                    {tasks.map((task) => <TaskRow key={task.id} task={task} />)}
                </div>
            )}
        </PageSection>
    );
}

export default function Index({ groups, focus, summary }) {
    const blockedWaiting = [...groups.blocked, ...groups.waiting];

    return (
        <AuthenticatedLayout title="Today Command Center" subtitle="Daily execution across all current Friday work.">
            <Head title="Today" />

            <div className="space-y-6">
                <Panel className="overflow-hidden">
                    <div className="bg-gradient-to-br from-white via-slate-50 to-emerald-50/70 p-6">
                        <Badge tone="bg-emerald-50 text-emerald-700 ring-emerald-100">Daily Execution System</Badge>
                        <h2 className="mt-4 text-3xl font-bold tracking-tight text-slate-950">Today Command Center</h2>
                        <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                            A rule-based daily cockpit for what is overdue, due today, coming next, blocked, or waiting.
                            Slack briefings use the same task selection logic.
                        </p>
                    </div>
                </Panel>

                <section className="grid gap-4 md:grid-cols-4">
                    <SummaryCard label="Overdue" value={summary.overdue} tone="text-rose-600" />
                    <SummaryCard label="Due today" value={summary.due_today} tone="text-amber-600" />
                    <SummaryCard label="Upcoming" value={summary.upcoming} tone="text-blue-600" />
                    <SummaryCard label="Blocked / waiting" value={summary.blocked_waiting} tone="text-violet-600" />
                </section>

                <PageSection title="Top 3 Focus" description="Deterministic priority: overdue urgent/high, due today urgent/high, overdue medium, due today medium, upcoming high.">
                    {focus.length === 0 ? (
                        <EmptyState title="No focus tasks selected" description="Create due or high-priority tasks to populate this section." />
                    ) : (
                        <div className="space-y-3 p-4">{focus.map((task) => <TaskRow key={task.id} task={task} />)}</div>
                    )}
                </PageSection>

                <TaskSection title={titles.overdue} tasks={groups.overdue} />
                <TaskSection title={titles.due_today} tasks={groups.due_today} />
                <TaskSection title={titles.upcoming} tasks={groups.upcoming} />
                <TaskSection title={titles.no_due_date} tasks={groups.no_due_date} />
                <TaskSection title="Blocked / Waiting" tasks={blockedWaiting} />
            </div>
        </AuthenticatedLayout>
    );
}
