import { Badge, DueDate, EmptyState, FilterBar, ProgressBar, ProjectTile, inputClass, primaryButton, secondaryButton, statusTone, visibilityTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const statusLabels = { active: 'Active', on_hold: 'On hold', completed: 'Completed', archived: 'Archived' };
const visibilityLabels = { workspace: 'Workspace', team: 'Team', private: 'Private' };
const healthLabels = { on_track: 'On track', at_risk: 'At risk', off_track: 'Off track', paused: 'Paused' };

export default function Index({ projects, filters, statuses, visibilities }) {
    const [values, setValues] = useState(filters);
    const [view, setView] = useState('grid');

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
                        {['grid', 'list'].map((item) => <button key={item} type="button" onClick={() => setView(item)} className={`rounded px-3 py-1.5 text-sm font-semibold ${view === item ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500'}`}>{item}</button>)}
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
