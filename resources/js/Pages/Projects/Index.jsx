import { Badge, DueDate, EmptyState, FilterBar, ProgressBar, ProjectTile, inputClass, primaryButton, secondaryButton, statusTone, visibilityTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const statusLabels = { active: 'Active', on_hold: 'On hold', completed: 'Completed', archived: 'Archived' };
const visibilityLabels = { workspace: 'Workspace', team: 'Team', private: 'Private' };
const healthLabels = { on_track: 'On track', at_risk: 'At risk', off_track: 'Off track', paused: 'Paused' };
const viewLabels = { kanban: 'Kanban', grid: 'Grid', list: 'List' };

export default function Index({ projects, filters, statuses, visibilities }) {
    const [values, setValues] = useState(filters);
    const [view, setView] = useState('kanban');
    const kanbanStatuses = useMemo(() => {
        const visibleStatuses = statuses.filter((status) => projects.some((project) => project.status === status));
        return visibleStatuses.length > 0 ? visibleStatuses : statuses.filter((status) => status !== 'archived');
    }, [projects, statuses]);

    useEffect(() => {
        const timeout = setTimeout(() => {
            router.get(route('projects.index'), values, { preserveState: true, replace: true });
        }, 250);
        return () => clearTimeout(timeout);
    }, [values]);

    return (
        <AuthenticatedLayout title="Projects" subtitle="Project directory for Miriam workstreams.">
            <Head title="Projects" />

            <div className="space-y-4">
                <div className="flex flex-col gap-3 border-b border-slate-200 pb-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="flex gap-1 rounded-md bg-slate-100 p-1">
                        {['kanban', 'grid', 'list'].map((item) => <button key={item} type="button" onClick={() => setView(item)} className={`rounded px-3 py-1.5 text-sm font-semibold ${view === item ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-900'}`}>{viewLabels[item]}</button>)}
                    </div>
                    <Link href={route('projects.create')} className={primaryButton}>+ Create project</Link>
                </div>

                <FilterBar>
                    <div className="grid gap-2 md:grid-cols-3">
                        <input type="search" value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder="Search projects" className={inputClass} />
                        <select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })} className={inputClass}>
                            <option value="">All statuses</option>
                            {statuses.map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}
                        </select>
                        <select value={values.visibility} onChange={(event) => setValues({ ...values, visibility: event.target.value })} className={inputClass}>
                            <option value="">All visibility</option>
                            {visibilities.map((visibility) => <option key={visibility} value={visibility}>{visibilityLabels[visibility]}</option>)}
                        </select>
                    </div>
                </FilterBar>

                {projects.length === 0 ? (
                    <div className="rounded-lg border border-slate-200 bg-white">
                        <EmptyState title="No projects found" description="Create the first project or adjust the current filters." />
                    </div>
                ) : view === 'list' ? (
                    <ProjectList projects={projects} />
                ) : view === 'kanban' ? (
                    <ProjectKanban projects={projects} statuses={kanbanStatuses} />
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <Link href={route('projects.create')} className="flex min-h-36 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-white text-sm font-semibold text-slate-500 hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700">+ Create project</Link>
                        {projects.map((project) => <ProjectCard key={project.id} project={project} />)}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function ProjectKanban({ projects, statuses }) {
    const projectsByStatus = statuses.reduce((groups, status) => {
        groups[status] = projects.filter((project) => project.status === status);
        return groups;
    }, {});

    return (
        <div className="overflow-x-auto pb-2">
            <div className="grid min-w-[920px] gap-3 lg:min-w-0" style={{ gridTemplateColumns: `repeat(${Math.max(1, statuses.length)}, minmax(220px, 1fr))` }}>
                {statuses.map((status) => {
                    const columnProjects = projectsByStatus[status] ?? [];

                    return (
                        <section key={status} className="min-h-[26rem] rounded-lg border border-slate-200 bg-slate-50">
                            <div className="flex items-center justify-between border-b border-slate-200 px-3 py-3">
                                <div className="min-w-0">
                                    <div className="truncate text-sm font-bold text-slate-900">{statusLabels[status] ?? status}</div>
                                    <div className="text-xs font-medium text-slate-500">{columnProjects.length} workstream{columnProjects.length === 1 ? '' : 's'}</div>
                                </div>
                                <Badge tone={statusTone[status]}>{columnProjects.length}</Badge>
                            </div>
                            <div className="space-y-3 p-3">
                                {columnProjects.length === 0 ? (
                                    <div className="rounded-md border border-dashed border-slate-300 bg-white p-4 text-sm text-slate-500">No workstreams here.</div>
                                ) : columnProjects.map((project) => <ProjectKanbanCard key={project.id} project={project} />)}
                            </div>
                        </section>
                    );
                })}
            </div>
        </div>
    );
}

function ProjectKanbanCard({ project }) {
    const totalTasks = Number(project.open_tasks_count ?? 0) + Number(project.completed_tasks_count ?? 0);
    const progress = totalTasks > 0 ? Math.round((Number(project.completed_tasks_count ?? 0) / totalTasks) * 100) : 0;
    const healthTone = project.health === 'at_risk' || project.health === 'off_track'
        ? 'bg-amber-50 text-amber-700 ring-amber-100'
        : project.health === 'paused'
            ? 'bg-slate-100 text-slate-700 ring-slate-200'
            : 'bg-emerald-50 text-emerald-700 ring-emerald-100';

    return (
        <article className="rounded-lg border border-slate-200 bg-white p-3 shadow-sm shadow-slate-200/60">
            <div className="flex items-start gap-2">
                <span className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: project.color || project.area?.color || '#e11d48' }} />
                <div className="min-w-0 flex-1">
                    <Link href={route('projects.show', project.id)} className="line-clamp-2 text-sm font-bold leading-5 text-slate-950 hover:text-rose-600">{project.name}</Link>
                    <div className="mt-1 truncate text-xs font-medium text-slate-500">{project.portfolio?.name ?? project.area?.name ?? 'No portfolio'}</div>
                </div>
            </div>

            <div className="mt-3 flex flex-wrap gap-1.5">
                <Badge tone={healthTone}>{healthLabels[project.health] ?? project.health ?? 'On track'}</Badge>
                <Badge tone={visibilityTone[project.visibility]}>{visibilityLabels[project.visibility]}</Badge>
            </div>

            <div className="mt-3">
                <div className="mb-1 flex justify-between text-xs font-medium text-slate-500">
                    <span>{project.completed_tasks_count ?? 0}/{totalTasks} tasks</span>
                    <span>{progress}%</span>
                </div>
                <ProgressBar value={progress} />
            </div>

            <div className="mt-3 grid gap-2 text-xs text-slate-600">
                <div className="flex items-center justify-between gap-2">
                    <span className="truncate">{project.owner?.name ?? 'Unassigned'}</span>
                    <DueDate date={project.due_date} status={project.status} />
                </div>
                <div className="flex gap-2">
                    <Link href={route('projects.show', project.id)} className={secondaryButton}>Open</Link>
                    <Link href={route('projects.board', project.id)} className={secondaryButton}>Board</Link>
                </div>
            </div>
        </article>
    );
}

