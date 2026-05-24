import { Badge, EmptyState, Panel, ProgressBar } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function Index({ areas }) {
    return (
        <AuthenticatedLayout title="Areas" subtitle="Life OS operating areas across work, ventures, people, foundation, finance, and command.">
            <Head title="Areas" />
            <div className="grid gap-4 xl:grid-cols-3">
                {areas.length === 0 ? (
                    <Panel className="xl:col-span-3"><EmptyState title="No areas yet" description="Run the seeder after migration to create the default Life OS areas." /></Panel>
                ) : areas.map((area) => (
                    <Link key={area.id} href={route('areas.show', area.id)} className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/70 transition duration-200 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <span className="block h-3 w-12 rounded-full" style={{ backgroundColor: area.color ?? '#0f172a' }} />
                                <h2 className="mt-4 text-xl font-bold text-slate-950">{area.name}</h2>
                                <p className="mt-2 text-sm leading-6 text-slate-500">{area.description}</p>
                            </div>
                            <Badge>{area.is_active ? 'Active' : 'Inactive'}</Badge>
                        </div>
                        <div className="mt-5 grid grid-cols-2 gap-3 text-sm">
                            <Metric label="Portfolios" value={area.portfolio_count} />
                            <Metric label="Projects" value={area.project_count} />
                            <Metric label="Open tasks" value={area.open_task_count} />
                            <Metric label="Due today" value={area.due_today_count} />
                        </div>
                        <ProgressBar value={Math.max(0, 100 - ((Number(area.overdue_task_count) / Math.max(1, Number(area.open_task_count))) * 100))} className="mt-4" />
                        <div className="mt-3 text-sm font-semibold text-rose-600">{area.overdue_task_count} overdue</div>
                    </Link>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

function Metric({ label, value }) {
    return (
        <div className="rounded-2xl border border-slate-100 bg-slate-50 p-3">
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">{label}</div>
            <div className="mt-1 text-lg font-bold text-slate-950">{value}</div>
        </div>
    );
}
