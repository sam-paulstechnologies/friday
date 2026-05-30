import { Badge, DueDate, EmptyState, PageSection, Panel, ProgressBar, priorityTone, statusTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { router } from '@inertiajs/react';

const sectionLabels = { overdue: 'Overdue', due_today: 'Due Today', upcoming: 'Upcoming', no_due_date: 'No Due Date' };

export default function Show({ portfolio, projects, tasks, availableProjects = [] }) {
    const totalProjects = Number(portfolio.total_projects_count ?? portfolio.project_count ?? projects.length ?? 0);
    const openTasks = Number(portfolio.open_tasks_count ?? 0);
    const completedTasks = Number(portfolio.completed_tasks_count ?? 0);
    const overdueTasks = Number(portfolio.overdue_tasks_count ?? 0);
    const hasWork = totalProjects + openTasks + completedTasks > 0;

    return (
        <AuthenticatedLayout title={portfolio.name} subtitle={portfolio.area?.name ?? 'Portfolio'}>
            <Head title={portfolio.name} />
            <div className="space-y-5">
                <Panel className="p-5">
                    <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                        <div className="min-w-0">
                            <Badge>{portfolio.area?.name ?? 'No area'}</Badge>
                            <h2 className="mt-3 text-2xl font-semibold text-slate-950">{portfolio.name}</h2>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-500">{portfolio.description}</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Link href={route('portfolios.edit', portfolio.id)} className="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Edit</Link>
                            {portfolio.status === 'archived'
                                ? <button type="button" onClick={() => router.patch(route('portfolios.restore', portfolio.id))} className="inline-flex items-center justify-center rounded-md bg-rose-500 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-600">Restore</button>
                                : <button type="button" onClick={() => router.patch(route('portfolios.archive', portfolio.id))} className="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Archive</button>}
                        </div>
                        <div className="min-w-72 flex-1 lg:max-w-md">
                            {hasWork ? (
                                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <div className="flex items-center justify-between text-sm font-semibold text-slate-600">
                                        <span>Task progress</span>
                                        <span className="text-slate-950">{portfolio.progress_percentage ?? 0}%</span>
                                    </div>
                                    <ProgressBar value={portfolio.progress_percentage ?? 0} className="mt-3" />
                                </div>
                            ) : (
                                <div className="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-4 text-sm font-semibold text-slate-500">
                                    No active work yet
                                </div>
                            )}
                        </div>
                    </div>
                    <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Metric label="Total projects" value={totalProjects} />
                        <Metric label="Open tasks" value={openTasks} />
                        <Metric label="Completed tasks" value={completedTasks} />
                        <Metric label="Overdue tasks" value={overdueTasks} tone={overdueTasks > 0 ? 'text-rose-600' : 'text-slate-950'} />
                    </div>
                </Panel>

                <PageSection title="Projects" description={`${projects.length} active project(s).`}>
                    <div className="divide-y divide-slate-100">
                        {projects.length === 0 ? <EmptyState title="No projects in this portfolio" /> : projects.map((project) => (
                            <div key={project.id} className="flex flex-col gap-3 px-4 py-3 transition hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                                <div className="min-w-0">
                                    <Link href={route('projects.show', project.id)} className="font-semibold text-slate-950 hover:text-rose-600">{project.name}</Link>
                                    <div className="mt-1 text-sm text-slate-500">{project.owner?.name ?? 'No owner'} / due {project.due_date ?? 'not set'}</div>
                                </div>
                                <div className="flex gap-2">
                                    <Badge tone={statusTone[project.status]}>{project.status}</Badge>
                                    <Badge>{project.health}</Badge>
                                    <button type="button" onClick={() => router.delete(route('portfolios.projects.destroy', [portfolio.id, project.id]))} className="text-xs font-semibold text-slate-500 hover:text-rose-600">Remove</button>
                                </div>
                            </div>
                        ))}
                    </div>
                </PageSection>

                {availableProjects.length > 0 && (
                    <PageSection title="Add project" description="Move an existing workspace project into this portfolio.">
                        <div className="grid gap-2 p-4 md:grid-cols-2">
                            {availableProjects.map((project) => (
                                <button key={project.id} type="button" onClick={() => router.post(route('portfolios.projects.store', portfolio.id), { project_id: project.id })} className="rounded-md border border-slate-200 bg-white px-3 py-2 text-left text-sm font-semibold text-slate-800 hover:bg-slate-50">
                                    {project.name}
                                </button>
                            ))}
                        </div>
                    </PageSection>
                )}

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
                    <Link key={task.id} href={route('tasks.show', task.id)} className="grid gap-3 px-4 py-3 text-sm transition hover:bg-slate-50 md:grid-cols-[1fr_120px_120px_130px] md:items-center">
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
        <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
            <div className="text-[11px] font-semibold uppercase text-slate-400">{label}</div>
            <div className={`mt-1 text-lg font-semibold ${tone}`}>{value}</div>
        </div>
    );
}
