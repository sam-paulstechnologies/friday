import { Badge, DueDate, EmptyState, Panel, inputClass, primaryButton, secondaryButton } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';

const urgencyTone = {
    critical: 'border-rose-300 bg-rose-50 text-rose-950 ring-rose-200 dark:border-rose-500/50 dark:bg-rose-500/15 dark:text-rose-100',
    high: 'border-amber-300 bg-amber-50 text-amber-950 ring-amber-200 dark:border-amber-400/50 dark:bg-amber-400/15 dark:text-amber-100',
    medium: 'border-sky-300 bg-sky-50 text-sky-950 ring-sky-200 dark:border-sky-400/50 dark:bg-sky-400/15 dark:text-sky-100',
    low: 'border-slate-200 bg-slate-50 text-slate-700 ring-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200',
    stable: 'border-emerald-300 bg-emerald-50 text-emerald-950 ring-emerald-200 dark:border-emerald-400/50 dark:bg-emerald-400/15 dark:text-emerald-100',
    waiting: 'border-amber-300 bg-amber-50 text-amber-950 ring-amber-200 dark:border-amber-400/50 dark:bg-amber-400/15 dark:text-amber-100',
    running: 'border-indigo-300 bg-indigo-50 text-indigo-950 ring-indigo-200 dark:border-indigo-400/50 dark:bg-indigo-400/15 dark:text-indigo-100',
    blocked: 'border-rose-300 bg-rose-50 text-rose-950 ring-rose-200 dark:border-rose-500/50 dark:bg-rose-500/15 dark:text-rose-100',
    overdue: 'border-rose-300 bg-rose-50 text-rose-950 ring-rose-200 dark:border-rose-500/50 dark:bg-rose-500/15 dark:text-rose-100',
};

const badgeTone = {
    critical: 'bg-rose-100 text-rose-800 ring-rose-200',
    high: 'bg-amber-100 text-amber-800 ring-amber-200',
    medium: 'bg-sky-100 text-sky-800 ring-sky-200',
    low: 'bg-slate-100 text-slate-700 ring-slate-200',
    waiting: 'bg-amber-100 text-amber-800 ring-amber-200',
    running: 'bg-indigo-100 text-indigo-800 ring-indigo-200',
    stable: 'bg-emerald-100 text-emerald-800 ring-emerald-200',
    blocked: 'bg-rose-100 text-rose-800 ring-rose-200',
    failed: 'bg-rose-100 text-rose-800 ring-rose-200',
    passed: 'bg-emerald-100 text-emerald-800 ring-emerald-200',
    completed: 'bg-emerald-100 text-emerald-800 ring-emerald-200',
};

const jobStatusTone = {
    queued: 'waiting',
    waiting_for_runner: 'waiting',
    preparing: 'running',
    running: 'running',
    waiting_for_approval: 'waiting',
    waiting_for_manual_fix: 'blocked',
    paused: 'waiting',
    blocked: 'blocked',
    failed: 'failed',
    completed: 'completed',
    passed: 'passed',
};

function MetricCard({ label, value, tone = 'low', detail }) {
    const compactValue = String(value ?? '').length > 8;

    return (
        <div className={`rounded-lg border p-4 shadow-sm ring-1 ${urgencyTone[tone] ?? urgencyTone.low}`}>
            <div className="text-xs font-black uppercase tracking-wide opacity-80">{label}</div>
            <div className={`mt-2 font-black leading-tight ${compactValue ? 'text-base' : 'text-3xl'}`}>{value}</div>
            {detail && <div className="mt-2 text-xs font-semibold opacity-80">{detail}</div>}
        </div>
    );
}

function SectionHeader({ title, description, action }) {
    return (
        <div className="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-700">
            <div>
                <h2 className="text-lg font-black tracking-tight text-slate-950 dark:text-white">{title}</h2>
                {description && <p className="mt-1 text-sm font-medium leading-6 text-slate-600 dark:text-slate-300">{description}</p>}
            </div>
            {action}
        </div>
    );
}

