import { Badge, EmptyState, Toolbar, inputClass, primaryButton, secondaryButton, statusTone, visibilityTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const statusLabels = { active: 'Active', on_hold: 'On hold', completed: 'Completed', archived: 'Archived' };
const visibilityLabels = { workspace: 'Workspace', team: 'Team', private: 'Private' };
const healthLabels = { on_track: 'On track', at_risk: 'At risk', off_track: 'Off track', paused: 'Paused' };

export default function Index({ projects, filters, statuses, visibilities }) {
    const [values, setValues] = useState(filters);

    useEffect(() => {
        const timeout = setTimeout(() => {
            router.get(route('projects.index'), values, { preserveState: true, replace: true });
        }, 250);
        return () => clearTimeout(timeout);
    }, [values]);

    return (
        <AuthenticatedLayout title="Projects" subtitle="A directory of the workstreams moving through Friday.">
            <Head title="Projects" />

            <div className="space-y-5">
                <div className="flex flex-col gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/70 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 className="text-xl font-bold tracking-tight text-slate-950">Project directory</h2>
                        <p className="mt-1 text-sm text-slate-500">Open a project, jump to its board, or inspect the timeline.</p>
                    </div>
                    <Link href={route('projects.create')} className={primaryButton}>Create Project</Link>
                </div>

                <Toolbar>
                    <div className="grid gap-3 md:grid-cols-3">
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
                </Toolbar>

                {projects.length === 0 ? (
                    <div className="rounded-3xl border border-slate-200 bg-white">
                        <EmptyState title="No projects found" description="Create the first project or adjust the current filters." />
                    </div>
                ) : (
                    <div className="grid gap-4 xl:grid-cols-2">
                        {projects.map((project) => (
                            <article key={project.id} className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/70 transition hover:-translate-y-0.5 hover:shadow-md">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-3">
                                            <span className="h-4 w-4 rounded-full" style={{ backgroundColor: project.color ?? '#2563eb' }} />
                                            <Link href={route('projects.show', project.id)} className="truncate text-lg font-bold text-slate-950 hover:text-slate-700">{project.name}</Link>
                                        </div>
                                        <p className="mt-2 line-clamp-2 text-sm leading-6 text-slate-500">{project.description || project.workspace?.name}</p>
                                    </div>
                                    <Badge tone={statusTone[project.status]}>{statusLabels[project.status]}</Badge>
                                </div>

                                <div className="mt-5 grid gap-3 sm:grid-cols-4">
                                    <div>
                                        <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Visibility</div>
                                        <div className="mt-1"><Badge tone={visibilityTone[project.visibility]}>{visibilityLabels[project.visibility]}</Badge></div>
                                    </div>
                                    <div>
                                        <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Owner</div>
                                        <div className="mt-1 truncate text-sm font-semibold text-slate-700">{project.owner?.name ?? 'Unassigned'}</div>
                                    </div>
                                    <div>
                                        <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Team</div>
                                        <div className="mt-1 truncate text-sm font-semibold text-slate-700">{project.team?.name ?? 'No team'}</div>
                                    </div>
                                    <div>
                                        <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Due</div>
                                        <div className="mt-1 text-sm font-semibold text-slate-700">{project.due_date ?? 'No due date'}</div>
                                    </div>
                                </div>

                                <div className="mt-4 flex flex-wrap gap-2">
                                    {project.area && <Badge>{project.area.name}</Badge>}
                                    {project.portfolio && <Badge>{project.portfolio.name}</Badge>}
                                    <Badge tone={project.health === 'at_risk' || project.health === 'off_track' ? 'bg-amber-50 text-amber-700 ring-amber-100' : 'bg-emerald-50 text-emerald-700 ring-emerald-100'}>
                                        {healthLabels[project.health] ?? project.health ?? 'On track'}
                                    </Badge>
                                </div>

                                <div className="mt-5 flex flex-wrap gap-2">
                                    <Link href={route('projects.show', project.id)} className={secondaryButton}>Open</Link>
                                    <Link href={route('projects.board', project.id)} className={secondaryButton}>Board</Link>
                                    <Link href={route('projects.timeline', project.id)} className={secondaryButton}>Timeline</Link>
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
