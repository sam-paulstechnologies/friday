import { Badge, EmptyState, PageSection, ProgressBar } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function Index({ areas }) {
    return (
        <AuthenticatedLayout title="Portfolios" subtitle="Grouped Life OS portfolios for workstreams, ventures, support, and command objects.">
            <Head title="Portfolios" />
            <div className="space-y-5">
                {areas.map((area) => (
                    <PageSection key={area.id} title={area.name} description={`${area.portfolios.length} portfolio(s)`}>
                        {area.portfolios.length === 0 ? (
                            <EmptyState title="No portfolios" />
                        ) : (
                            <div className="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">
                                {area.portfolios.map((portfolio) => {
                                    const totalProjects = Number(portfolio.total_projects_count ?? portfolio.project_count ?? 0);
                                    const openTasks = Number(portfolio.open_tasks_count ?? 0);
                                    const completedTasks = Number(portfolio.completed_tasks_count ?? 0);
                                    const overdueTasks = Number(portfolio.overdue_tasks_count ?? 0);
                                    const progress = portfolio.progress_percentage;
                                    const hasWork = totalProjects + openTasks + completedTasks > 0;

                                    return (
                                    <Link key={portfolio.id} href={route('portfolios.show', portfolio.id)} className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60 transition duration-200 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <div className="font-bold text-slate-950">{portfolio.name}</div>
                                                <p className="mt-2 text-sm leading-6 text-slate-500">{portfolio.description}</p>
                                            </div>
                                            <Badge>{portfolio.status}</Badge>
                                        </div>
                                        <div className="mt-4 grid grid-cols-2 gap-2 text-sm">
                                            <Metric label="Projects" value={totalProjects} />
                                            <Metric label="Open tasks" value={openTasks} />
                                            <Metric label="Completed" value={completedTasks} />
                                            <Metric label="Overdue" value={overdueTasks} tone={overdueTasks > 0 ? 'text-rose-600' : 'text-slate-950'} />
                                        </div>
                                        {hasWork ? (
                                            <div className="mt-4">
                                                <div className="mb-2 flex items-center justify-between text-xs font-semibold text-slate-500">
                                                    <span>Task progress</span>
                                                    <span>{progress ?? 0}%</span>
                                                </div>
                                                <ProgressBar value={progress ?? 0} />
                                            </div>
                                        ) : (
                                            <div className="mt-4 rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm font-semibold text-slate-500">
                                                No active work yet
                                            </div>
                                        )}
                                    </Link>
                                )})}
                            </div>
                        )}
                    </PageSection>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

function Metric({ label, value, tone = 'text-slate-950' }) {
    return (
        <div className="rounded-2xl border border-slate-100 bg-slate-50 p-3">
            <div className="text-[11px] font-bold uppercase tracking-wide text-slate-400">{label}</div>
            <div className={`mt-1 text-lg font-bold ${tone}`}>{value}</div>
        </div>
    );
}
