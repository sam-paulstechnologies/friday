import { Badge, DueDate, EmptyState, PageSection, Panel, ProgressBar, inputClass, primaryButton, secondaryButton, statusTone } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';

export default function Show({ goal, projects, keyResults, activities }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        title: '',
        target_value: 100,
        current_value: 0,
        unit: '',
        status: 'not_started',
    });

    const submitKeyResult = (event) => {
        event.preventDefault();
        post(route('goals.key-results.store', goal.id), { onSuccess: () => reset() });
    };

    return (
        <AuthenticatedLayout title={goal.title} subtitle={goal.workspace?.name ?? 'Goal'}>
            <Head title={goal.title} />
            <div className="space-y-5">
                <Panel className="p-5">
                    <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                        <div className="min-w-0">
                            <Badge tone={statusTone[goal.status]}>{goal.status.replaceAll('_', ' ')}</Badge>
                            <h2 className="mt-3 text-2xl font-semibold text-slate-950">{goal.title}</h2>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-500">{goal.description}</p>
                            <div className="mt-3 text-sm text-slate-500">{goal.owner?.name ?? 'No owner'} / target {goal.target_date ?? 'not set'}</div>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Link href={route('goals.edit', goal.id)} className={secondaryButton}>Edit</Link>
                            {goal.status === 'archived'
                                ? <button type="button" onClick={() => router.patch(route('goals.restore', goal.id))} className={primaryButton}>Restore</button>
                                : <button type="button" onClick={() => router.patch(route('goals.archive', goal.id))} className={secondaryButton}>Archive</button>}
                        </div>
                    </div>
                    <div className="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div className="mb-2 flex justify-between text-sm font-semibold text-slate-600">
                            <span>Progress</span>
                            <span>{goal.progress_percentage}%</span>
                        </div>
                        <ProgressBar value={goal.progress_percentage} />
                    </div>
                </Panel>

                <section className="grid gap-5 xl:grid-cols-[1fr_0.8fr]">
                    <PageSection title="Key results" description={`${keyResults.length} result(s)`}>
                        {keyResults.length === 0 ? <EmptyState title="No key results yet" /> : (
                            <div className="divide-y divide-slate-100">
                                {keyResults.map((result) => (
                                    <div key={result.id} className="px-4 py-3">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <div className="font-semibold text-slate-950">{result.title}</div>
                                                <div className="mt-1 text-sm text-slate-500">{result.current_value} / {result.target_value} {result.unit}</div>
                                            </div>
                                            <Badge tone={statusTone[result.status]}>{result.status.replaceAll('_', ' ')}</Badge>
                                        </div>
                                        <div className="mt-3">
                                            <ProgressBar value={result.progress_percentage} />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </PageSection>

                    <Panel className="p-4">
                        <h3 className="text-sm font-semibold text-slate-950">Add key result</h3>
                        <form onSubmit={submitKeyResult} className="mt-4 space-y-3">
                            <input value={data.title} onChange={(event) => setData('title', event.target.value)} placeholder="Key result title" className={`${inputClass} w-full`} />
                            {errors.title && <div className="text-xs text-rose-600">{errors.title}</div>}
                            <div className="grid grid-cols-2 gap-2">
                                <input type="number" min="0" value={data.current_value} onChange={(event) => setData('current_value', event.target.value)} className={`${inputClass} w-full`} />
                                <input type="number" min="0.01" value={data.target_value} onChange={(event) => setData('target_value', event.target.value)} className={`${inputClass} w-full`} />
                            </div>
                            <input value={data.unit ?? ''} onChange={(event) => setData('unit', event.target.value)} placeholder="Unit" className={`${inputClass} w-full`} />
                            <select value={data.status} onChange={(event) => setData('status', event.target.value)} className={inputClass}>
                                {['not_started', 'on_track', 'at_risk', 'off_track', 'completed'].map((status) => <option key={status} value={status}>{status.replaceAll('_', ' ')}</option>)}
                            </select>
                            <button type="submit" disabled={processing} className={primaryButton}>Add result</button>
                        </form>
                    </Panel>
                </section>

                <PageSection title="Linked projects" description={`${projects.length} project(s)`}>
                    {projects.length === 0 ? <EmptyState title="No linked projects" /> : (
                        <div className="divide-y divide-slate-100">
                            {projects.map((project) => (
                                <Link key={project.id} href={route('projects.show', project.id)} className="grid gap-3 px-4 py-3 text-sm hover:bg-slate-50 md:grid-cols-[1fr_120px_120px_140px] md:items-center">
                                    <div className="font-semibold text-slate-950">{project.name}</div>
                                    <Badge tone={statusTone[project.status]}>{project.status}</Badge>
                                    <Badge>{project.health}</Badge>
                                    <DueDate date={project.due_date} status={project.status} />
                                </Link>
                            ))}
                        </div>
                    )}
                </PageSection>

                <PageSection title="Recent activity">
                    <div className="divide-y divide-slate-100">
                        {activities.length === 0 ? <EmptyState title="No activity yet" /> : activities.map((activity) => (
                            <div key={activity.id} className="px-4 py-3 text-sm">
                                <div className="font-semibold text-slate-950">{activity.action.replaceAll('_', ' ')}</div>
                                <div className="mt-1 text-slate-500">{activity.description} / {activity.user?.name ?? 'System'} / {activity.created_at}</div>
                            </div>
                        ))}
                    </div>
                </PageSection>
            </div>
        </AuthenticatedLayout>
    );
}
