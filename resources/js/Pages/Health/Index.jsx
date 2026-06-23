import HealthSummaryWidget from '@/Components/HealthSummaryWidget';
import { Badge, EmptyState, Panel, PageSection, inputClass, primaryButton, secondaryButton } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const scoreOptions = [1, 2, 3, 4, 5];
const focusOptions = ['cardio', 'strength', 'mobility', 'recovery', 'rest'];

export default function Index({ health, medicationDoseStatus, medications, recentWorkouts, googleCalendar }) {
    return (
        <AuthenticatedLayout title="Health Discipline" subtitle="Sleep, gym readiness, medication confirmation, and practical workout logging.">
            <Head title="Health Discipline" />

            <div className="space-y-5">
                <HealthSummaryWidget health={health} />

                <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_420px]">
                    <div className="space-y-5">
                        <HealthCheckForm health={health} />
                        <WorkoutLogCard health={health} recentWorkouts={recentWorkouts} />
                    </div>
                    <MedicationCard medications={medications} medicationStatus={health.medication} doseStatus={medicationDoseStatus} googleCalendar={googleCalendar} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function HealthCheckForm({ health }) {
    const today = health?.today ?? {};
    const form = useForm({
        sleep_hours: today.sleep_hours ?? '',
        sleep_quality: today.sleep_quality ?? '',
        energy_score: today.energy_score ?? '',
        mood_score: today.mood_score ?? '',
        notes: today.notes ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('health.daily-log.store'), {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['health'], preserveScroll: true }),
        });
    };

    return (
        <Panel>
            <form onSubmit={submit} className="space-y-4 p-4">
                <div>
                    <h3 className="text-sm font-semibold text-slate-950">Today's Health Check</h3>
                    <p className="mt-1 text-xs text-slate-500">Miriam uses this to approve or soften today's gym plan.</p>
                </div>
                <div className="grid gap-3 md:grid-cols-4">
                    <Field label="Sleep hours">
                        <input type="number" step="0.25" min="0" max="24" value={form.data.sleep_hours} onChange={(event) => form.setData('sleep_hours', event.target.value)} className={`${inputClass} w-full`} />
                    </Field>
                    <SelectField label="Sleep quality" value={form.data.sleep_quality} onChange={(value) => form.setData('sleep_quality', value)} />
                    <SelectField label="Energy" value={form.data.energy_score} onChange={(value) => form.setData('energy_score', value)} />
                    <SelectField label="Mood" value={form.data.mood_score} onChange={(value) => form.setData('mood_score', value)} />
                </div>
                <textarea value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} rows="3" className={`${inputClass} w-full`} placeholder="Anything Miriam should know about recovery, stress, or body condition?" />
                {Object.keys(form.errors).length > 0 && <div className="text-sm font-medium text-rose-600">{Object.values(form.errors)[0]}</div>}
                <button type="submit" disabled={form.processing} className={primaryButton}>Save health check</button>
            </form>
        </Panel>
    );
}

