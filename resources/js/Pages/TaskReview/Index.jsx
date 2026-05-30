import { Badge, DueDate, EmptyState, PageSection, priorityTone, secondaryButton, statusTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const summaryLabels = {
    total_tasks: 'Total tasks',
    open_tasks: 'Open tasks',
    completed_tasks: 'Completed',
    overdue_tasks: 'Overdue',
    due_today: 'Due today',
    due_this_week: 'Due this week',
    no_due_date: 'No due date',
    urgent_high_open_tasks: 'Urgent/high',
    unassigned_tasks: 'Unassigned',
};

const candidateLabels = {
    possible_urgent_not_urgent: 'Possible urgent tasks that may not be urgent',
    possible_high_priority: 'Possible high-priority tasks',
    possible_overdue_cleanup: 'Possible overdue cleanup',
    unassigned_active_tasks: 'Unassigned active tasks',
    no_due_date_active_tasks: 'No due date active tasks',
    completed_with_active_signals: 'Completed but still showing active signals',
};

export default function Index({ review }) {
    return (
        <AuthenticatedLayout title="Task Review" subtitle="Read-only review pack for cleanup and prioritization before enabling the AI Brain.">
            <Head title="Task Review" />

            <div className="space-y-5">
                <div className="overflow-hidden rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/70">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 className="text-xl font-bold tracking-tight text-slate-950">Miriam task review pack</h2>
                            <p className="mt-1 text-sm text-slate-500">Generated {review.generated_at}. This page does not change task records.</p>
                        </div>
                        <Link href={route('tasks.index')} className={secondaryButton}>Back to Tasks</Link>
                    </div>
                </div>

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {Object.entries(summaryLabels).map(([key, label]) => (
                        <KpiCard key={key} label={label} value={review.summary[key] ?? 0} alert={['overdue_tasks', 'due_today', 'urgent_high_open_tasks'].includes(key) && Number(review.summary[key] ?? 0) > 0} />
                    ))}
                </section>

                <PageSection title="Portfolio summary" description="Area, status pressure, priority mix, and missing due dates by portfolio.">
                    <ResponsiveTable
                        emptyTitle="No portfolios"
                        columns={['Portfolio', 'Area', 'Total', 'Open', 'Completed', 'Overdue', 'Urgent', 'High', 'Medium', 'Low', 'No due']}
                        rows={review.portfolioSummary.map((portfolio) => ({
                            key: portfolio.portfolio,
                            cells: [
                                portfolio.portfolio,
                                portfolio.area,
                                portfolio.total_tasks,
                                portfolio.open,
                                portfolio.completed,
                                <AlertNumber value={portfolio.overdue} />,
                                <AlertNumber value={portfolio.urgent} />,
                                portfolio.high,
                                portfolio.medium,
                                portfolio.low,
                                portfolio.no_due_date,
                            ],
                        }))}
                    />
                </PageSection>

                <section className="grid gap-5 xl:grid-cols-2">
                    {Object.entries(candidateLabels).map(([key, label]) => (
                        <PageSection key={key} title={label} description={`${review.priorityReviewCandidates[key]?.length ?? 0} tasks`}>
                            <TaskList tasks={(review.priorityReviewCandidates[key] ?? []).slice(0, 20)} />
                        </PageSection>
                    ))}
                </section>

                <PageSection title="Full task list" description="All exported task rows with inferred due bucket and ownership.">
                    <ResponsiveTable
                        emptyTitle="No tasks"
                        columns={['ID', 'Area', 'Portfolio', 'Project', 'Title', 'Status', 'Priority', 'Due', 'Bucket', 'Assignee', 'Reporter', 'Notes']}
                        rows={review.tasks.map((task) => ({
                            key: task.id,
                            cells: [
                                task.id,
                                task.area,
                                task.portfolio,
                                task.project,
                                task.title,
                                <Badge tone={statusTone[task.status] ?? statusTone.todo}>{titleCase(task.status)}</Badge>,
                                <Badge tone={priorityTone[task.priority]}>{titleCase(task.priority)}</Badge>,
                                <DueDate date={task.due_date} status={task.status} />,
                                task.due_date_bucket,
                                task.assignee,
                                task.reporter,
                                <span className="line-clamp-2 text-slate-500">{task.summary || 'None'}</span>,
                            ],
                        }))}
                    />
                </PageSection>
            </div>
        </AuthenticatedLayout>
    );
}

function KpiCard({ label, value, alert = false }) {
    return (
        <article className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/70">
            <div className="text-xs font-bold uppercase tracking-[0.14em] text-slate-400">{label}</div>
            <div className={`mt-3 text-3xl font-black tracking-tight ${alert ? 'text-rose-600' : 'text-slate-950'}`}>{value}</div>
        </article>
    );
}

function AlertNumber({ value }) {
    return <span className={Number(value) > 0 ? 'font-bold text-rose-600' : ''}>{value}</span>;
}

function TaskList({ tasks }) {
    if (tasks.length === 0) {
        return <EmptyState title="No candidates" />;
    }

    return (
        <div className="divide-y divide-slate-100">
            {tasks.map((task) => (
                <div key={task.id} className="grid gap-2 px-5 py-3 text-sm md:grid-cols-[1fr_96px_120px] md:items-center">
                    <div>
                        <div className="font-semibold text-slate-950">#{task.id} {task.title}</div>
                        <div className="mt-1 text-xs font-semibold text-slate-500">{task.area} / {task.portfolio} / {task.project}</div>
                    </div>
                    <Badge tone={priorityTone[task.priority]}>{titleCase(task.priority)}</Badge>
                    <DueDate date={task.due_date} status={task.status} />
                </div>
            ))}
        </div>
    );
}

function ResponsiveTable({ columns, rows, emptyTitle }) {
    if (rows.length === 0) {
        return <EmptyState title={emptyTitle} />;
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-left text-sm">
                <thead>
                    <tr>
                        {columns.map((column) => (
                            <th key={column} className="border-b border-slate-100 bg-slate-50 px-4 py-3 text-xs font-bold uppercase tracking-wide text-slate-400 first:pl-5 last:pr-5">
                                {column}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.key} className="hover:bg-slate-50/70">
                            {row.cells.map((cell, index) => (
                                <td key={index} className="max-w-80 border-b border-slate-100 px-4 py-3 align-middle font-semibold text-slate-700 first:pl-5 last:pr-5">
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function titleCase(value) {
    return String(value ?? 'None').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}
