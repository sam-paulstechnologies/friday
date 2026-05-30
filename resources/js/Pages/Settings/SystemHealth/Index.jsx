import { Badge, PageSection, secondaryButton } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const tones = {
    passed: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    warning: 'bg-amber-50 text-amber-700 ring-amber-100',
    failed: 'bg-rose-50 text-rose-700 ring-rose-100',
};

export default function Index({ health }) {
    const counts = health.checks.reduce((carry, check) => ({
        ...carry,
        [check.status]: (carry[check.status] ?? 0) + 1,
    }), {});

    return (
        <AuthenticatedLayout title="System Health" subtitle="Safe production readiness checks for owners and admins.">
            <Head title="System Health" />

            <div className="space-y-5">
                <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/50">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <Badge tone={tones[health.overall] ?? tones.warning}>Overall {health.overall}</Badge>
                            <h2 className="mt-3 text-xl font-semibold tracking-tight text-slate-950">Production readiness snapshot</h2>
                            <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-500">
                                This page intentionally shows configuration status only. Secrets, tokens, and credentials are never displayed.
                            </p>
                        </div>
                        <Link href={route('settings.workspace.edit')} className={secondaryButton}>Workspace Settings</Link>
                    </div>
                    <div className="mt-5 grid gap-3 sm:grid-cols-3">
                        <Metric label="Passed" value={counts.passed ?? 0} tone="text-emerald-600" />
                        <Metric label="Warnings" value={counts.warning ?? 0} tone="text-amber-600" />
                        <Metric label="Failed" value={counts.failed ?? 0} tone="text-rose-600" />
                    </div>
                </section>

                <PageSection title="Checks" description={`${health.checks.length} readiness check(s)`}>
                    <div className="divide-y divide-slate-100">
                        {health.checks.map((check) => (
                            <article key={check.name} className="grid gap-3 px-4 py-3 text-sm lg:grid-cols-[180px_minmax(0,1fr)] lg:items-start">
                                <div className="flex items-center gap-2">
                                    <Badge tone={tones[check.status] ?? tones.warning}>{check.status}</Badge>
                                    <span className="font-semibold text-slate-950">{check.name}</span>
                                </div>
                                <div>
                                    <p className="text-slate-600">{check.message}</p>
                                    {check.context && (
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {Object.entries(check.context).map(([key, value]) => (
                                                <span key={key} className="rounded-md bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-500 ring-1 ring-slate-200">
                                                    {key}: {formatValue(value)}
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </article>
                        ))}
                    </div>
                </PageSection>
            </div>
        </AuthenticatedLayout>
    );
}

function Metric({ label, value, tone }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className={`mt-1 text-2xl font-semibold ${tone}`}>{value}</div>
        </div>
    );
}

function formatValue(value) {
    if (Array.isArray(value)) {
        return value.join(', ');
    }

    if (typeof value === 'boolean') {
        return value ? 'yes' : 'no';
    }

    return String(value);
}
