import { AppCard, Badge, ProgressBar, secondaryButton } from '@/Components/Ui';
import { Link } from '@inertiajs/react';

export default function HealthSummaryWidget({ health }) {
    const today = health?.today;
    const medication = health?.medication;
    const recommendation = health?.recommendation;
    const score = Number(today?.gym_readiness_score ?? 0);

    return (
        <AppCard>
            <div className="flex flex-col gap-3 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 className="text-sm font-semibold text-slate-950">Health Discipline</h3>
                    <p className="text-xs text-slate-500">Sleep, energy, gym approval, medication, and workout focus.</p>
                </div>
                <Link href={route('health.index')} className={secondaryButton}>Open Health</Link>
            </div>
            <div className="grid gap-3 p-4 md:grid-cols-4">
                <Metric label="Sleep" value={today?.sleep_hours ? `${today.sleep_hours}h` : '--'} />
                <Metric label="Energy" value={today?.energy_score ? `${today.energy_score}/5` : '--'} />
                <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                    <div className="flex items-center justify-between">
                        <div className="text-xs font-medium text-slate-500">Gym readiness</div>
                        <Badge tone={today?.gym_approved ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : 'bg-amber-50 text-amber-700 ring-amber-100'}>
                            {today?.gym_approved ? 'Approved' : 'Protect recovery'}
                        </Badge>
                    </div>
                    <div className="mt-2 text-xl font-semibold text-slate-950">{score ? `${score}/5` : '--'}</div>
                    <ProgressBar value={score * 20} className="mt-2" />
                </div>
                <Metric label="Medication" value={medication?.status_label ?? 'No medications'} alert={Number(medication?.pending_count ?? 0) > 0} />
            </div>
            <div className="border-t border-slate-100 px-4 py-3 text-sm leading-6 text-slate-600">
                <span className="font-semibold text-slate-950">{recommendation?.focus ?? today?.workout_focus ?? 'recovery'}:</span> {recommendation?.text ?? today?.gym_recommendation ?? 'Log sleep and energy to get a recommendation.'}
            </div>
        </AppCard>
    );
}

function Metric({ label, value, alert = false }) {
    return (
        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
            <div className="text-xs font-medium text-slate-500">{label}</div>
            <div className={`mt-1 text-lg font-semibold ${alert ? 'text-amber-700' : 'text-slate-950'}`}>{value}</div>
        </div>
    );
}
