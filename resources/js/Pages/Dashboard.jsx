import { Badge, EmptyState, PageSection, Panel, primaryButton, secondaryButton, priorityTone, statusTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const summaryCards = [
    ['overdue', 'Overdue', 'text-rose-600'],
    ['due_today', 'Due today', 'text-amber-600'],
    ['upcoming', 'Upcoming 7 days', 'text-blue-600'],
    ['waiting_for', 'Waiting for', 'text-violet-600'],
    ['blockers', 'Blockers', 'text-rose-600'],
    ['decisions', 'Decisions', 'text-indigo-600'],
    ['risks', 'Risks', 'text-orange-600'],
    ['approvals', 'Approvals', 'text-emerald-600'],
];

export default function Dashboard({
    date,
    summary,
    focus,
    overdue,
    dueToday,
    weeklyFocus,
    commandCenter,
    areaHealth,
    portfolioProgress,
}) {
    return (
        <AuthenticatedLayout title="CEO Command Center" subtitle="Daily focus, weekly priorities, and open command-center objects.">
            <Head title="Dashboard" />
            <div className="space-y-6">
                <Panel className="overflow-hidden">
                    <div className="grid gap-6 bg-gradient-to-br from-white via-slate-50 to-emerald-50/70 p-6 xl:grid-cols-[1.3fr_0.7fr]">
                        <div>
                            <Badge tone="bg-emerald-50 text-emerald-700 ring-emerald-100">{date}</Badge>
                            <h2 className="mt-4 max-w-4xl text-4xl font-bold tracking-tight text-slate-950">CEO Command Center</h2>
                            <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                                A single operating view for today’s execution, the next seven days, and the objects that need decisions, follow-up, risk handling, or approval.
                            </p>
                            <div className="mt-6 flex flex-wrap gap-3">
                                <Link href={route('today.index')} className={primaryButton}>Open Today</Link>
                                <Link href={route('tasks.create')} className={secondaryButton}>Create Task</Link>
                                <Link href={route('waiting.index')} className={secondaryButton}>Add Waiting Item</Link>
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            {summaryCards.map(([key, label, tone]) => (
                                <div key={key} className="rounded-2xl border border-white/80 bg-white/80 p-4 shadow-sm">
                                    <div className="text-xs font-bold uppercase tracking-wide text-slate-400">{label}</div>
                                    <div className={`mt-2 text-2xl font-bold ${tone}`}>{summary[key] ?? 0}</div>
                                </div>
                            ))}
                        </div>
                    </div>
                </Panel>

                <section className="grid gap-4 xl:grid-cols-[1fr_1fr]">
                    <TaskList title="Daily Focus" description="Top 3 focus tasks from the Daily Review selection logic." tasks={focus} />
                    <TaskList title="Critical Today" description="Overdue and due-today tasks that need attention." tasks={[...overdue, ...dueToday].slice(0, 8)} />
                </section>

                <TaskList title="Weekly Focus" description="Tasks due in the next 7 days, across areas and portfolios." tasks={weeklyFocus} />

                <PageSection title="Command Center Objects" description="Dedicated objects plus tasks marked as waiting, decision, blocker, risk, or approval.">
                    <div className="grid gap-4 p-4 xl:grid-cols-5">
                        <CommandGroup title="Waiting For" href={route('waiting.index')} items={commandCenter.waiting} />
                        <CommandGroup title="Decisions" href={route('decisions.index')} items={commandCenter.decisions} />
                        <CommandGroup title="Blockers" href={route('blockers.index')} items={commandCenter.blockers} />
                        <CommandGroup title="Risks" href={route('risks.index')} items={commandCenter.risks} />
                        <CommandGroup title="Approvals" href={route('approvals.index')} items={commandCenter.approvals} />
                    </div>
                </PageSection>

                <section className="grid gap-4 xl:grid-cols-[1fr_1fr]">
                    <PageSection title="Area Health" description="Open task and project counts by Life OS area.">
                        <div className="divide-y divide-slate-100">
                            {areaHealth.map((area) => (
                                <Link key={area.id} href={route('areas.show', area.id)} className="grid gap-3 px-5 py-4 hover:bg-slate-50 md:grid-cols-[1fr_90px_90px_90px] md:items-center">
                                    <div className="font-semibold text-slate-950">{area.name}</div>
                                    <Metric label="Open" value={area.open_tasks} />
                                    <Metric label="Overdue" value={area.overdue_tasks} />
                                    <Metric label="Projects" value={area.projects} />
                                </Link>
                            ))}
                        </div>
                    </PageSection>

                    <PageSection title="Portfolio Progress" description="Priority portfolios for your CEO operating system.">
                        <div className="divide-y divide-slate-100">
                            {portfolioProgress.map((portfolio) => (
                                <Link key={portfolio.id} href={route('portfolios.show', portfolio.id)} className="grid gap-3 px-5 py-4 hover:bg-slate-50 md:grid-cols-[1fr_90px_90px] md:items-center">
                                    <div>
                                        <div className="font-semibold text-slate-950">{portfolio.name}</div>
                                        <div className="mt-1 text-sm text-slate-500">{portfolio.area}</div>
                                    </div>
                                    <Metric label="Tasks" value={portfolio.open_tasks} />
                                    <Metric label="Projects" value={portfolio.projects} />
                                </Link>
                            ))}
                        </div>
                    </PageSection>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function Metric({ label, value }) {
    return <div className="text-sm font-semibold text-slate-600"><span className="text-slate-400">{label}</span> {value}</div>;
}

function TaskList({ title, description, tasks }) {
    return (
        <PageSection title={title} description={description}>
            <div className="divide-y divide-slate-100">
                {tasks.length === 0 ? <EmptyState title="Nothing here" description="Create or update tasks to populate this section." /> : tasks.map((task) => (
                    <Link key={task.id} href={route('tasks.show', task.id)} className="grid gap-3 px-5 py-4 hover:bg-slate-50 md:grid-cols-[1fr_120px_120px_120px] md:items-center">
                        <div>
                            <div className="font-semibold text-slate-950">{task.title}</div>
                            <div className="mt-1 text-sm text-slate-500">
                                {task.area?.name ?? 'No area'} / {task.portfolio?.name ?? 'No portfolio'} / {task.project?.name ?? 'No project'}
                            </div>
                        </div>
                        <Badge tone={statusTone[task.status]}>{task.status}</Badge>
                        <Badge tone={priorityTone[task.priority]}>{task.priority}</Badge>
                        <div className="text-sm font-medium text-slate-600 md:text-right">{task.due_date ?? 'No due date'}</div>
                    </Link>
                ))}
            </div>
        </PageSection>
    );
}

function CommandGroup({ title, href, items }) {
    return (
        <Link href={href} className="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 transition hover:bg-white hover:shadow-sm">
            <div className="flex items-center justify-between gap-3">
                <div className="font-bold text-slate-950">{title}</div>
                <Badge>{items.length}</Badge>
            </div>
            <div className="mt-4 space-y-2">
                {items.length === 0 ? (
                    <div className="text-sm text-slate-500">No open items.</div>
                ) : items.slice(0, 4).map((item) => (
                    <div key={`${item.kind}-${item.id}`} className="rounded-xl bg-white p-3 text-sm shadow-sm">
                        <div className="font-semibold text-slate-800">{item.title}</div>
                        <div className="mt-1 text-xs text-slate-500">{item.kind} / {item.area ?? 'No area'}</div>
                    </div>
                ))}
            </div>
        </Link>
    );
}