function MedicationCard({ medications, medicationStatus, doseStatus, googleCalendar }) {
    const [processingAction, setProcessingAction] = useState(null);
    const form = useForm({
        name: '',
        dosage: '',
        schedule_time: '',
        notes: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('health.medications.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                router.reload({ only: ['health', 'medications'], preserveScroll: true });
            },
        });
    };

    const updateMedication = (medication, action) => {
        const key = `${medication.id}-${action}`;
        if (processingAction) return;

        setProcessingAction(key);
        router.post(route(`health.medications.${action}`, medication.id), {}, {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['health', 'medications'], preserveScroll: true }),
            onFinish: () => setProcessingAction(null),
        });
    };

    const statusById = Object.fromEntries((medicationStatus?.items ?? []).map((item) => [item.id, item]));
    const updateDose = (dose, action, payload = {}) => {
        const key = `dose-${dose.id}-${action}`;
        if (processingAction) return;

        setProcessingAction(key);
        router.post(route(`health.medication-doses.${action}`, dose.id), payload, {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['health', 'medicationDoseStatus'], preserveScroll: true }),
            onFinish: () => setProcessingAction(null),
        });
    };

    const skipDose = (dose) => {
        const reason = window.prompt('Optional skip reason');
        updateDose(dose, 'skip', { reason: reason || '' });
    };

    return (
        <Panel>
            <div className="border-b border-slate-200 px-4 py-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 className="text-sm font-semibold text-slate-950">Medication</h3>
                        <p className="text-xs text-slate-500">Today&apos;s routine, reminders, acknowledgements, and audit history.</p>
                    </div>
                    {googleCalendar?.connected ? (
                        <Badge tone="bg-emerald-50 text-emerald-700 ring-emerald-100">Google Calendar connected</Badge>
                    ) : (
                        <Link
                            href={googleCalendar?.connect_url ?? route('settings.integrations.google.connect')}
                            className={`${secondaryButton} ${!googleCalendar?.configured ? 'pointer-events-none opacity-50' : ''}`}
                        >
                            Connect Google Calendar
                        </Link>
                    )}
                </div>
            </div>
            <div className="space-y-3 p-4">
                {(doseStatus?.items ?? []).length > 0 && (
                    <div className="space-y-3">
                        {(doseStatus?.items ?? []).map((dose) => {
                            const tone = dose.status === 'taken'
                                ? 'bg-emerald-50 text-emerald-700 ring-emerald-100'
                                : dose.status === 'skipped'
                                    ? 'bg-slate-100 text-slate-600 ring-slate-200'
                                    : dose.overdue
                                        ? 'bg-rose-50 text-rose-700 ring-rose-100'
                                        : 'bg-amber-50 text-amber-700 ring-amber-100';
                            return (
                                <div key={dose.id} className="rounded-md border border-slate-200 bg-white p-3">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <div className="font-semibold text-slate-950">{dose.label}</div>
                                            <div className="text-xs text-slate-500">{dose.dosage_text} / {dose.timing_note} / {dose.scheduled_for_local ?? dose.schedule_time}</div>
                                            <div className="mt-1 text-xs text-slate-500">Attempts: {dose.reminder_attempts} / Last channel: {dose.last_delivery_channel || dose.acknowledgement_channel || 'none'}</div>
                                        </div>
                                        <Badge tone={tone}>{dose.overdue && !['taken', 'skipped'].includes(dose.status) ? 'overdue' : dose.status}</Badge>
                                    </div>
                                    {!['taken', 'skipped'].includes(dose.status) && (
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            <button type="button" onClick={() => updateDose(dose, 'taken')} disabled={!!processingAction} className={primaryButton}>{processingAction === `dose-${dose.id}-taken` ? 'Saving...' : 'Taken'}</button>
                                            <button type="button" onClick={() => updateDose(dose, 'snooze', { minutes: 30 })} disabled={!!processingAction} className={secondaryButton}>{processingAction === `dose-${dose.id}-snooze` ? 'Saving...' : 'Snooze'}</button>
                                            <button type="button" onClick={() => skipDose(dose)} disabled={!!processingAction} className={secondaryButton}>{processingAction === `dose-${dose.id}-skip` ? 'Saving...' : 'Skip'}</button>
                                        </div>
                                    )}
                                    {dose.history?.length > 0 && (
                                        <div className="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-500">
                                            {dose.history.map((event, index) => (
                                                <div key={`${dose.id}-${event.event_type}-${index}`}>{event.occurred_at}: {event.event_type}{event.channel ? ` via ${event.channel}` : ''}</div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}

                {medications.length === 0 ? (
                    <EmptyState title="No medication configured" description="Add a medication to track daily confirmation." />
                ) : (
                    medications.map((medication) => {
                        const status = statusById[medication.id]?.status ?? 'pending';
                        return (
                            <div key={medication.id} className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <div className="font-semibold text-slate-950">{medication.name}</div>
                                        <div className="text-xs text-slate-500">{medication.dosage || 'No dosage'}{medication.schedule_time ? ` / ${medication.schedule_time}` : ''}</div>
                                    </div>
                                    <Badge tone={status === 'taken' ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : 'bg-amber-50 text-amber-700 ring-amber-100'}>{status}</Badge>
                                </div>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <button type="button" onClick={() => updateMedication(medication, 'taken')} disabled={!!processingAction} className={primaryButton}>{processingAction === `${medication.id}-taken` ? 'Saving...' : 'Taken'}</button>
                                    <button type="button" onClick={() => updateMedication(medication, 'skip')} disabled={!!processingAction} className={secondaryButton}>{processingAction === `${medication.id}-skip` ? 'Saving...' : 'Skip'}</button>
                                    <button type="button" onClick={() => updateMedication(medication, 'snooze')} disabled={!!processingAction} className={secondaryButton}>{processingAction === `${medication.id}-snooze` ? 'Saving...' : 'Snooze'}</button>
                                </div>
                            </div>
                        );
                    })
                )}

                <form onSubmit={submit} className="space-y-3 rounded-md border border-slate-200 bg-white p-3">
                    <div className="text-sm font-semibold text-slate-950">Add medication</div>
                    <input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} className={`${inputClass} w-full`} placeholder="Medication name" required />
                    <div className="grid gap-2 sm:grid-cols-2">
                        <input value={form.data.dosage} onChange={(event) => form.setData('dosage', event.target.value)} className={inputClass} placeholder="Dosage" />
                        <input type="time" value={form.data.schedule_time} onChange={(event) => form.setData('schedule_time', event.target.value)} className={inputClass} />
                    </div>
                    <textarea value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} className={`${inputClass} w-full`} placeholder="Notes" />
                    <button type="submit" disabled={form.processing} className={secondaryButton}>Add medication</button>
                </form>
            </div>
        </Panel>
    );
}

function WorkoutLogCard({ health, recentWorkouts }) {
    const [processingWorkout, setProcessingWorkout] = useState(null);
    const recommendation = health?.recommendation ?? {};
    const form = useForm({
        planned_focus: recommendation.focus ?? 'recovery',
        intensity: recommendation.intensity ?? 'low',
        notes: recommendation.text ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('health.workouts.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('notes');
                router.reload({ only: ['health', 'recentWorkouts'], preserveScroll: true });
            },
        });
    };

    const updateWorkout = (workout, action, payload = {}) => {
        const key = `${workout.id}-${action}`;
        if (processingWorkout) return;

        setProcessingWorkout(key);
        router.post(route(`health.workouts.${action}`, workout.id), payload, {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['health', 'recentWorkouts'], preserveScroll: true }),
            onFinish: () => setProcessingWorkout(null),
        });
    };

    return (
        <PageSection title="Workout Log" description="Plan the session Miriam recommends, then mark it complete or skipped.">
            <div className="space-y-4 p-4">
                <form onSubmit={submit} className="grid gap-3 md:grid-cols-[180px_160px_minmax(0,1fr)_auto] md:items-end">
                    <Field label="Planned focus">
                        <select value={form.data.planned_focus} onChange={(event) => form.setData('planned_focus', event.target.value)} className={`${inputClass} w-full`}>
                            {focusOptions.map((focus) => <option key={focus} value={focus}>{focus}</option>)}
                        </select>
                    </Field>
                    <Field label="Intensity">
                        <select value={form.data.intensity} onChange={(event) => form.setData('intensity', event.target.value)} className={`${inputClass} w-full`}>
                            {['low', 'medium', 'high'].map((item) => <option key={item} value={item}>{item}</option>)}
                        </select>
                    </Field>
                    <Field label="Notes">
                        <input value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} className={`${inputClass} w-full`} />
                    </Field>
                    <button type="submit" disabled={form.processing} className={primaryButton}>Plan workout</button>
                </form>

                <div className="space-y-2">
                    {recentWorkouts.length === 0 ? (
                        <EmptyState title="No workouts logged yet" description="Planned workouts will appear here." />
                    ) : recentWorkouts.map((workout) => (
                        <div key={workout.id} className="flex flex-col gap-3 rounded-md border border-slate-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div className="font-semibold text-slate-950">{workout.workout_date} / {workout.planned_focus ?? workout.actual_focus ?? 'workout'}</div>
                                <div className="text-xs text-slate-500">{workout.status} {workout.duration_minutes ? `/ ${workout.duration_minutes} min` : ''}</div>
                            </div>
                            {workout.status === 'planned' && (
                                <div className="flex gap-2">
                                    <button type="button" onClick={() => updateWorkout(workout, 'complete', { actual_focus: workout.planned_focus, intensity: workout.intensity })} disabled={!!processingWorkout} className={primaryButton}>{processingWorkout === `${workout.id}-complete` ? 'Saving...' : 'Complete'}</button>
                                    <button type="button" onClick={() => updateWorkout(workout, 'skip')} disabled={!!processingWorkout} className={secondaryButton}>{processingWorkout === `${workout.id}-skip` ? 'Saving...' : 'Skip'}</button>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </PageSection>
    );
}

function Field({ label, children }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-semibold text-slate-500">{label}</span>
            {children}
        </label>
    );
}

function SelectField({ label, value, onChange }) {
    return (
        <Field label={label}>
            <select value={value} onChange={(event) => onChange(event.target.value)} className={`${inputClass} w-full`}>
                <option value="">--</option>
                {scoreOptions.map((score) => <option key={score} value={score}>{score}</option>)}
            </select>
        </Field>
    );
}
