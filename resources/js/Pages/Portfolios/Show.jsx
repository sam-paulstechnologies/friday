import { Badge, DueDate, EmptyState, PageSection, Panel, ProgressBar, priorityTone, statusTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const sectionLabels = { overdue: 'Overdue', due_today: 'Due Today', upcoming: 'Upcoming', no_due_date: 'No Due Date' };

export default function Show({ portfolio, projects, tasks }) {
    const totalProjects = Number(portfolio.total_projects_count ?? portfolio.project_count ?? projects.length ?? 0);
    const openTasks = Number(portfolio.open_tasks_count ?? 0);
    const completedTasks = Number(portfolio.completed_tasks_count ?? 0);
    const overdueTasks = Number(portfolio.overdue_tasks_count ?? 0);
    const hasWork = totalProjects + openTasks + completedTasks > 0;

    return (
        <AuthenticatedLayout title={portfolio.name} subtitle={portfolio.area?.name ?? 'Portfolio'}>
            <Head title={portfolio.name} />
            <div className="space-y-6">
                <Panel className="overflow-hidden">
                    <div className="bg-[radial-gradient(circle_at_top_left,_rgba(99,102,241,0.12),_transparent_30%),linear-gradient(135deg,_#ffffff,_#f8fafc_58%,_#eef2ff)] p-6 sm:p-8">
                    <Badge>{portfolio.area?.name ?? 'No area'}</Badge>
                    <h2 className="mt-4 text-3xl font-bold text-slate-950">{portfolio.name}</h2>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-500">{portfolio.description}</p>
                    <div className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Metric label="Total projects" value={totalProjects} />
                        <Metric label="Open tasks" value={openTasks} />
                        <Metric label="Completed tasks" value={completedTasks} />
                        <Metric label="Overdue tasks" value={overdueTasks} tone={overdueTasks > 0 ? 'text-rose-600' : 'text-slate-950'} />
                    </div>
                    {hasWork ? (
                        <div className="mt-5 rounded-3xl border border-white/80 bg-white/75 p-4 shadow-sm shadow-slate-200/70">
                            <div className="flex items-center justify-between text-sm font-semibold text-slate-600">
                                <span>Task progress</span>
                                <span className="text-slate-950">{portfolio.progress_percentage ?? 0}%</span>
                            </div>
                            <ProgressBar value={portfolio.progress_percentage ?? 0} className="mt-3" />
                        </div>
                    ) : (
                        <div className="mt-5 rounded-3xl border border-dashed border-slate-200 bg-white/70 p-5 text-sm font-semibold text-slate-500">
                            No active work yet
                        </div>
                    )}
                    </div>
                </Panel>

                <PageSection title="Projects" description={`${projects.length} active project(s).`}>
                    <div className="divide-y divide-slate-100">
                        {projects.length === 0 ? <EmptyState title="No projects in this portfolio" /> : projects.map((project) => (
                            <Link key={project.id} href={route('projects.show', project.id)} className="flex flex-col gap-4 px-5 py-4 transition hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                                <div className="min-w-0">
                                    <div className="font-semibold text-slate-950">{project.name}</div>
                                    <div className="mt-1 text-sm text-slate-500">{project.owner?.name ?? 'No owner'} / due {project.due_date ?? 'not set'}</div>
                                </div>
                                <div className="flex gap-2">
                                    <Badge tone={statusTone[project.status]}>{project.status}</Badge>
                                    <Badge>{project.health}</Badge>
                                </div>
                            </Link>
                        ))}
                    </div>
                </PageSection>

                {Object.entries(sectionLabels).map(([key, label]) => <TaskSection key={key} title={label} tasks={tasks[key] ?? []} />)}
            </div>
        </AuthenticatedLayout>
    );
}

function TaskSection({ title, tasks }) {
    return (
        <PageSection title={title} description={`${tasks.length} task(s)`}>
            <div className="divide-y divide-slate-100">
                {tasks.length === 0 ? <EmptyState title="Nothing here" description="Tasks will appear here when they match this portfolio and date bucket." /> : tasks.map((task) => (
                    <Link key={task.id} href={route('tasks.show', task.id)} className="grid gap-3 px-5 py-4 transition hover:bg-slate-50 md:grid-cols-[1fr_120px_120px_130px] md:items-center">
                        <div className="min-w-0">
                            <div className="font-semibold text-slate-950">{task.title}</div>
                            <div className="mt-1 text-sm text-slate-500">{task.area?.name ?? 'No area'} / {task.project?.name ?? 'No project'}</div>
                        </div>
                        <Badge tone={statusTone[task.status]}>{task.status}</Badge>
                        <Badge tone={priorityTone[task.priority]}>{task.priority}</Badge>
                        <div className="md:text-right"><DueDate date={task.due_date} status={task.status} /></div>
                    </Link>
                ))}
            </div>
        </PageSection>
    );
}

function Metric({ label, value, tone = 'text-slate-950' }) {
    return (
        <div className="rounded-2xl border border-white/80 bg-white/80 p-4 shadow-sm shadow-slate-200/60">
            <div className="text-[11px] font-bold uppercase tracking-wide text-slate-400">{label}</div>
            <div className={`mt-1 text-2xl font-bold ${tone}`}>{value}</div>
        </div>
    );
}