function ActionCard({ item, compact = false }) {
    return (
        <div className={`rounded-lg border p-4 shadow-sm ${urgencyTone[item.urgency] ?? urgencyTone.low}`}>
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={badgeTone[item.urgency] ?? badgeTone.low}>{labelize(item.urgency)}</Badge>
                        <span className="text-xs font-black uppercase tracking-wide opacity-70">{item.source}</span>
                    </div>
                    <h3 className="mt-2 text-base font-black leading-6">{item.title}</h3>
                    <p className="mt-1 text-sm font-semibold leading-6 opacity-85">{item.why}</p>
                    <div className="mt-2 text-xs font-bold opacity-75">{item.time}</div>
                </div>
                <div className="flex shrink-0 flex-wrap gap-2">
                    <PrimaryItemAction item={item} />
                    {!compact && <SecondaryItemActions item={item} />}
                </div>
            </div>
        </div>
    );
}

function PrimaryItemAction({ item }) {
    if (item.kind === 'task') {
        return (
            <button type="button" onClick={() => router.patch(route('tasks.complete', item.model_id), {}, { preserveScroll: true })} className={primaryButton}>
                Mark done
            </button>
        );
    }

    if (item.kind === 'approval') {
        return (
            <button type="button" onClick={() => router.patch(route('approvals.close', item.model_id), {}, { preserveScroll: true })} className={primaryButton}>
                Approve
            </button>
        );
    }

    return <Link href={item.href} className={primaryButton}>{item.action_label ?? 'Open details'}</Link>;
}

function SecondaryItemActions({ item }) {
    if (item.kind === 'task') {
        return (
            <>
                <button type="button" onClick={() => router.patch(route('today.tasks.snooze', item.model_id), {}, { preserveScroll: true })} className={secondaryButton}>
                    Snooze
                </button>
                <Link href={item.href} className={secondaryButton}>Open</Link>
            </>
        );
    }

    if (item.kind === 'approval') {
        return (
            <>
                <button type="button" onClick={() => router.patch(route('approvals.reject', item.model_id), {}, { preserveScroll: true })} className={secondaryButton}>
                    Reject
                </button>
                <Link href={item.href} className={secondaryButton}>Open</Link>
            </>
        );
    }

    if (item.kind === 'waiting') {
        return (
            <>
                <button type="button" onClick={() => router.patch(route('waiting.update', item.model_id), { title: item.title, follow_up_date: tomorrowDate() }, { preserveScroll: true })} className={secondaryButton}>
                    Snooze
                </button>
                <Link href={item.href} className={secondaryButton}>Open</Link>
            </>
        );
    }

    return <Link href={item.href} className={secondaryButton}>Open details</Link>;
}

function WaitingPanel({ items }) {
    return (
        <Panel>
            <SectionHeader
                title="Waiting on me"
                description="Approvals, replies, reviews, deployment gates, and health decisions that cannot progress without input."
                action={<Badge tone={items.length ? badgeTone.waiting : badgeTone.stable}>{items.length} waiting</Badge>}
            />
            <div className="space-y-3 p-4">
                {items.length === 0 ? (
                    <EmptyState title="Nothing is waiting on you" description="Approvals and decisions will appear here when they block progress." />
                ) : items.map((item) => <ActionCard key={item.id} item={{ ...item, urgency: 'critical', why: item.reason, time: item.age, action_label: item.primary_action }} />)}
            </div>
        </Panel>
    );
}

function CodexPanel({ workstream }) {
    const jobs = workstream?.jobs ?? [];

    return (
        <div id="codex-workstream">
            <Panel>
                <SectionHeader
                    title="Codex / Runner workstream"
                    description="Current phase, last result, pass/fail state, blocker, next action, and freshness."
                    action={<Badge tone={jobs.length ? badgeTone.running : badgeTone.stable}>{workstream?.status_label ?? 'Codex idle'}</Badge>}
                />
                <div className="space-y-3 p-4">
                    {jobs.length === 0 ? (
                        <EmptyState title="Codex idle" description="No active development jobs are queued, running, blocked, or waiting for approval." />
                    ) : jobs.map((job) => (
                        <div key={job.id} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge tone={badgeTone[jobStatusTone[job.status] ?? 'low']}>{labelize(job.status)}</Badge>
                                        <span className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">{job.app}</span>
                                    </div>
                                    <h3 className="mt-2 text-base font-black text-slate-950 dark:text-white">{job.title}</h3>
                                    <div className="mt-3 grid gap-2 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                        <Info label="Phase" value={job.phase} />
                                        <Info label="Last result" value={labelize(job.last_result)} />
                                        <Info label="Next action" value={job.next_action} />
                                        <Info label="Updated" value={job.updated_at} />
                                    </div>
                                    {job.blocker && (
                                        <div className="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800">
                                            Blocker: {job.blocker}
                                        </div>
                                    )}
                                </div>
                                <Link href={`${route('today.index')}#codex-workstream`} className={secondaryButton}>Open details</Link>
                            </div>
                        </div>
                    ))}
                </div>
            </Panel>
        </div>
    );
}

