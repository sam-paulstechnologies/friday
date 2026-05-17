import { Badge, EmptyState, PageSection } from '@/Components/Ui';
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
                                {area.portfolios.map((portfolio) => (
                                    <Link key={portfolio.id} href={route('portfolios.show', portfolio.id)} className="rounded-2xl border border-slate-200 bg-white p-4 transition hover:-translate-y-0.5 hover:shadow-md">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <div className="font-bold text-slate-950">{portfolio.name}</div>
                                                <p className="mt-2 text-sm leading-6 text-slate-500">{portfolio.description}</p>
                                            </div>
                                            <Badge>{portfolio.status}</Badge>
                                        </div>
                                        <div className="mt-4 text-sm text-slate-500">{portfolio.project_count ?? 0} projects / {portfolio.task_count ?? 0} tasks</div>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </PageSection>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
