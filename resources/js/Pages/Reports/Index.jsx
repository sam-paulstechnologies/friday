import { Badge, DueDate, EmptyState, PageSection, ProgressBar, Toolbar, inputClass, secondaryButton, statusTone, priorityTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const labels = {
    total_open_tasks: 'Open tasks',
    completed_tasks: 'Completed tasks',
    overdue_tasks: 'Overdue tasks',
    due_today: 'Due today',
    due_this_week: 'Due this week',
    total_projects: 'Total projects',
    active_projects: 'Active projects',
    completed_projects: 'Completed projects',
    active_portfolios: 'Active portfolios',
    active_areas: 'Active areas',
};

const statusLabels = {
    todo: 'To do',
    in_progress: 'In progress',
    blocked: 'Blocked',
    review: 'Review',
    completed: 'Completed',
    archived: 'Archived',
    active: 'Active',
    on_hold: 'On hold',
};

export default function Index({ filters, options, summary, portfolioMetrics, projectMetrics, taskHealth, launchReadiness, trends }) {
    const [values, setValues] = useState(filters);

    useEffect(() => {
        const timeout = setTimeout(() => {
            router.get(route('reports.index'), compact(values), { preserveState: true, replace: true });
        }, 250);

        return () => clearTimeout(timeout);
    }, [values]);

    return (
        <AuthenticatedLayout title="Reports" subtitle="Operational metrics for portfolios, projects, tasks, and launch readiness.">
            <Head title="Reports" />

            <div className="space-y-5">
                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white p-4">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 className="text-xl font-bold tracking-tight text-slate-950">Miriam metrics cockpit</h2>
                            <p className="mt-1 text-sm text-slate-500">Live counts from current tasks, projects, portfolios, and areas.</p>
                        </div>
                        <Link href={route('dashboard')} className={secondaryButton}>Back to Dashboard</Link>
                    </div>
                </div>

                <Toolbar>
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                        <Select value={values.area_id} onChange={(area_id) => setValues({ ...values, area_id, portfolio_id: '' })}>
                            <option value="">All areas</option>
                            {options.areas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                        </Select>
                        <Select value={values.portfolio_id} onChange={(portfolio_id) => setValues({ ...values, portfolio_id })}>
                            <option value="">All portfolios</option>
                            {options.portfolios
                                .filter((portfolio) => !values.area_id || String(portfolio.area_id) === String(values.area_id))
                                .map((portfolio) => <option key={portfolio.id} value={portfolio.id}>{portfolio.name}</option>)}
                        </Select>
                        <Select value={values.status} onChange={(status) => setValues({ ...values, status })}>
                            <option value="">All statuses</option>
                            {options.statuses.map((status) => <option key={status} value={status}>{statusLabels[status] ?? titleCase(status)}</option>)}
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

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {Object.entries(labels).map(([key, label]) => (
                        <KpiCard key={key} label={label} value={summary[key] ?? 0} alert={['overdue_tasks', 'due_today'].includes(key) && Number(summary[key] ?? 0) > 0} />
                    ))}
                </section>

                <section className="grid gap-5 xl:grid-cols-[1.5fr_1fr]">
                    <PageSection title="Launch readiness" description="Dedicated readiness view for SayaraForce and ChurchForce.">
                        {launchReadiness.length === 0 ? (
                            <EmptyState title="No launch portfolios found" description="SayaraForce and ChurchForce will appear here when those portfolios exist." />
                        ) : (
                            <div className="grid gap-4 p-4">
                                {launchReadiness.map((portfolio) => <LaunchCard key={portfolio.id} portfolio={portfolio} />)}
                            </div>
                        )}
                    </PageSection>

                    <PageSection title="Simple trend data" description="Completed metrics use completed_at where available.">
                        <div className="grid gap-3 p-4">
                            <MiniMetric label="Tasks created this week" value={trends.created_this_week} />
                            <MiniMetric label="Tasks completed this week" value={trends.completed_this_week} />
                            <MiniMetric label="Recently completed/updated" value={trends.recently_completed_or_updated} />
                            <MiniMetric label="Tasks overdue this week" value={trends.overdue_this_week} tone="text-rose-600" />
                        </div>
                    </PageSection>
                </section>

                <PageSection title="Portfolio readiness and progress" description="Portfolio-level project, task, overdue, and progress metrics.">
                    <ResponsiveTable
                        emptyTitle="No portfolio metrics"
                        columns={['Portfolio', 'Area', 'Projects', 'Tasks', 'Open', 'Completed', 'Overdue', 'Progress', 'Status']}
                        rows={portfolioMetrics.map((portfolio) => ({
                            key: portfolio.id,
                            cells: [
                                <Link href={route('portfolios.show', portfolio.id)} className="font-bold text-slate-950 hover:text-slate-700">{portfolio.name}</Link>,
                                portfolio.area?.name ?? 'No area',
                                portfolio.total_projects,
                                portfolio.total_tasks,
                                portfolio.open_tasks,
                                portfolio.completed_tasks,
                                <span className={portfolio.overdue_tasks > 0 ? 'font-bold text-rose-600' : ''}>{portfolio.overdue_tasks}</span>,
                                <ProgressCell value={portfolio.progress} />,
                                <Badge tone={statusTone[portfolio.status] ?? statusTone.active}>{statusLabels[portfolio.status] ?? titleCase(portfolio.status)}</Badge>,
                            ],
                        }))}
                    />
                </PageSection>

                <PageSection title="Project progress report" description="Project-level completion, overdue pressure, and due dates.">
                    <ResponsiveTable
                        emptyTitle="No project metrics"
                        columns={['Project', 'Portfolio', 'Status', 'Tasks', 'Open', 'Completed', 'Overdue', 'Progress', 'Due']}
                        rows={projectMetrics.map((project) => ({
                            key: project.id,
                            cells: [
                                <Link href={route('projects.show', project.id)} className="font-bold text-slate-950 hover:text-slate-700">{project.name}</Link>,
                                project.portfolio?.name ?? 'No portfolio',
                                <Badge tone={statusTone[project.status] ?? statusTone.active}>{statusLabels[project.status] ?? titleCase(project.status)}</Badge>,
                                project.total_tasks,
                                project.open_tasks,
                                project.completed_tasks,
                                <span className={project.overdue_tasks > 0 ? 'font-bold text-rose-600' : ''}>{project.overdue_tasks}</span>,
                                <ProgressCell value={project.progress} />,
                                <DueDate date={project.due_date} status={project.status} />,
                            ],
                        }))}
                    />
                </PageSection>

                <section className="grid gap-5 xl:grid-cols-2">
                    <HealthPanel title="Tasks by status" items={taskHealth.byStatus} />
                    <HealthPanel title="Tasks by priority" items={taskHealth.byPriority} priority />
                    <HealthPanel title="Tasks by due date" items={taskHealth.byDueBucket} />
                    <HealthPanel title="Tasks by portfolio" items={taskHealth.byPortfolio} />
                    <HealthPanel title="Tasks by project" items={taskHealth.byProject} className="xl:col-span-2" />
                </section>
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

function MiniMetric({ label, value, tone = 'text-slate-950' }) {
    return (
        <div className="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
            <div className="text-[11px] font-semibold uppercase text-slate-400">{label}</div>
            <div className={`mt-1 text-lg font-semibold ${tone}`}>{value}</div>
        </div>
    );
}

function LaunchCard({ portfolio }) {
    return (
        <article className="rounded-lg border border-slate-200 bg-slate-50/70 p-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <Link href={route('portfolios.show', portfolio.id)} className="text-base font-semibold text-slate-950 hover:text-slate-700">{portfolio.name}</Link>
                    <p className="mt-1 text-sm text-slate-500">{portfolio.area?.name ?? 'No area'} launch portfolio</p>
                </div>
                <Badge tone={portfolio.overdue_tasks > 0 ? 'bg-rose-50 text-rose-700 ring-rose-100' : 'bg-emerald-50 text-emerald-700 ring-emerald-100'}>
                    {portfolio.completion ?? 0}% complete
                </Badge>
            </div>
            <ProgressBar value={portfolio.completion ?? 0} className="mt-4" />
            <div className="mt-4 grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
                <MiniMetric label="Total" value={portfolio.total_launch_tasks} />
                <MiniMetric label="Completed" value={portfolio.completed_tasks} />
                <MiniMetric label="Open" value={portfolio.open_tasks} />
                <MiniMetric label="Overdue" value={portfolio.overdue_tasks} tone="text-rose-600" />
                <MiniMetric label="Urgent open" value={portfolio.urgent_open_tasks} tone="text-rose-600" />
                <MiniMetric label="High open" value={portfolio.high_open_tasks} tone="text-amber-600" />
            </div>
            <div className="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                {portfolio.next_open_tasks.length === 0 ? (
                    <div className="p-4 text-sm font-semibold text-slate-500">No open launch tasks.</div>
                ) : portfolio.next_open_tasks.map((task) => (
                    <Link key={task.id} href={route('tasks.show', task.id)} className="grid gap-2 border-b border-slate-100 px-3 py-2 text-sm last:border-b-0 hover:bg-slate-50 md:grid-cols-[1fr_120px_120px] md:items-center">
                        <div className="font-semibold text-slate-900">{task.title}</div>
                        <Badge tone={priorityTone[task.priority]}>{titleCase(task.priority)}</Badge>
                        <DueDate date={task.due_date} status={task.status} />
                    </Link>
                ))}
            </div>
        </article>
    );
}

function ResponsiveTable({ columns, rows, emptyTitle }) {
    if (rows.length === 0) {
        return <EmptyState title={emptyTitle} description="Create or update records to populate this report." />;
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

function ProgressCell({ value }) {
    if (value === null || value === undefined) {
        return <span className="text-slate-400">No tasks</span>;
    }

    return (
        <div className="min-w-32">
            <div className="mb-1 text-xs font-bold text-slate-500">{value}%</div>
            <ProgressBar value={value} />
        </div>
    );
}

function HealthPanel({ title, items, priority = false, className = '' }) {
    const max = Math.max(1, ...items.map((item) => Number(item.count)));

    return (
        <PageSection title={title} className={className}>
            {items.length === 0 ? (
                <EmptyState title="No data yet" />
            ) : (
                <div className="space-y-3 p-4">
                    {items.map((item) => {
                        const label = item.label ?? 'None';
                        const percent = Math.round((Number(item.count) / max) * 100);

                        return (
                            <div key={label} className="rounded-lg border border-slate-100 bg-slate-50 p-3">
                                <div className="flex items-center justify-between gap-3">
                                    <div className="flex min-w-0 items-center gap-2">
                                        <Badge tone={priority ? priorityTone[label] : statusTone[label] ?? undefined}>{statusLabels[label] ?? titleCase(label)}</Badge>
                                    </div>
                                    <span className="text-sm font-black text-slate-950">{item.count}</span>
                                </div>
                                <ProgressBar value={percent} className="mt-3" />
                            </div>
                        );
                    })}
                </div>
            )}
        </PageSection>
    );
}

function compact(values) {
    return Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '' && value !== null && value !== undefined));
}

function titleCase(value) {
    return String(value ?? 'None').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}
