import { Avatar, Badge, DueDate, EmptyState, MetadataItem, Panel, ProgressBar, ViewSwitcher, priorityTone, primaryButton, secondaryButton, statusTone, visibilityTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';

const statusLabels = { active: 'Active', on_hold: 'On hold', completed: 'Completed', archived: 'Archived' };
const visibilityLabels = { workspace: 'Workspace', team: 'Team', private: 'Private' };
const healthLabels = { on_track: 'On track', at_risk: 'At risk', off_track: 'Off track', paused: 'Paused' };
const taskStatusLabels = { todo: 'To do', in_progress: 'In progress', blocked: 'Blocked', review: 'Review', completed: 'Completed', archived: 'Archived' };
const priorityLabels = { low: 'Low', medium: 'Medium', high: 'High', urgent: 'Urgent' };

function ProjectMembers({ project, availableMembers }) {
    const { data, setData, post, processing, reset, errors } = useForm({
        user_id: '',
        role: 'member',
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('projects.members.store', project.id), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <Panel>
            <div className="border-b border-slate-100 p-4">
                <h3 className="text-sm font-semibold text-slate-950">Project members</h3>
            </div>
            <div className="divide-y divide-slate-100">
                {project.members.length === 0 ? (
                    <EmptyState title="No project members" description="Add workspace members to make project access explicit." />
                ) : project.members.map((member) => (
                    <div key={member.id} className="flex items-center justify-between gap-3 p-4">
                        <div className="flex min-w-0 items-center gap-2">
                            <Avatar name={member.name} size="sm" />
                            <div className="min-w-0">
                                <div className="truncate text-sm font-semibold text-slate-950">{member.name}</div>
                                <div className="text-xs text-slate-500">{member.role ?? 'member'}</div>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={() => router.delete(route('projects.members.destroy', [project.id, member.id]), { preserveScroll: true })}
                            className="text-xs font-semibold text-rose-600 hover:text-rose-700"
                        >
                            Remove
                        </button>
                    </div>
                ))}
            </div>
            <form onSubmit={submit} className="grid gap-2 border-t border-slate-100 p-4 sm:grid-cols-[1fr_120px_auto]">
                <select value={data.user_id} onChange={(event) => setData('user_id', event.target.value)} className="rounded-md border border-slate-200 text-sm">
                    <option value="">Add workspace member</option>
                    {availableMembers.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                </select>
                <input value={data.role} onChange={(event) => setData('role', event.target.value)} className="rounded-md border border-slate-200 text-sm" />
                <button type="submit" disabled={processing || !data.user_id} className={primaryButton}>Add</button>
                {errors.user_id && <div className="text-sm text-rose-600 sm:col-span-3">{errors.user_id}</div>}
            </form>
        </Panel>
    );
}

function ProjectActivity({ activities }) {
    return (
        <Panel>
            <div className="border-b border-slate-100 p-4">
                <h3 className="text-sm font-semibold text-slate-950">Project activity</h3>
            </div>
            <div className="divide-y divide-slate-100">
                {activities.length === 0 ? (
                    <EmptyState title="No activity yet" description="Project changes will appear here." />
                ) : activities.map((activity) => (
                    <div key={activity.id} className="p-4">
                        <div className="text-sm font-semibold text-slate-950">{activity.action.replaceAll('_', ' ')}</div>
                        {activity.task_title && <p className="mt-1 text-xs font-medium text-slate-500">{activity.task_title}</p>}
                        {activity.description && <p className="mt-1 text-sm text-slate-600">{activity.description}</p>}
                        <div className="mt-1 text-xs text-slate-500">{activity.user?.name ?? 'System'} / {activity.created_at}</div>
                    </div>
                ))}
            </div>
        </Panel>
    );
}

export default function Show({ project, tasks, availableMembers = [], collaborationActivity = [] }) {
    const archive = () => router.patch(route('projects.archive', project.id));
    const restore = () => router.patch(route('projects.restore', project.id));
    const completedTasks = tasks.filter((task) => task.status === 'completed').length;
    const progress = tasks.length > 0 ? Math.round((completedTasks / tasks.length) * 100) : 0;

    return (
        <AuthenticatedLayout title={project.name} subtitle={project.workspace?.name}>
            <Head title={project.name} />

            <div className="space-y-6">
                <Panel className="overflow-hidden">
                    <div className="p-5">
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
                                <h2 className="mt-3 max-w-4xl text-2xl font-semibold tracking-tight text-slate-950">{project.name}</h2>
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
                                {project.status === 'archived' && (
                                    <button type="button" onClick={restore} className={primaryButton}>Restore</button>
                                )}
                            </div>
                        </div>

                        <div className="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Project progress</div>
                                    <div className="mt-1 text-sm font-semibold text-slate-900">{completedTasks} of {tasks.length} tasks complete</div>
                                </div>
                                <span className="text-xl font-bold text-slate-950">{progress}%</span>
                            </div>
                            <ProgressBar value={progress} className="mt-3" />
                        </div>

                        <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                            <MetadataItem label="Owner" value={project.owner?.name ?? 'Unassigned'} />
                            <MetadataItem label="Team" value={project.team?.name ?? 'No team'} />
                            <MetadataItem label="Start" value={project.start_date ?? 'Not set'} />
                            <MetadataItem label="Due"><DueDate date={project.due_date} status={project.status} /></MetadataItem>
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
                                <Link key={task.id} href={route('tasks.show', task.id)} className={`grid gap-3 px-4 py-2.5 text-sm transition hover:bg-slate-50 lg:grid-cols-[1fr_130px_120px_150px_120px] lg:items-center ${task.status === 'completed' ? 'bg-slate-50/60' : ''}`}>
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-3">
                                            <span className={`h-4 w-4 rounded-full border ${task.status === 'completed' ? 'border-emerald-500 bg-emerald-500' : 'border-slate-300 bg-white'}`} />
                                            <div className="min-w-0">
                                                <div className={`truncate font-semibold text-slate-950 ${task.status === 'completed' ? 'text-slate-500 line-through decoration-slate-300' : ''}`}>{task.title}</div>
                                                <div className="mt-1 text-xs text-slate-500">
                                                    {task.area?.name ?? project.area?.name ?? 'No area'} / {task.portfolio?.name ?? project.portfolio?.name ?? 'No portfolio'}
                                                </div>
                                                {task.labels?.length > 0 && (
                                                    <div className="mt-1 flex flex-wrap gap-1">
                                                        {task.labels.map((label) => (
                                                            <span key={label.id} className="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-600">
                                                                <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: label.color ?? '#475569' }} />
                                                                {label.name}
                                                            </span>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    <Badge tone={statusTone[task.status]}>{taskStatusLabels[task.status]}</Badge>
                                    <Badge tone={priorityTone[task.priority]}>{priorityLabels[task.priority]}</Badge>
                                    <div className="flex items-center gap-2 text-sm text-slate-600"><Avatar name={task.assignee?.name ?? 'Unassigned'} size="sm" /> {task.assignee?.name ?? 'Unassigned'}</div>
                                    <div className="lg:text-right"><DueDate date={task.due_date} status={task.status} /></div>
                                </Link>
                            ))}
                        </div>
                    )}
                </Panel>

                <section className="grid gap-4 lg:grid-cols-2">
                    <ProjectActivity activities={collaborationActivity.length ? collaborationActivity : project.activities} />
                    <ProjectMembers project={project} availableMembers={availableMembers} />
                    <Panel className="p-4">
                        <h3 className="text-sm font-semibold text-slate-950">Planning notes</h3>
                        <p className="mt-2 text-sm leading-6 text-slate-500">Use Board, Timeline, and Calendar views to inspect the same work from different planning angles.</p>
                    </Panel>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
