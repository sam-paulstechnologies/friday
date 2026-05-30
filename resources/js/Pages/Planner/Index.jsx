import { Badge, DueDate, EmptyState, Panel, PriorityDot, ProgressBar, inputClass, primaryButton, priorityTone, secondaryButton, statusTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const tabs = ['Calendar', 'Week', 'Timeline', 'Workload'];

const eventTone = {
    task_start: 'bg-blue-50 text-blue-700 ring-blue-100',
    task_due: 'bg-amber-50 text-amber-700 ring-amber-100',
    project_start: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    project_due: 'bg-violet-50 text-violet-700 ring-violet-100',
    google_event: 'bg-sky-50 text-sky-700 ring-sky-100',
};

export default function Index({ filters, options, month, week, calendar, weekPlan, timeline, workload, summary }) {
    const [activeTab, setActiveTab] = useState('Calendar');
    const [values, setValues] = useState(filters);

    useEffect(() => {
        const timeout = setTimeout(() => {
            router.get(route('planner.index'), compact({ ...values, month: month.value, week: week.start }), { preserveState: true, replace: true });
        }, 250);

        return () => clearTimeout(timeout);
    }, [values]);

    const clearFilters = () => setValues({ project_id: '', status: '', assignee_id: '' });

    return (
        <AuthenticatedLayout title="Planner" subtitle="Calendar, week plan, timeline, and workload in one calm planning surface.">
            <Head title="Planner" />

            <div data-testid="planner-page" className="space-y-5">
                <Panel className="p-4">
                    <div className="grid gap-4 xl:grid-cols-[1fr_auto] xl:items-center">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <Metric label="Open" value={summary.open_tasks} />
                            <Metric label="Overdue" value={summary.overdue_tasks} alert />
                            <Metric label="Due this week" value={summary.due_this_week} />
                            <Metric label="Done this week" value={summary.completed_this_week} success />
                        </div>
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                            <div className="text-xs font-semibold uppercase text-slate-500">Next project deadline</div>
                            {summary.next_project_deadline ? (
                                <Link href={summary.next_project_deadline.href} className="mt-1 block font-semibold text-slate-950 hover:text-rose-600">
                                    {summary.next_project_deadline.name} / {summary.next_project_deadline.due_date}
                                </Link>
                            ) : (
                                <div className="mt-1 font-semibold text-slate-500">No upcoming project deadline</div>
                            )}
                        </div>
                    </div>
                </Panel>

                <Panel className="p-3">
                    <div className="grid gap-3 xl:grid-cols-[1fr_auto] xl:items-center">
                        <div className="grid gap-3 md:grid-cols-3">
                            <Select value={values.project_id} onChange={(project_id) => setValues({ ...values, project_id })}>
                                <option value="">All projects</option>
                                {options.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                            </Select>
                            <Select value={values.status} onChange={(status) => setValues({ ...values, status })}>
                                <option value="">All statuses</option>
                                {options.statuses.filter((status) => status !== 'archived').map((status) => <option key={status} value={status}>{titleCase(status)}</option>)}
                            </Select>
                            <Select value={values.assignee_id} onChange={(assignee_id) => setValues({ ...values, assignee_id })}>
                                <option value="">All assignees</option>
                                <option value="unassigned">Unassigned</option>
                                {options.users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                            </Select>
                        </div>
                        <button type="button" onClick={clearFilters} className={secondaryButton}>Clear filters</button>
                    </div>
                </Panel>

                <Panel className="overflow-hidden">
                    <div className="flex flex-wrap gap-1 border-b border-slate-200 px-4">
                        {tabs.map((tab) => (
                            <button key={tab} type="button" onClick={() => setActiveTab(tab)} className={`border-b-2 px-3 py-3 text-sm font-semibold ${activeTab === tab ? 'border-rose-500 text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-900'}`}>
                                {tab}
                            </button>
                        ))}
                    </div>

                    {activeTab === 'Calendar' && <CalendarTab month={month} events={calendar.events} overdue={calendar.overdue} />}
                    {activeTab === 'Week' && <WeekTab week={week} plan={weekPlan} />}
                    {activeTab === 'Timeline' && <TimelineTab projects={timeline} />}
                    {activeTab === 'Workload' && <WorkloadTab rows={workload} />}
                </Panel>
            </div>
        </AuthenticatedLayout>
    );
}

function CalendarTab({ month, events, overdue }) {
    const days = daysForMonth(month.value);
    const eventsByDate = events.reduce((grouped, event) => ({
        ...grouped,
        [event.date]: [...(grouped[event.date] ?? []), event],
    }), {});

    return (
        <div className="space-y-4 p-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold text-slate-950">{month.label}</h2>
                    <p className="text-sm text-slate-500">{events.length} scheduled item(s), {overdue.length} overdue.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link href={route('planner.index', { month: month.previous })} className={secondaryButton}>Previous</Link>
                    <Link href={route('planner.index', { month: month.today })} className={secondaryButton}>Today</Link>
                    <Link href={route('planner.index', { month: month.next })} className={primaryButton}>Next</Link>
                </div>
            </div>

            <div className="premium-scrollbar overflow-x-auto rounded-lg border border-slate-200">
                <div className="min-w-[920px]">
                    <div className="grid grid-cols-7 border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                        {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((day) => <div key={day} className="p-3">{day}</div>)}
                    </div>
                    <div className="grid grid-cols-7">
                        {days.map((day) => {
                            const date = formatDate(day);
                            const inMonth = date.startsWith(month.value);

                            return (
                                <div key={date} className={`min-h-32 border-b border-r border-slate-100 p-2 ${inMonth ? 'bg-white' : 'bg-slate-50 text-slate-400'}`}>
                                    <div className="text-xs font-semibold">{day.getDate()}</div>
                                    <div className="mt-2 space-y-1">
                                        {(eventsByDate[date] ?? []).map((event, index) => <EventPill key={`${event.url}-${index}`} event={event} />)}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>

            {overdue.length > 0 && (
                <PlannerSection title="Overdue" description="Active work that needs a new plan.">
                    <TaskList tasks={overdue.slice(0, 8)} showActions />
                </PlannerSection>
            )}
        </div>
    );
}

function WeekTab({ week, plan }) {
    return (
        <div className="space-y-4 p-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold text-slate-950">Week of {week.label}</h2>
                    <p className="text-sm text-slate-500">Active tasks stay above completed work inside each day.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link href={route('planner.index', { week: week.previous })} className={secondaryButton}>Previous week</Link>
                    <Link href={route('planner.index', { week: week.next })} className={primaryButton}>Next week</Link>
                </div>
            </div>

            <div className="grid gap-3 xl:grid-cols-7">
                {plan.days.map((day) => (
                    <div key={day.date} className="rounded-lg border border-slate-200 bg-slate-50">
                        <div className="border-b border-slate-200 px-3 py-2">
                            <div className="text-sm font-semibold text-slate-950">{day.label}</div>
                            <div className="text-xs text-slate-500">{day.activeTasks.length} active / {day.completedTasks.length} done</div>
                        </div>
                        <div className="space-y-2 p-2">
                            {day.activeTasks.length === 0 ? <div className="px-2 py-4 text-center text-xs text-slate-500">No active work</div> : day.activeTasks.map((task) => <CompactTask key={task.id} task={task} />)}
                            {day.completedTasks.length > 0 && (
                                <div className="border-t border-slate-200 pt-2">
                                    <div className="px-1 pb-1 text-[11px] font-semibold uppercase text-slate-400">Completed</div>
                                    {day.completedTasks.map((task) => <CompactTask key={task.id} task={task} muted />)}
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            <section className="grid gap-4 xl:grid-cols-2">
                <PlannerSection title="Overdue" description="Move these to today or tomorrow when ready.">
                    {plan.overdue.length === 0 ? <EmptyState title="No overdue tasks" /> : <TaskList tasks={plan.overdue.slice(0, 10)} showActions />}
                </PlannerSection>
                <PlannerSection title="Backlog" description="Open tasks without start or due dates.">
                    {plan.backlog.length === 0 ? <EmptyState title="No backlog tasks" /> : <TaskList tasks={plan.backlog.slice(0, 10)} showActions />}
                </PlannerSection>
            </section>
        </div>
    );
}

function TimelineTab({ projects }) {
    return (
        <div className="space-y-3 p-4">
            {projects.length === 0 ? <EmptyState title="No timeline data" description="Projects and dated tasks will appear here." /> : projects.map((project) => (
                <div key={project.id} className={`rounded-lg border p-4 ${project.overdue ? 'border-rose-200 bg-rose-50/40' : 'border-slate-200 bg-white'}`}>
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <Link href={project.href} className="text-base font-semibold text-slate-950 hover:text-rose-600">{project.name}</Link>
                            <div className="mt-1 text-sm text-slate-500">{project.start_date ?? 'No start'} to {project.due_date ?? 'No deadline'}</div>
                        </div>
                        <Badge tone={project.overdue ? statusTone.blocked : statusTone[project.status]}>{project.overdue ? 'Overdue' : project.status}</Badge>
                    </div>
                    <div className="mt-4 space-y-2">
                        {project.tasks.length === 0 ? <div className="text-sm text-slate-500">No dated tasks in this project.</div> : project.tasks.map((task) => (
                            <Link key={task.id} href={task.href} className="grid gap-2 rounded-md border border-slate-100 bg-slate-50 px-3 py-2 text-sm hover:bg-white md:grid-cols-[1fr_110px_110px_130px] md:items-center">
                                <span className="truncate font-medium text-slate-900">{task.title}</span>
                                <span className="text-xs text-slate-500">{task.start_date ?? 'No start'}</span>
                                <DueDate date={task.due_date} status={task.status} />
                                <span className="inline-flex items-center gap-2 text-xs font-medium text-slate-600"><PriorityDot priority={task.priority} />{task.priority}</span>
                            </Link>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}

function WorkloadTab({ rows }) {
    return (
        <div className="p-4">
            {rows.length === 0 ? <EmptyState title="No workload data" /> : (
                <div className="overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead>
                            <tr>
                                {['Person', 'Open', 'Overdue', 'Due week', 'Urgent/high', 'Recently done', 'Pressure'].map((header) => (
                                    <th key={header} className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase text-slate-500">{header}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => {
                                const pressure = Math.min(100, row.open_tasks * 10 + row.overdue_tasks * 10 + row.high_priority_tasks * 8);
                                return (
                                    <tr key={row.id} className="hover:bg-slate-50">
                                        <td className="border-b border-slate-100 px-3 py-2 font-semibold text-slate-950">{row.name}</td>
                                        <td className="border-b border-slate-100 px-3 py-2">{row.open_tasks}</td>
                                        <td className={`border-b border-slate-100 px-3 py-2 ${row.overdue_tasks > 0 ? 'font-semibold text-rose-600' : ''}`}>{row.overdue_tasks}</td>
                                        <td className="border-b border-slate-100 px-3 py-2">{row.due_this_week}</td>
                                        <td className={`border-b border-slate-100 px-3 py-2 ${row.high_priority_tasks > 0 ? 'font-semibold text-amber-600' : ''}`}>{row.high_priority_tasks}</td>
                                        <td className="border-b border-slate-100 px-3 py-2">{row.recently_completed}</td>
                                        <td className="w-44 border-b border-slate-100 px-3 py-2"><ProgressBar value={pressure} /></td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function PlannerSection({ title, description, children }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white">
            <div className="border-b border-slate-200 px-4 py-3">
                <h3 className="text-sm font-semibold text-slate-950">{title}</h3>
                {description && <p className="text-xs text-slate-500">{description}</p>}
            </div>
            {children}
        </div>
    );
}

function TaskList({ tasks, showActions = false }) {
    return (
        <div className="divide-y divide-slate-100">
            {tasks.map((task) => (
                <div key={task.id} className="grid gap-3 px-4 py-3 text-sm lg:grid-cols-[minmax(0,1fr)_120px_120px_auto] lg:items-center">
                    <Link href={task.href} className="min-w-0 font-semibold text-slate-950 hover:text-rose-600">
                        <span className={task.status === 'completed' ? 'line-through decoration-slate-300' : ''}>{task.title}</span>
                        <span className="mt-1 block truncate text-xs font-medium text-slate-500">{task.project?.name ?? 'No project'} / {task.assignee?.name ?? 'Unassigned'}</span>
                    </Link>
                    <Badge tone={priorityTone[task.priority]}>{titleCase(task.priority)}</Badge>
                    <DueDate date={task.due_date} status={task.status} />
                    {showActions && <ScheduleButtons task={task} />}
                </div>
            ))}
        </div>
    );
}

function CompactTask({ task, muted = false }) {
    return (
        <Link href={task.href} className={`block rounded-md border border-slate-100 bg-white px-2 py-2 text-xs hover:bg-slate-50 ${muted ? 'opacity-70' : ''}`}>
            <div className={`truncate font-semibold text-slate-900 ${muted ? 'line-through decoration-slate-300' : ''}`}>{task.title}</div>
            <div className="mt-1 flex items-center justify-between gap-2">
                <span className="inline-flex items-center gap-1 text-slate-500"><PriorityDot priority={task.priority} />{task.priority}</span>
                {task.overdue && <span className="font-semibold text-rose-600">Overdue</span>}
            </div>
        </Link>
    );
}

function ScheduleButtons({ task }) {
    const moveTo = (date) => router.patch(route('planner.tasks.schedule', task.id), { due_date: date }, { preserveScroll: true });
    const today = new Date().toISOString().slice(0, 10);
    const tomorrowDate = new Date();
    tomorrowDate.setDate(tomorrowDate.getDate() + 1);
    const tomorrow = tomorrowDate.toISOString().slice(0, 10);

    return (
        <div className="flex flex-wrap gap-2">
            <button type="button" onClick={() => moveTo(today)} className={secondaryButton}>Today</button>
            <button type="button" onClick={() => moveTo(tomorrow)} className={secondaryButton}>Tomorrow</button>
        </div>
    );
}

function EventPill({ event }) {
    const className = `block rounded-md px-2 py-1 text-xs font-semibold ring-1 transition hover:brightness-95 ${eventTone[event.type] ?? eventTone.task_due} ${event.completed ? 'opacity-60' : ''}`;
    const content = (
        <>
            <span className="flex items-center justify-between gap-2">
                <span>{event.label}</span>
                {event.overdue && <span className="text-rose-600">Late</span>}
            </span>
            <span className={`block truncate font-medium ${event.completed ? 'line-through decoration-slate-300' : ''}`}>{event.title}</span>
        </>
    );

    if (!event.url) {
        return <div className={className}>{content}</div>;
    }

    return (
        <Link href={event.url} className={className}>
            {content}
        </Link>
    );
}

function Metric({ label, value, alert = false, success = false }) {
    const tone = alert && Number(value) > 0 ? 'text-rose-600' : success ? 'text-emerald-600' : 'text-slate-950';

    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
            <div className="text-xs font-medium text-slate-500">{label}</div>
            <div className={`mt-1 text-2xl font-semibold ${tone}`}>{value ?? 0}</div>
        </div>
    );
}

function Select({ value, onChange, children }) {
    return <select value={value ?? ''} onChange={(event) => onChange(event.target.value)} className={inputClass}>{children}</select>;
}

function daysForMonth(month) {
    const [year, monthIndex] = month.split('-').map(Number);
    const first = new Date(year, monthIndex - 1, 1);
    const start = new Date(first);
    start.setDate(first.getDate() - first.getDay());

    return Array.from({ length: 42 }, (_, index) => {
        const date = new Date(start);
        date.setDate(start.getDate() + index);
        return date;
    });
}

function formatDate(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function compact(values) {
    return Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '' && value !== null && value !== undefined));
}

function titleCase(value) {
    return String(value ?? 'None').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}
