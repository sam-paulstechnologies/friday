import { Badge, DueDate, EmptyState, Panel, PageSection, inputClass, primaryButton, secondaryButton, priorityTone, statusTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';

const titles = {
    overdue: 'Overdue',
    missed_yesterday: 'Missed Yesterday',
    due_today: 'Due Today',
    scheduled_today: 'Scheduled Today',
    upcoming: 'Upcoming Next 7 Days',
    no_due_date: 'No Due Date',
    blocked: 'Blocked',
    waiting: 'Waiting',
    completed_today: 'Completed Today',
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
        <Panel className="p-5 transition duration-200 hover:-translate-y-0.5 hover:shadow-md hover:shadow-slate-200/70">
            <div className="text-xs font-bold uppercase tracking-wide text-slate-400">{label}</div>
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
        <form onSubmit={submit} className="mt-4 flex flex-col gap-2 sm:flex-row">
            <input
                value={data.body}
                onChange={(event) => setData('body', event.target.value)}
                placeholder="Add a note..."
                className={`${inputClass} min-w-0 flex-1`}
            />
            <button type="submit" disabled={processing || !data.body} className={secondaryButton}>
                Note
            </button>
        </form>
    );
}

function TaskRow({ task, completed = false }) {
    return (
        <div className={`rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60 transition duration-200 hover:border-slate-300 hover:shadow-md ${completed ? 'opacity-80' : ''}`}>
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <Link href={route('tasks.show', task.id)} className={`font-bold text-slate-950 hover:text-slate-700 ${completed ? 'line-through decoration-slate-300' : ''}`}>
                        {task.title}
                    </Link>
                    {task.missed_yesterday && !completed && (
                        <div className="mt-2 inline-flex rounded-md bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700 ring-1 ring-amber-100">Missed yesterday</div>
                    )}
                    <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        <span>{task.project?.name ?? 'No project'}</span>
                        <span>{task.workspace?.name ?? 'No workspace'}</span>
                        <span>{task.area?.name ?? 'No area'}</span>
                        <span>{task.portfolio?.name ?? 'No portfolio'}</span>
                        <DueDate date={task.due_date} status={task.status} />
                    </div>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Badge tone={statusTone[task.status]}>{statusLabels[task.status] ?? task.status}</Badge>
                        <Badge tone={priorityTone[task.priority]}>{priorityLabels[task.priority] ?? task.priority}</Badge>
                        {task.task_type && <Badge>{typeLabels[task.task_type] ?? task.task_type}</Badge>}
                    </div>
                </div>
                <div className="flex flex-wrap gap-2 lg:justify-end">
                    {!completed && (
                        <>
                            <button type="button" onClick={() => router.patch(route('tasks.complete', task.id), {}, { preserveScroll: true })} className={primaryButton}>
                                Mark done
                            </button>
                            <button type="button" onClick={() => router.patch(route('today.tasks.today', task.id), {}, { preserveScroll: true })} className={secondaryButton}>
                                Today
                            </button>
                            <button type="button" onClick={() => router.patch(route('today.tasks.tomorrow', task.id), {}, { preserveScroll: true })} className={secondaryButton}>
                                Tomorrow
                            </button>
                            <button type="button" onClick={() => router.patch(route('today.tasks.snooze', task.id), {}, { preserveScroll: true })} className={secondaryButton}>
                                Snooze
                            </button>
                        </>
                    )}
                    <Link href={route('tasks.show', task.id)} className={secondaryButton}>
                        Open
                    </Link>
                </div>
            </div>
            {!completed && <TaskNoteForm task={task} />}
        </div>
    );
}

function TaskSection({ title, tasks, completed = false, description = null }) {
    return (
        <PageSection title={title} description={description ?? `${tasks.length} task(s)`}>
            {tasks.length === 0 ? (
                <EmptyState title="Nothing in this section" description="Tasks will appear here when they match this daily planning bucket." />
            ) : (
                <div className="space-y-3 p-4">
                    {tasks.map((task) => <TaskRow key={task.id} task={task} completed={completed} />)}
                </div>
            )}
        </PageSection>
    );
}

