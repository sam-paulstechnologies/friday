import { Avatar, Badge, DueDate, EmptyState, PageSection, ProgressBar, Toolbar, inputClass, priorityTone, secondaryButton, statusTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const summaryLabels = {
    total_open_tasks: 'Open tasks',
    total_overdue_tasks: 'Overdue',
    total_due_this_week: 'Due this week',
    total_unassigned_tasks: 'Unassigned',
    overloaded_people: 'Overloaded people',
    available_people: 'Available people',
};

const statusToneMap = {
    Available: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    Healthy: 'bg-cyan-50 text-cyan-700 ring-cyan-100',
    Busy: 'bg-amber-50 text-amber-700 ring-amber-100',
    Overloaded: 'bg-rose-50 text-rose-700 ring-rose-100',
};

export default function Index({ filters, options, summary, assigneeWorkloads, unassignedTasks, portfolioWorkloads, projectWorkloads, weeklyBuckets }) {
    const [values, setValues] = useState(filters);

    useEffect(() => {
        const timeout = setTimeout(() => {
            router.get(route('workload.index'), compact(values), { preserveState: true, replace: true });
        }, 250);

        return () => clearTimeout(timeout);
    }, [values]);

    const clearFilters = () => setValues({
        assignee_id: '',
        area_id: '',
        portfolio_id: '',
        project_id: '',
        priority: '',
        due_bucket: '',
    });

    return (
        <AuthenticatedLayout title="Workload Planning" subtitle="Resource pressure, overdue work, upcoming capacity, and unassigned tasks.">
            <Head title="Workload Planning" />

            <div className="space-y-5">
                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white p-4">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 className="text-xl font-bold tracking-tight text-slate-950">Friday resource board</h2>
                            <p className="mt-1 text-sm text-slate-500">Live workload scoring from task status, priority, due dates, assignees, projects, and portfolios.</p>
                        </div>
                        <button type="button" onClick={clearFilters} className={secondaryButton}>Clear filters</button>
                    </div>
                </div>

                <Toolbar>
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                        <Select value={values.assignee_id} onChange={(assignee_id) => setValues({ ...values, assignee_id })}>
                            <option value="">All assignees</option>
                            <option value="unassigned">Unassigned</option>
                            {options.users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                        </Select>
                        <Select value={values.area_id} onChange={(area_id) => setValues({ ...values, area_id, portfolio_id: '', project_id: '' })}>
                            <option value="">All areas</option>
                            {options.areas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                        </Select>
                        <Select value={values.portfolio_id} onChange={(portfolio_id) => setValues({ ...values, portfolio_id, project_id: '' })}>
                            <option value="">All portfolios</option>
                            {options.portfolios
                                .filter((portfolio) => !values.area_id || String(portfolio.area_id) === String(values.area_id))
                                .map((portfolio) => <option key={portfolio.id} value={portfolio.id}>{portfolio.name}</option>)}
                        </Select>
                        <Select value={values.project_id} onChange={(project_id) => setValues({ ...values, project_id })}>
                            <option value="">All projects</option>
                            {options.projects
                                .filter((project) => !values.area_id || String(project.area_id) === String(values.area_id))
                                .filter((project) => !values.portfolio_id || String(project.portfolio_id) === String(values.portfolio_id))
                                .map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                        </Select>
                        <Select value={values.priority} onChange={(priority) => setValues({ ...values, priority })}>
                            <option value="">All priorities</option>
                            {options.priorities.map((priority) => <option key={priority} value={priority}>{titleCase(priority)}</option>)}
                        </Select>
                        <Select value={values.due_bucket} onChange={(due_bucket) => setValues({ ...values, due_bucket })}>
                            <option value="">All due dates</option>
                            {options.dueBuckets.map((bucket) => <option key={bucket.value} value={bucket.value}>{bucket.label}</option>)}
                        </Select>
                    </div>
                </Toolbar>

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    {Object.entries(summaryLabels).map(([key, label]) => (
                        <KpiCard key={key} label={label} value={summary[key] ?? 0} alert={['total_overdue_tasks', 'overloaded_people'].includes(key) && Number(summary[key] ?? 0) > 0} />
                    ))}
                </section>

                <PageSection title="Team workload" description="Score: urgent 5, high 3, medium 2, low 1, plus overdue 2, due today 1, blocked 2.">
                    {assigneeWorkloads.length === 0 ? (
                        <EmptyState title="No workload data" description="Open tasks and workspace users will appear here." />
                    ) : (
                        <div className="grid gap-4 p-4 xl:grid-cols-2">
                            {assigneeWorkloads.map((workload) => <AssigneeCard key={workload.id} workload={workload} />)}
                        </div>
                    )}
                </PageSection>

                <section className="grid gap-5 xl:grid-cols-[1fr_1fr]">
                    <PageSection title="Unassigned work" description={`${summary.total_unassigned_tasks} open tasks need an owner.`}>
                        {unassignedTasks.length === 0 ? (
                            <EmptyState title="No unassigned open tasks" description="Every filtered open task has an assignee." />
                        ) : (
                            <TaskList tasks={unassignedTasks} />
                        )}
                    </PageSection>

                    <PageSection title="Upcoming weekly workload" description="Active work grouped by due pressure.">
                        <div className="grid gap-3 p-4">
                            {weeklyBuckets.map((bucket) => <DueBucket key={bucket.key} bucket={bucket} />)}
                        </div>
                    </PageSection>
                </section>

                <PageSection title="Portfolio workload" description="Portfolio pressure based on filtered open tasks.">
                    <ResponsiveTable
                        emptyTitle="No portfolio workload"
                        columns={['Portfolio', 'Area', 'Open', 'Overdue', 'Urgent/High', 'Top assignee', 'Pressure']}
                        rows={portfolioWorkloads.map((portfolio) => ({
                            key: portfolio.id,
                            cells: [
                                <Link href={portfolio.href} className="font-bold text-slate-950 hover:text-slate-700">{portfolio.name}</Link>,
                                portfolio.area?.name ?? 'No area',
                                portfolio.open_tasks,
                                <AlertCount value={portfolio.overdue_tasks} />,
                                <AlertCount value={portfolio.urgent_high_open_tasks} amber />,
                                portfolio.top_assignee ? `${portfolio.top_assignee.name} (${portfolio.top_assignee.count})` : 'None',
                                <Pressure value={portfolio.pressure} />,
                            ],
                        }))}
                    />
                </PageSection>

                <PageSection title="Project workload" description="Projects with active filtered work, sorted by workload score.">
                    <ResponsiveTable
                        emptyTitle="No project workload"
                        columns={['Project', 'Portfolio', 'Open', 'Overdue', 'Due this week', 'Unassigned', 'Score', 'Pressure']}
                        rows={projectWorkloads.map((project) => ({
                            key: project.id,
                            cells: [
                                <Link href={project.href} className="font-bold text-slate-950 hover:text-slate-700">{project.name}</Link>,
                                project.portfolio?.name ?? 'No portfolio',
                                project.open_tasks,
                                <AlertCount value={project.overdue_tasks} />,
                                project.due_this_week,
                                <AlertCount value={project.unassigned_tasks} amber />,
                                project.workload_score,
                                <Pressure value={project.pressure} />,
                            ],
                        }))}
                    />
                </PageSection>
            </div>
        </AuthenticatedLayout>
    );
}

function Select({ value, onChange, children }) {
    return <select value={value ?? ''} onChange={(event) => onChange(event.target.value)} className={inputClass}>{children}</select>;
}

function KpiCard({ label, value, alert = false }) {
    return (
        <article className="rounded-lg border border-slate-200 bg-white p-3">
            <div className="text-[11px] font-semibold uppercase text-slate-400">{label}</div>
            <div className={`mt-2 text-2xl font-semibold tracking-tight ${alert ? 'text-rose-600' : 'text-slate-950'}`}>{value}</div>
        </article>
    );
}

function AssigneeCard({ workload }) {
    return (
        <article className="rounded-lg border border-slate-200 bg-slate-50/70 p-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex min-w-0 items-center gap-3">
                    <Avatar name={workload.name} size="lg" />
                    <div className="min-w-0">
                        <Link href={workload.href} className="truncate text-base font-semibold text-slate-950 hover:text-slate-700">{workload.name}</Link>
                        <div className="mt-1 text-sm font-semibold text-slate-500">Score {workload.workload_score}</div>
                    </div>
                </div>
                <Badge tone={statusToneMap[workload.classification]}>{workload.classification}</Badge>
            </div>

            <ProgressBar value={workload.pressure} className="mt-4" />

            <div className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-5">
                <MiniMetric label="Open" value={workload.total_open_tasks} />
                <MiniMetric label="Overdue" value={workload.overdue_tasks} tone="text-rose-600" />
                <MiniMetric label="Today" value={workload.due_today} />
                <MiniMetric label="Week" value={workload.due_this_week} />
                <MiniMetric label="Urgent/high" value={workload.urgent_open_tasks + workload.high_priority_open_tasks} tone="text-amber-600" />
                <MiniMetric label="Blocked" value={workload.blocked_tasks} tone="text-rose-600" />
                <MiniMetric label="Review" value={workload.review_tasks} />
                <MiniMetric label="No due" value={workload.no_due_date_tasks} />
            </div>
        </article>
    );
}

function MiniMetric({ label, value, tone = 'text-slate-950' }) {
    return (
        <div className="rounded-lg border border-slate-100 bg-white px-3 py-2">
            <div className="text-[11px] font-semibold uppercase text-slate-400">{label}</div>
            <div className={`mt-1 text-lg font-semibold ${tone}`}>{value}</div>
        </div>
    );
}

function TaskList({ tasks }) {
    return (
        <div className="overflow-hidden">
            {tasks.map((task) => (
                <Link key={task.id} href={task.href} className="grid gap-2 border-b border-slate-100 px-4 py-3 text-sm last:border-b-0 hover:bg-slate-50 md:grid-cols-[1fr_110px_120px_140px_140px] md:items-center">
                    <div className="min-w-0">
                        <div className="truncate font-bold text-slate-950">{task.title}</div>
                        <div className="mt-1 text-xs font-semibold text-slate-500">{task.area?.name ?? 'No area'}</div>
                    </div>
                    <Badge tone={priorityTone[task.priority]}>{titleCase(task.priority)}</Badge>
                    <DueDate date={task.due_date} status={task.status} />
                    <span className="truncate font-semibold text-slate-600">{task.project?.name ?? 'No project'}</span>
                    <span className="truncate font-semibold text-slate-600">{task.portfolio?.name ?? 'No portfolio'}</span>
                </Link>
            ))}
        </div>
    );
}

function DueBucket({ bucket }) {
    return (
        <div className="rounded-lg border border-slate-100 bg-slate-50 p-3">
            <div className="flex items-center justify-between gap-3">
                <div className="font-bold text-slate-950">{bucket.label}</div>
                <Badge tone={bucket.key === 'overdue' && bucket.count > 0 ? statusTone.blocked : undefined}>{bucket.count}</Badge>
            </div>
            {bucket.tasks.length > 0 && (
                <div className="mt-3 space-y-2">
                    {bucket.tasks.map((task) => (
                        <Link key={task.id} href={task.href} className="flex items-center justify-between gap-3 rounded-md bg-white px-3 py-2 text-sm ring-1 ring-slate-100 hover:bg-slate-50">
                            <span className="min-w-0 truncate font-semibold text-slate-800">{task.title}</span>
                            <Badge tone={priorityTone[task.priority]}>{titleCase(task.priority)}</Badge>
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}

function ResponsiveTable({ columns, rows, emptyTitle }) {
    if (rows.length === 0) {
        return <EmptyState title={emptyTitle} description="Filtered open tasks will populate this section." />;
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-left text-sm">
                <thead>
                    <tr>
                        {columns.map((column) => (
                            <th key={column} className="border-b border-slate-100 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-400 first:pl-4 last:pr-4">
                                {column}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.key} className="hover:bg-slate-50/70">
                            {row.cells.map((cell, index) => (
                                <td key={index} className="border-b border-slate-100 px-3 py-2 align-middle font-medium text-slate-700 first:pl-4 last:pr-4">
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

function AlertCount({ value, amber = false }) {
    const tone = value > 0 ? (amber ? 'text-amber-600' : 'text-rose-600') : 'text-slate-700';

    return <span className={`font-black ${tone}`}>{value}</span>;
}

function Pressure({ value }) {
    return (
        <div className="min-w-36">
            <div className="mb-1 text-xs font-bold text-slate-500">{value}%</div>
            <ProgressBar value={value} />
        </div>
    );
}

function compact(values) {
    return Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '' && value !== null && value !== undefined));
}

function titleCase(value) {
    return String(value ?? 'None').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}
