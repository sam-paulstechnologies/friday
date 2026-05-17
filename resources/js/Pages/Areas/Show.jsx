import { Badge, EmptyState, PageSection, Panel, priorityTone, statusTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const sectionLabels = { overdue: 'Overdue', due_today: 'Due Today', upcoming: 'Upcoming', no_due_date: 'No Due Date' };

export default function Show({ area, portfolios, projects, tasks }) {
    return (
        <AuthenticatedLayout title={area.name} subtitle="Area cockpit for portfolios, projects, and active tasks.">
            <Head title={area.name} />
            <div className="space-y-6">
                <Panel className="p-6">
                    <span className="block h-3 w-20 rounded-full" style={{ backgroundColor: area.color ?? '#0f172a' }} />
                    <h2 className="mt-4 text-3xl font-bold text-slate-950">{area.name}</h2>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-500">{area.description}</p>
                </Panel>

                <PageSection title="Portfolios" description={`${portfolios.length} portfolio(s) in this area.`}>
                    <div className="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">
                        {portfolios.map((portfolio) => (
                            <Link key={portfolio.id} href={route('portfolios.show', portfolio.id)} className="rounded-2xl border border-slate-200 p-4 transition hover:bg-slate-50">
                                <div className="font-bold text-slate-950">{portfolio.name}</div>
                                <div className="mt-2 text-sm text-slate-500">{portfolio.project_count} projects / {portfolio.task_count} tasks</div>
                            </Link>
                        ))}
                    </div>
                </PageSection>

                <PageSection title="Projects" description={`${projects.length} active project(s).`}>
                    <div className="divide-y divide-slate-100">
                        {projects.length === 0 ? <EmptyState title="No projects in this area" /> : projects.map((project) => (
                            <Link key={project.id} href={route('projects.show', project.id)} className="flex items-center justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                                <div>
                                    <div className="font-semibold text-slate-950">{project.name}</div>
                                    <div className="mt-1 text-sm text-slate-500">{project.portfolio?.name ?? 'No portfolio'} / {project.owner?.name ?? 'No owner'}</div>
                                </div>
                                <div className="flex flex-wrap justify-end gap-2">
                                    <Badge tone={statusTone[project.status]}>{project.status}</Badge>
                                    <Badge>{project.health}</Badge>
                                </div>
                            </Link>
                        ))}
                    </div>
                </PageSection>

                {Object.entries(sectionLabels).map(([key, label]) => (
                    <TaskSection key={key} title={label} tasks={tasks[key] ?? []} />
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

function TaskSection({ title, tasks }) {
    return (
        <PageSection title={title} description={`${tasks.length} task(s)`}>
            <div className="divide-y divide-slate-100">
                {tasks.length === 0 ? <div className="p-5 text-sm text-slate-500">Nothing here.</div> : tasks.map((task) => (
                    <Link key={task.id} href={route('tasks.show', task.id)} className="grid gap-3 px-5 py-4 hover:bg-slate-50 md:grid-cols-[1fr_120px_120px_120px] md:items-center">
                        <div>
                            <div className="font-semibold text-slate-950">{task.title}</div>
                            <div className="mt-1 text-sm text-slate-500">{task.portfolio?.name ?? 'No portfolio'} / {task.project?.name ?? 'No project'}</div>
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