function ReadingPanel({ reading }) {
    if (!reading?.has_plan) {
        return null;
    }

    return (
        <PageSection title="Reading / Learning" description="What is due today">
            <div className="p-4">
                <div className="rounded-lg border border-slate-200 bg-white p-4">
                    <div className="text-sm font-semibold text-slate-950">{reading.today_label}</div>
                    <div className="mt-2 text-sm text-slate-500">{reading.today_completed_chapters} / {reading.today_total_chapters} chapters complete</div>
                    {reading.missed_yesterday && (
                        <p className="mt-3 rounded-md bg-amber-50 p-2 text-xs leading-5 text-amber-800">Missed yesterday: {reading.missed_yesterday_label}</p>
                    )}
                    <Link href={reading.continue_url} className={`${secondaryButton} mt-3`}>Continue reading</Link>
                </div>
            </div>
        </PageSection>
    );
}

export default function Index({ groups, focus, summary, dailyReview, reading }) {
    const blockedWaiting = [...groups.blocked, ...groups.waiting];
    const activeToday = [...groups.missed_yesterday, ...groups.overdue.filter((task) => !task.missed_yesterday), ...groups.due_today, ...groups.scheduled_today];

    return (
        <AuthenticatedLayout title="My Day" subtitle="Today, missed work, reading, and completion review.">
            <Head title="My Day" />

            <div data-testid="today-page" className="space-y-6">
                <Panel className="overflow-hidden">
                    <div className="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_260px] lg:items-center">
                        <div>
                            <Badge tone="bg-emerald-50 text-emerald-700 ring-emerald-100">Daily Execution</Badge>
                            <h2 className="mt-4 text-3xl font-bold tracking-tight text-slate-950">My Day</h2>
                            <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">{dailyReview.morning}</p>
                        </div>
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Active today</div>
                            <div className="mt-1 text-3xl font-bold text-slate-950">{activeToday.length}</div>
                            <div className="mt-2 text-xs leading-5 text-slate-500">Missed and overdue work stays visible until you move it or complete it.</div>
                        </div>
                    </div>
                </Panel>

                <section className="grid gap-4 md:grid-cols-5">
                    <SummaryCard label="Overdue" value={summary.overdue} tone="text-rose-600" />
                    <SummaryCard label="Missed yesterday" value={summary.missed_yesterday} tone="text-amber-600" />
                    <SummaryCard label="Due today" value={summary.due_today} tone="text-amber-600" />
                    <SummaryCard label="Scheduled" value={summary.scheduled_today} tone="text-blue-600" />
                    <SummaryCard label="Done today" value={summary.completed_today} tone="text-emerald-600" />
                </section>

                <PageSection title="Top 3 Focus" description="Highest priority work to clear first.">
                    {focus.length === 0 ? (
                        <EmptyState title="No focus tasks selected" description="Create due or high-priority tasks to populate this section." />
                    ) : (
                        <div className="space-y-3 p-4">{focus.map((task) => <TaskRow key={task.id} task={task} />)}</div>
                    )}
                </PageSection>

                <TaskSection title="Active Today" tasks={activeToday} description={`${activeToday.length} task(s), ordered with missed and overdue work first`} />
                <ReadingPanel reading={reading} />
                <TaskSection title={titles.completed_today} tasks={groups.completed_today} completed description={`${groups.completed_today.length} completed today`} />
                <TaskSection title={titles.upcoming} tasks={groups.upcoming} />
                <TaskSection title={titles.no_due_date} tasks={groups.no_due_date} />
                <TaskSection title="Blocked / Waiting" tasks={blockedWaiting} />
            </div>
        </AuthenticatedLayout>
    );
}