function ProductsPanel({ products }) {
    return (
        <Panel>
            <SectionHeader
                title="Today by product/app"
                description="Urgency, open load, next action, blocker, and last movement by workstream."
                action={<Link href={route('portfolios.index')} className={secondaryButton}>Products</Link>}
            />
            <div className="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">
                {products.map((product) => (
                    <Link key={product.key} href={product.href} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300 hover:shadow-md dark:border-slate-700 dark:bg-slate-900">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h3 className="font-black text-slate-950 dark:text-white">{product.label}</h3>
                                <p className="mt-1 text-xs font-semibold text-slate-500 dark:text-slate-400">{product.last_movement}</p>
                            </div>
                            <div className="flex gap-2">
                                <Badge tone={product.urgent_count ? badgeTone.critical : badgeTone.stable}>{product.urgent_count} urgent</Badge>
                                <Badge>{product.open_count} open</Badge>
                            </div>
                        </div>
                        <div className="mt-4 rounded-md bg-slate-50 p-3 text-sm dark:bg-slate-800">
                            <div className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">Next action</div>
                            <div className="mt-1 font-semibold text-slate-950 dark:text-white">{product.next_action}</div>
                        </div>
                        <div className={`mt-3 rounded-md px-3 py-2 text-sm font-semibold ${product.blocker ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>
                            {product.blocker ? `Blocked: ${product.blocker}` : 'No blocker recorded'}
                        </div>
                    </Link>
                ))}
            </div>
        </Panel>
    );
}

function OverdueBlockedPanel({ items }) {
    return (
        <Panel>
            <SectionHeader
                title="Overdue and blocked"
                description="These are separated from normal backlog so they do not disappear inside a generic task list."
                action={<Badge tone={items.length ? badgeTone.critical : badgeTone.stable}>{items.length} active</Badge>}
            />
            <div className="space-y-3 p-4">
                {items.length === 0 ? (
                    <EmptyState title="No overdue or blocked items" description="Blocked and overdue work will stay isolated here when it appears." />
                ) : items.map((item) => (
                    <Link key={item.id} href={item.href} className={`block rounded-lg border p-4 shadow-sm ${urgencyTone[item.tone] ?? urgencyTone.blocked}`}>
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge tone={badgeTone[item.tone] ?? badgeTone.blocked}>{labelize(item.tone)}</Badge>
                                    <span className="text-xs font-black uppercase tracking-wide opacity-70">{item.source}</span>
                                </div>
                                <h3 className="mt-2 font-black">{item.title}</h3>
                                <p className="mt-1 text-sm font-semibold leading-6 opacity-85">{item.blocked_reason}</p>
                            </div>
                            <div className="min-w-48 rounded-md bg-white/70 p-3 text-sm dark:bg-slate-950/30">
                                <Info label="Owner" value={item.owner} />
                                <Info label="Age" value={item.age} />
                                <Info label="Suggested next action" value={item.suggested_next_action} />
                            </div>
                        </div>
                    </Link>
                ))}
            </div>
        </Panel>
    );
}

function BacklogPanel({ items }) {
    return (
        <Panel>
            <SectionHeader
                title="Later / backlog"
                description="Lower urgency work is intentionally pushed below the command surface."
                action={<Link href={route('tasks.index')} className={secondaryButton}>Open backlog</Link>}
            />
            <div className="divide-y divide-slate-100 dark:divide-slate-800">
                {items.length === 0 ? (
                    <EmptyState title="No low-urgency backlog visible" description="Backlog items without immediate pressure will appear here." />
                ) : items.map((item) => (
                    <Link key={item.id} href={item.href} className="grid gap-3 px-5 py-3 text-sm hover:bg-slate-50 md:grid-cols-[minmax(0,1fr)_110px_110px_120px] md:items-center dark:hover:bg-slate-900">
                        <div className="min-w-0">
                            <div className="truncate font-bold text-slate-950 dark:text-white">{item.title}</div>
                            <div className="truncate text-xs font-semibold text-slate-500 dark:text-slate-400">{item.source}</div>
                        </div>
                        <Badge>{labelize(item.status)}</Badge>
                        <Badge tone={item.priority === 'high' ? badgeTone.high : badgeTone.low}>{labelize(item.priority)}</Badge>
                        <DueDate date={item.due_date} status={item.status} />
                    </Link>
                ))}
            </div>
        </Panel>
    );
}

function ReadingPanel({ reading }) {
    if (!reading?.has_plan) {
        return null;
    }

    return (
        <Panel>
            <SectionHeader title="Safe to ignore / stable" description="Stable routines that should not compete with urgent work." />
            <div className="p-4">
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-emerald-950">
                    <div className="text-sm font-black">Reading / Learning</div>
                    <div className="mt-1 text-sm font-semibold">{reading.today_label}</div>
                    <div className="mt-1 text-xs font-bold">{reading.today_completed_chapters} / {reading.today_total_chapters} chapters complete</div>
                    <Link href={reading.continue_url} className={`${secondaryButton} mt-3 bg-white`}>Continue reading</Link>
                </div>
            </div>
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
        <form onSubmit={submit} className="mt-3 flex flex-col gap-2 sm:flex-row">
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

function LegacyTaskList({ title, tasks }) {
    if (!tasks.length) return null;

    return (
        <Panel>
            <SectionHeader title={title} description="Secondary detail view for normal daily execution." />
            <div className="space-y-3 p-4">
                {tasks.slice(0, 6).map((task) => (
                    <div key={task.id} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div className="min-w-0">
                                <Link href={route('tasks.show', task.id)} className="font-black text-slate-950 hover:text-slate-700 dark:text-white">
                                    {task.title}
                                </Link>
                                <div className="mt-2 flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-500 dark:text-slate-400">
                                    <span>{task.project?.name ?? 'No project'}</span>
                                    <span>{task.portfolio?.name ?? 'No portfolio'}</span>
                                    <DueDate date={task.due_date} status={task.status} />
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <button type="button" onClick={() => router.patch(route('tasks.complete', task.id), {}, { preserveScroll: true })} className={primaryButton}>Done</button>
                                <Link href={route('tasks.show', task.id)} className={secondaryButton}>Open</Link>
                            </div>
                        </div>
                        <TaskNoteForm task={task} />
                    </div>
                ))}
            </div>
        </Panel>
    );
}

function Info({ label, value }) {
    return (
        <div>
            <div className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</div>
            <div className="mt-1 font-semibold text-slate-950 dark:text-white">{value || 'None'}</div>
        </div>
    );
}

export default function Index({ groups, summary, dailyReview, reading, commandCenter }) {
    const cc = commandCenter ?? {};
    const metrics = cc.metrics ?? {};
    const heroStatus = cc.hero_status ?? {};
    const doThisNow = cc.do_this_now ?? [];
    const waitingOnMe = cc.waiting_on_me ?? [];
    const codexWorkstream = cc.codex_workstream ?? { jobs: [] };
    const products = cc.products ?? [];
    const overdueBlocked = cc.overdue_blocked ?? [];
    const backlog = cc.later_backlog ?? [];
    const emptyState = cc.empty_state ?? {};
    const activeToday = [...(groups?.overdue ?? []), ...(groups?.due_today ?? []), ...(groups?.scheduled_today ?? [])];

    return (
        <AuthenticatedLayout title="Today" subtitle="Command center for urgency, approvals, Codex, medicine, products, blockers, and backlog.">
            <Head title="Today Command Center" />

            <div data-testid="today-page" className="space-y-6">
                <Panel className="overflow-hidden">
                    <div className="bg-slate-950 p-5 text-white dark:bg-black">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <Badge tone={badgeTone.critical}>Today Command Center</Badge>
                                <h2 className="mt-4 text-3xl font-black tracking-tight sm:text-4xl">What needs attention now</h2>
                                <p className="mt-3 max-w-3xl text-sm font-semibold leading-6 text-slate-300">{dailyReview?.morning}</p>
                            </div>
                            <div className="grid gap-2 text-sm sm:grid-cols-2">
                                <Badge tone={badgeTone[heroStatus.health?.tone] ?? badgeTone.low}>{heroStatus.health?.label ?? 'Medication unknown'}</Badge>
                                <Badge tone={badgeTone[heroStatus.codex?.tone] ?? badgeTone.low}>{heroStatus.codex?.label ?? 'Codex idle'}</Badge>
                            </div>
                        </div>
                    </div>
                    <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                        <MetricCard label="Critical" value={metrics.critical ?? 0} tone={(metrics.critical ?? 0) > 0 ? 'critical' : 'stable'} />
                        <MetricCard label="Overdue" value={metrics.overdue ?? summary?.overdue ?? 0} tone={(metrics.overdue ?? 0) > 0 ? 'critical' : 'stable'} />
                        <MetricCard label="Due today" value={metrics.due_today ?? summary?.due_today ?? 0} tone={(metrics.due_today ?? 0) > 0 ? 'high' : 'stable'} />
                        <MetricCard label="Waiting for me" value={metrics.waiting_for_me ?? 0} tone={(metrics.waiting_for_me ?? 0) > 0 ? 'waiting' : 'stable'} />
                        <MetricCard label="Codex running" value={metrics.codex_running ?? 0} tone={(metrics.codex_running ?? 0) > 0 ? 'running' : 'stable'} />
                        <MetricCard label="Blocked" value={metrics.blocked ?? 0} tone={(metrics.blocked ?? 0) > 0 ? 'critical' : 'stable'} />
                        <MetricCard label="Medicine" value={metrics.health_medicine ?? 'Unknown'} tone={heroStatus.health?.tone ?? 'low'} />
                    </div>
                </Panel>

                {emptyState.show && (
                    <Panel className="border-emerald-200 bg-emerald-50">
                        <div className="p-5">
                            <Badge tone={badgeTone.stable}>Stable</Badge>
                            <h2 className="mt-3 text-2xl font-black text-emerald-950">No fires right now</h2>
                            <p className="mt-2 text-sm font-semibold leading-6 text-emerald-800">{emptyState.next_action}</p>
                            <div className="mt-4 grid gap-3 text-sm md:grid-cols-2">
                                <Info label="Codex" value={emptyState.codex_status} />
                                <Info label="Medication" value={emptyState.medication_status} />
                            </div>
                        </div>
                    </Panel>
                )}

                <Panel>
                    <SectionHeader
                        title="Do this now"
                        description="Maximum three items: highest urgency work, pending medicine, approvals, and client/revenue-impacting actions."
                        action={<Badge tone={doThisNow.length ? badgeTone.critical : badgeTone.stable}>{doThisNow.length} selected</Badge>}
                    />
                    <div className="space-y-3 p-4">
                        {doThisNow.length === 0 ? (
                            <EmptyState title="No fires right now" description="The next best action is to review product focus and pick one high-leverage task." />
                        ) : doThisNow.map((item) => <ActionCard key={item.id} item={item} />)}
                    </div>
                </Panel>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
                    <WaitingPanel items={waitingOnMe} />
                    <CodexPanel workstream={codexWorkstream} />
                </div>

                <ProductsPanel products={products} />
                <OverdueBlockedPanel items={overdueBlocked} />
                <BacklogPanel items={backlog} />

                <div className="grid gap-6 xl:grid-cols-2">
                    <LegacyTaskList title="Due today detail" tasks={activeToday} />
                    <ReadingPanel reading={reading} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function labelize(value) {
    return String(value ?? '')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function tomorrowDate() {
    const date = new Date();
    date.setDate(date.getDate() + 1);

    return date.toISOString().slice(0, 10);
}
