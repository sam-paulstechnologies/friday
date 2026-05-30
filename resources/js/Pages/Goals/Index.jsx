import { Badge, EmptyState, PageSection, ProgressBar, primaryButton, secondaryButton, statusTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function Index({ goals, filters, statuses }) {
    return (
        <AuthenticatedLayout title="Goals" subtitle="Objectives, key results, linked projects, and leadership progress.">
            <Head title="Goals" />
            <div className="space-y-5">
                <div className="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('goals.index')} className={!filters.status ? primaryButton : secondaryButton}>Active</Link>
                        {statuses.map((status) => <Link key={status} href={route('goals.index', { status })} className={filters.status === status ? primaryButton : secondaryButton}>{status.replaceAll('_', ' ')}</Link>)}
                    </div>
                    <Link href={route('goals.create')} className={primaryButton}>Create goal</Link>
                </div>
                <PageSection title="Goals" description={`${goals.length} objective(s)`}>
                    {goals.length === 0 ? <EmptyState title="No goals yet" /> : (
                        <div className="grid gap-3 p-4 xl:grid-cols-2">
                            {goals.map((goal) => (
                                <Link key={goal.id} href={route('goals.show', goal.id)} className="rounded-lg border border-slate-200 bg-slate-50 p-4 hover:bg-white">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <div className="font-semibold text-slate-950">{goal.title}</div>
                                            <div className="mt-1 text-sm text-slate-500">{goal.owner?.name ?? 'No owner'} / target {goal.target_date ?? 'not set'}</div>
                                        </div>
                                        <Badge tone={statusTone[goal.status]}>{goal.status.replaceAll('_', ' ')}</Badge>
                                    </div>
                                    <div className="mt-4">
                                        <div className="mb-1 text-xs font-semibold text-slate-500">{goal.progress_percentage}% progress / {goal.projects_count ?? 0} project(s) / {goal.key_results_count ?? 0} KR(s)</div>
                                        <ProgressBar value={goal.progress_percentage} />
                                    </div>
                                </Link>
                            ))}
                        </div>
                    )}
                </PageSection>
            </div>
        </AuthenticatedLayout>
    );
}
