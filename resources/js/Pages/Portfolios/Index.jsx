import { EmptyState, PageSection, PortfolioTile, ProgressBar, primaryButton } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function Index({ areas }) {
    return (
        <AuthenticatedLayout title="Portfolios" subtitle="Grouped Life OS portfolios for workstreams, ventures, support, and command objects.">
            <Head title="Portfolios" />
            <div className="space-y-5">
                <div className="flex justify-end">
                    <Link href={route('portfolios.create')} className={primaryButton}>Create portfolio</Link>
                </div>
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
                                    <PortfolioTile
                                        key={portfolio.id}
                                        portfolio={{ ...portfolio, area, projects_count: totalProjects, open_tasks_count: openTasks, overdue_tasks_count: overdueTasks, progress_percentage: progress }}
                                        href={route('portfolios.show', portfolio.id)}
                                    >
                                        <div className="mt-3 grid grid-cols-2 gap-2 text-sm">
                                            <Metric label="Completed" value={completedTasks} />
                                            {hasWork ? (
                                                <div className="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                                                    <div className="mb-1 flex items-center justify-between text-[11px] font-semibold uppercase text-slate-400">
                                                        <span>Progress</span>
                                                        <span>{progress ?? 0}%</span>
                                                    </div>
                                                    <ProgressBar value={progress ?? 0} />
                                                </div>
                                            ) : (
                                                <div className="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-500">
                                                    No active work
                                                </div>
                                            )}
                                        </div>
                                    </PortfolioTile>
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
        <div className="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
            <div className="text-[11px] font-semibold uppercase text-slate-400">{label}</div>
            <div className={`mt-1 text-sm font-bold ${tone}`}>{value}</div>
        </div>
    );
}
