import { AppCard, Avatar, Badge, DueDate, EmptyState, PageSection, PriorityDot, ProgressBar, primaryButton, secondaryButton, priorityTone, statusTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const summaryCards = [
    ['overdue', 'Overdue'],
    ['due_today', 'Due today'],
    ['upcoming', 'Upcoming'],
    ['waiting_for', 'Waiting'],
    ['blockers', 'Blockers'],
    ['decisions', 'Decisions'],
    ['risks', 'Risks'],
    ['approvals', 'Approvals'],
];

export default function Dashboard({
    date,
    summary,
    focus,
    overdue,
    dueToday,
    weeklyFocus,
    completedTasks = [],
    spiritualReading,
    commandCenter,
    areaHealth,
    portfolioProgress,
}) {
    const user = usePage().props.auth.user;
    const [taskTab, setTaskTab] = useState('upcoming');
    const taskTabs = useMemo(() => ({
        upcoming: [...dueToday, ...weeklyFocus].filter((task) => task.status !== 'completed').slice(0, 8),
        overdue: overdue.filter((task) => task.status !== 'completed').slice(0, 8),
        completed: completedTasks.filter((task) => task.status === 'completed').slice(0, 8),
    }), [dueToday, weeklyFocus, overdue, completedTasks]);

    return (
        <AuthenticatedLayout title="Home" subtitle="Daily operating view for Miriam / Friday.">
            <Head title="Dashboard" />

            <div className="space-y-5">
                <AppCard className="p-5">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <div className="text-sm text-slate-500">{date}</div>
                            <h2 className="mt-1 text-2xl font-semibold text-slate-950">{greeting()}, {user.name}</h2>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-500">
                                Focus on the highest leverage work first, then clear today’s commitments.
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Link href={route('tasks.create')} className={primaryButton}>Create task</Link>
                            <Link href={route('prioritization-review.index')} className={secondaryButton}>Review priorities</Link>
                        </div>
                    </div>

                    <div className="mt-5 grid gap-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
                        {summaryCards.map(([key, label]) => (
                            <div key={key} className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                <div className="text-xs font-medium text-slate-500">{label}</div>
                                <div className={`mt-1 text-xl font-semibold ${['overdue', 'blockers', 'risks'].includes(key) && Number(summary[key] ?? 0) > 0 ? 'text-rose-600' : 'text-slate-950'}`}>{summary[key] ?? 0}</div>
                            </div>
                        ))}
                    </div>
                </AppCard>

                <section className="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(320px,0.65fr)]">
                    <AppCard>
                        <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                            <div>
                                <h3 className="text-sm font-semibold text-slate-950">My Tasks</h3>
                                <p className="text-xs text-slate-500">Upcoming, overdue, and completed work.</p>
                            </div>
                            <Link href={route('tasks.index')} className="text-sm font-semibold text-rose-600 hover:text-rose-700">View all</Link>
                        </div>
                        <div className="flex gap-1 border-b border-slate-200 px-4">
                            {[
                                ['upcoming', 'Upcoming'],
                                ['overdue', 'Overdue'],
                                ['completed', 'Completed'],
                            ].map(([key, label]) => (
                                <button key={key} type="button" onClick={() => setTaskTab(key)} className={`border-b-2 px-3 py-2 text-sm font-medium ${taskTab === key ? 'border-rose-500 text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-900'}`}>
                                    {label}
                                </button>
                            ))}
                        </div>
                        <div>
                            {taskTabs[taskTab].length === 0 ? (
                                <EmptyState title="No tasks here" description="Nothing currently matches this tab." />
                            ) : taskTabs[taskTab].map((task) => <HomeTaskRow key={task.id} task={task} />)}
                        </div>
                    </AppCard>

                    <div className="space-y-5">
                        <SpiritualReadingWidget reading={spiritualReading} />
                        <AskFridayWidget />
                    </div>
                </section>

                <section className="grid gap-5 xl:grid-cols-[1fr_1fr]">
                    <PageSection title="Projects" description="Priority portfolios and project areas.">
                        <div className="divide-y divide-slate-100">
                            {portfolioProgress.length === 0 ? <EmptyState title="No portfolios found" /> : portfolioProgress.map((portfolio) => (
                                <Link key={portfolio.id} href={route('portfolios.show', portfolio.id)} className="grid gap-3 px-4 py-3 text-sm hover:bg-slate-50 md:grid-cols-[1fr_80px_80px] md:items-center">
                                    <div className="min-w-0">
                                        <div className="truncate font-medium text-slate-950">{portfolio.name}</div>
                                        <div className="text-xs text-slate-500">{portfolio.area}</div>
                                    </div>
                                    <span className="text-slate-600">{portfolio.open_tasks} tasks</span>
                                    <span className="text-slate-600">{portfolio.projects} projects</span>
                                </Link>
                            ))}
                            <Link href={route('projects.create')} className="block px-4 py-3 text-sm font-semibold text-rose-600 hover:bg-slate-50">+ Create project</Link>
                        </div>
                    </PageSection>

                    <PageSection title="Command Center" description="Open command-center objects needing attention.">
                        <div className="grid gap-3 p-4 sm:grid-cols-2">
                            <CommandGroup title="Waiting For" href={route('waiting.index')} items={commandCenter.waiting} />
                            <CommandGroup title="Decisions" href={route('decisions.index')} items={commandCenter.decisions} />
                            <CommandGroup title="Blockers" href={route('blockers.index')} items={commandCenter.blockers} />
                            <CommandGroup title="Risks" href={route('risks.index')} items={commandCenter.risks} />
                        </div>
                    </PageSection>
                </section>

                <PageSection title="Area Health" description="Open tasks, overdue pressure, and active projects by Life OS area.">
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead>
                                <tr>
                                    {['Area', 'Open', 'Overdue', 'Projects', 'Health'].map((header) => <th key={header} className="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{header}</th>)}
                                </tr>
                            </thead>
                            <tbody>
                                {areaHealth.map((area) => {
                                    const total = Math.max(1, Number(area.open_tasks) + Number(area.projects));
                                    const risk = Math.min(100, Math.round((Number(area.overdue_tasks) / total) * 100));
                                    return (
                                        <tr key={area.id} className="hover:bg-slate-50">
                                            <td className="border-b border-slate-100 px-4 py-2 font-medium text-slate-950"><Link href={route('areas.show', area.id)}>{area.name}</Link></td>
                                            <td className="border-b border-slate-100 px-4 py-2">{area.open_tasks}</td>
                                            <td className={`border-b border-slate-100 px-4 py-2 ${area.overdue_tasks > 0 ? 'font-semibold text-rose-600' : ''}`}>{area.overdue_tasks}</td>
                                            <td className="border-b border-slate-100 px-4 py-2">{area.projects}</td>
                                            <td className="w-48 border-b border-slate-100 px-4 py-2"><ProgressBar value={100 - risk} /></td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </PageSection>
            </div>
        </AuthenticatedLayout>
    );
}

function HomeTaskRow({ task }) {
    return (
        <Link href={route('tasks.show', task.id)} className="grid gap-3 border-b border-slate-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-slate-50 md:grid-cols-[minmax(0,1fr)_120px_110px_120px] md:items-center">
            <div className="flex min-w-0 items-center gap-3">
                <span className={`h-4 w-4 rounded-full border ${task.status === 'completed' ? 'border-emerald-500 bg-emerald-500' : 'border-slate-300 bg-white'}`} />
                <div className="min-w-0">
                    <div className="truncate font-medium text-slate-950">{task.title}</div>
                    <div className="truncate text-xs text-slate-500">{task.project?.name ?? 'No project'}{task.portfolio?.name ? ` / ${task.portfolio.name}` : ''}</div>
                </div>
            </div>
            <span className="inline-flex items-center gap-2 text-xs font-medium text-slate-600"><PriorityDot priority={task.priority} />{task.priority}</span>
            <Badge tone={statusTone[task.status]}>{task.status}</Badge>
            <DueDate date={task.due_date} status={task.status} />
        </Link>
    );
}

function SpiritualReadingWidget({ reading }) {
    if (!reading) return null;

    return (
        <AppCard>
            <div className="border-b border-slate-200 px-4 py-3">
                <h3 className="text-sm font-semibold text-slate-950">Today’s Bible Reading</h3>
                <p className="text-xs text-slate-500">Personal Foundation</p>
            </div>
            <div className="space-y-3 p-4">
                <div className="font-semibold text-slate-950">{reading.today_label}</div>
                <div className="text-sm text-slate-500">{reading.today_completed_chapters} / {reading.today_total_chapters} chapters today</div>
                <ProgressBar value={reading.today_total_chapters > 0 ? Math.round((reading.today_completed_chapters / reading.today_total_chapters) * 100) : 0} />
                <div className="flex flex-wrap gap-2">
                    <Badge>{reading.current_streak} day streak</Badge>
                    <Badge tone={reading.behind_count > 0 ? 'bg-rose-50 text-rose-700 ring-rose-100' : 'bg-emerald-50 text-emerald-700 ring-emerald-100'}>{reading.status_label}</Badge>
                </div>
                {reading.missed_yesterday && <p className="rounded-md bg-amber-50 p-2 text-xs leading-5 text-amber-800">Missed yesterday: {reading.missed_yesterday_label}</p>}
                <Link href={route('spiritual.index')} className={secondaryButton}>Continue Reading</Link>
            </div>
        </AppCard>
    );
}

function AskFridayWidget() {
    const prompts = ['What should I focus on today?', 'Career snapshot', 'Review priorities', 'Show overdue tasks'];

    return (
        <AppCard>
            <div className="border-b border-slate-200 px-4 py-3">
                <h3 className="text-sm font-semibold text-slate-950">Ask Friday</h3>
                <p className="text-xs text-slate-500">Quick prompts for AI Brain workflows.</p>
            </div>
            <div className="space-y-2 p-4">
                {prompts.map((prompt) => <Link key={prompt} href={route('settings.ai.edit')} className="block rounded-md border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{prompt}</Link>)}
            </div>
        </AppCard>
    );
}

function CommandGroup({ title, href, items }) {
    return (
        <Link href={href} className="rounded-md border border-slate-200 bg-white p-3 hover:bg-slate-50">
            <div className="flex items-center justify-between">
                <div className="text-sm font-semibold text-slate-950">{title}</div>
                <Badge>{items.length}</Badge>
            </div>
            <div className="mt-2 space-y-1">
                {items.length === 0 ? <div className="text-xs text-slate-500">No open items.</div> : items.slice(0, 2).map((item) => <div key={`${item.kind}-${item.id}`} className="truncate text-xs text-slate-600">{item.title}</div>)}
            </div>
        </Link>
    );
}

function greeting() {
    const hour = new Date().getHours();
    if (hour < 12) return 'Good morning';
    if (hour < 17) return 'Good afternoon';
    return 'Good evening';
}