function ProjectCard({ project }) {
    const totalTasks = Number(project.open_tasks_count ?? 0) + Number(project.completed_tasks_count ?? 0);
    const progress = totalTasks > 0 ? Math.round((Number(project.completed_tasks_count ?? 0) / totalTasks) * 100) : 0;

    return (
        <ProjectTile project={project} href={route('projects.show', project.id)}>
            <div className="mt-4 flex flex-wrap gap-2">
                <Badge tone={statusTone[project.status]}>{statusLabels[project.status]}</Badge>
                <Badge tone={visibilityTone[project.visibility]}>{visibilityLabels[project.visibility]}</Badge>
                <Badge tone={project.health === 'at_risk' || project.health === 'off_track' ? 'bg-amber-50 text-amber-700 ring-amber-100' : 'bg-emerald-50 text-emerald-700 ring-emerald-100'}>{healthLabels[project.health] ?? project.health ?? 'On track'}</Badge>
            </div>
            <div className="mt-4">
                <div className="mb-1 flex justify-between text-xs font-medium text-slate-500"><span>{project.completed_tasks_count ?? 0} of {totalTasks} complete</span><span>{progress}%</span></div>
                <ProgressBar value={progress} />
            </div>
            <div className="mt-4 grid grid-cols-2 gap-2 text-xs text-slate-600">
                <span>{project.owner?.name ?? 'Unassigned'}</span>
                <span className="text-right"><DueDate date={project.due_date} status={project.status} /></span>
            </div>
            <div className="mt-3 text-xs font-semibold text-slate-500">Open project for list, board, and timeline views.</div>
        </ProjectTile>
    );
}

function ProjectList({ projects }) {
    return (
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <table className="min-w-full text-left text-sm">
                <thead>
                    <tr>
                        {['Project', 'Portfolio', 'Status', 'Owner', 'Progress', 'Due'].map((header) => <th key={header} className="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{header}</th>)}
                    </tr>
                </thead>
                <tbody>
                    {projects.map((project) => {
                        const totalTasks = Number(project.open_tasks_count ?? 0) + Number(project.completed_tasks_count ?? 0);
                        const progress = totalTasks > 0 ? Math.round((Number(project.completed_tasks_count ?? 0) / totalTasks) * 100) : 0;
                        return (
                            <tr key={project.id} className="hover:bg-slate-50">
                                <td className="border-b border-slate-100 px-4 py-2 font-medium text-slate-950"><Link href={route('projects.show', project.id)}>{project.name}</Link></td>
                                <td className="border-b border-slate-100 px-4 py-2">{project.portfolio?.name ?? 'No portfolio'}</td>
                                <td className="border-b border-slate-100 px-4 py-2"><Badge tone={statusTone[project.status]}>{statusLabels[project.status]}</Badge></td>
                                <td className="border-b border-slate-100 px-4 py-2">{project.owner?.name ?? 'Unassigned'}</td>
                                <td className="w-48 border-b border-slate-100 px-4 py-2"><ProgressBar value={progress} /></td>
                                <td className="border-b border-slate-100 px-4 py-2"><DueDate date={project.due_date} status={project.status} /></td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
