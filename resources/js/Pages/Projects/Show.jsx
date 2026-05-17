import { Badge, EmptyState, MetadataItem, Panel, ViewSwitcher, priorityTone, primaryButton, secondaryButton, statusTone, visibilityTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const statusLabels = { active: 'Active', on_hold: 'On hold', completed: 'Completed', archived: 'Archived' };
const visibilityLabels = { workspace: 'Workspace', team: 'Team', private: 'Private' };
const healthLabels = { on_track: 'On track', at_risk: 'At risk', off_track: 'Off track', paused: 'Paused' };
const taskStatusLabels = { todo: 'To do', in_progress: 'In progress', blocked: 'Blocked', review: 'Review', completed: 'Completed', archived: 'Archived' };
const priorityLabels = { low: 'Low', medium: 'Medium', high: 'High', urgent: 'Urgent' };

export default function Show({ project, tasks }) {
    const archive = () => router.patch(route('projects.archive', project.id));

    return (
        <AuthenticatedLayout title={project.name} subtitle={project.workspace?.name}>
            <Head title={project.name} />

            <div className="space-y-6">
                <Panel className="overflow-hidden">
                    <div className="bg-gradient-to-br from-white via-slate-50 to-blue-50/70 p-6">
                        <div className="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="h-3.5 w-3.5 rounded-full" style={{ backgroundColor: project.color ?? '#2563eb' }} />
                                    <Badge tone={statusTone[project.status]}>{statusLabels[project.status]}</Badge>
                                    <Badge tone={visibilityTone[project.visibility]}>{visibilityLabels[project.visibility]}</Badge>
                                    <Badge>{healthLabels[project.health] ?? project.health ?? 'On track'}</Badge>
                                    {project.area && <Badge>{project.area.name}</Badge>}
                                    {project.portfolio && <Badge>{project.portfolio.name}</Badge>}
                                </div>
                                <h2 className="mt-4 max-w-4xl text-3xl font-bold tracking-tight text-slate-950">{project.name}</h2>
                                <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                                    {project.description || 'No description has been added yet. Add more context from the edit screen.'}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Link href={route('projects.board', project.id)} className={secondaryButton}>Board</Link>
                                <Link href={route('projects.timeline', project.id)} className={secondaryButton}>Timeline</Link>
                                <Link href={route('projects.edit', project.id)} className={secondaryButton}>Edit</Link>
                                {project.status !== 'archived' && (
                                    <button type="button" onClick={archive} className={primaryButton}>Archive</button>
                                )}
                            </div>
                        </div>

                        <div className="mt-6 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                            <MetadataItem label="Owner" value={project.owner?.name ?? 'Unassigned'} />
                            <MetadataItem label="Team" value={project.team?.name ?? 'No team'} />
                            <MetadataItem label="Start" value={project.start_date ?? 'Not set'} />
                            <MetadataItem label="Due" value={project.due_date ?? 'Not set'} />
                            <MetadataItem label="Tasks" value={tasks.length} />
                            <MetadataItem label="Area" value={project.area?.name ?? 'Not set'} />
                            <MetadataItem label="Portfolio" value={project.portfolio?.name ?? 'Not set'} />
                        </div>
                    </div>
                </Panel>

                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <ViewSwitcher
                        items={[
                            { label: 'Overview', href: route('projects.show', project.id), active: true, component: Link },
                            { label: 'List', href: route('projects.show', project.id), component: Link },
                            { label: 'Board', href: route('projects.board', project.id), component: Link },
                            { label: 'Timeline', href: route('projects.timeline', project.id), component: Link },
                            { label: 'Calendar', href: route('calendar.index'), component: Link },
                        ]}
                    />
                    <Link href={route('projects.tasks.create', project.id)} className={primaryButton}>Add Task</Link>
                </div>

                <Panel className="overflow-hidden">
                    <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                        <div>
                            <h3 className="text-base font-bold text-slate-950">Project tasks</h3>
                            <p className="mt-1 text-sm text-slate-500">A list workspace for work linked to this project.</p>
                        </div>
                    </div>

                    {tasks.length === 0 ? (
                        <EmptyState title="No tasks yet" description="Add the first task to turn this project into an execution plan." />
                    ) : (
                        <div className="divide-y divide-slate-100">
                            {tasks.map((task) => (
                                <Link key={task.id} href={route('tasks.show', task.id)} className="grid gap-3 px-5 py-4 transition hover:bg-slate-50 lg:grid-cols-[1fr_140px_120px_160px_120px] lg:items-center">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-3">
                                            <span className="h-4 w-4 rounded-full border-2 border-slate-300 bg-white" />
                                            <div className="min-w-0">
                                                <div className="truncate font-semibold text-slate-950">{task.title}</div>
                                                <div className="mt-1 text-xs text-slate-500">
                                                    {task.area?.name ?? project.area?.name ?? 'No area'} / {task.portfolio?.name ?? project.portfolio?.name ?? 'No portfolio'}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <Badge tone={statusTone[task.status]}>{taskStatusLabels[task.status]}</Badge>
                                    <Badge tone={priorityTone[task.priority]}>{priorityLabels[task.priority]}</Badge>
                                    <div className="text-sm text-slate-600">{task.assignee?.name ?? 'Unassigned'}</div>
                                    <div className="text-sm font-medium text-slate-600 lg:text-right">{task.due_date ?? 'No due date'}</div>
                                </Link>
                            ))}
                        </div>
                    )}
                </Panel>

                <section className="grid gap-4 lg:grid-cols-2">
                    <Panel className="p-5">
                        <h3 className="text-base font-bold text-slate-950">Project activity</h3>
                        <p className="mt-2 text-sm leading-6 text-slate-500">Project-level activity summaries need backend aggregation in a later phase.</p>
                    </Panel>
                    <Panel className="p-5">
                        <h3 className="text-base font-bold text-slate-950">Planning notes</h3>
                        <p className="mt-2 text-sm leading-6 text-slate-500">Use Board, Timeline, and Calendar views to inspect the same work from different planning angles.</p>
                    </Panel>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
