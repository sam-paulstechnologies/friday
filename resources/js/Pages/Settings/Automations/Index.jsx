import { Badge, EmptyState, inputClass, primaryButton, secondaryButton, PageSection } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { router, useForm } from '@inertiajs/react';

const triggerLabels = {
    task_overdue: 'Task overdue',
    task_due_today: 'Task due today',
    task_completed: 'Task completed',
    task_status_changed: 'Task status changed',
    project_at_risk: 'Project at risk',
    daily_morning_briefing: 'Morning briefing',
    daily_evening_review: 'Evening review',
    recurring_task_due: 'Recurring task due',
};

const actionLabels = {
    notify_assignee: 'Notify assignee',
    notify_project_owner: 'Notify project owner',
    notify_workspace_admins: 'Notify admins',
    move_task_to_today: 'Move task to today',
    add_task_comment: 'Add task comment',
    create_notification: 'Create notification',
    flag_project_at_risk: 'Flag project at risk',
};

export default function Index({ workspace, rules = [], activity = [], triggerTypes = [], actionTypes = [] }) {
    const form = useForm({
        workspace_id: workspace.id,
        name: '',
        description: '',
        trigger_type: triggerTypes[0] ?? 'task_overdue',
        action_type: actionTypes[0] ?? 'create_notification',
        conditions: {},
        action_payload: {},
        is_active: true,
    });

    const activeCount = rules.filter((rule) => rule.is_active).length;

    const toggleRule = (rule) => {
        router.patch(route('settings.automations.toggle', rule.id), { is_active: !rule.is_active }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout title="Automation Settings" subtitle="Simple preset rules for reminders, briefings, and project risk alerts.">
            <div className="space-y-4">
                <div className="grid gap-3 md:grid-cols-3">
                    <SummaryCard label="Active rules" value={activeCount} />
                    <SummaryCard label="Total rules" value={rules.length} />
                    <SummaryCard label="Recent runs" value={rules.reduce((total, rule) => total + Number(rule.runs_count ?? 0), 0)} />
                </div>

                <PageSection title="Preset automation rules" description={`${workspace.name} rules can be enabled or disabled by workspace admins.`}>
                    <div className="divide-y divide-slate-100">
                        {rules.length === 0 ? <EmptyState title="No automation rules yet" /> : rules.map((rule) => (
                            <div key={rule.id} className="grid gap-3 px-4 py-3 text-sm lg:grid-cols-[minmax(0,1fr)_180px_180px_120px] lg:items-center">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <div className="font-semibold text-slate-950">{rule.name}</div>
                                        <Badge tone={rule.is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : 'bg-slate-100 text-slate-600 ring-slate-200'}>{rule.is_active ? 'active' : 'inactive'}</Badge>
                                    </div>
                                    <p className="mt-1 text-slate-500">{rule.description}</p>
                                    <div className="mt-1 text-xs text-slate-400">Last run: {rule.last_run_at ?? 'Never'}</div>
                                </div>
                                <Badge>{triggerLabels[rule.trigger_type] ?? rule.trigger_type}</Badge>
                                <Badge>{actionLabels[rule.action_type] ?? rule.action_type}</Badge>
                                <button type="button" onClick={() => toggleRule(rule)} className={secondaryButton}>{rule.is_active ? 'Disable' : 'Enable'}</button>
                            </div>
                        ))}
                    </div>
                </PageSection>

                <PageSection title="Add simple rule" description="Use presets where possible; custom rules stay lightweight and extensible.">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(route('settings.automations.store'), {
                                preserveScroll: true,
                                onSuccess: () => form.reset('name', 'description'),
                            });
                        }}
                        className="grid gap-3 p-4 md:grid-cols-2"
                    >
                        <Field label="Name" error={form.errors.name}>
                            <input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} className={`${inputClass} w-full`} />
                        </Field>
                        <Field label="Trigger" error={form.errors.trigger_type}>
                            <select value={form.data.trigger_type} onChange={(event) => form.setData('trigger_type', event.target.value)} className={`${inputClass} w-full`}>
                                {triggerTypes.map((type) => <option key={type} value={type}>{triggerLabels[type] ?? type}</option>)}
                            </select>
                        </Field>
                        <Field label="Action" error={form.errors.action_type}>
                            <select value={form.data.action_type} onChange={(event) => form.setData('action_type', event.target.value)} className={`${inputClass} w-full`}>
                                {actionTypes.map((type) => <option key={type} value={type}>{actionLabels[type] ?? type}</option>)}
                            </select>
                        </Field>
                        <Field label="Description" error={form.errors.description}>
                            <input value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} className={`${inputClass} w-full`} />
                        </Field>
                        <div className="md:col-span-2">
                            <button type="submit" disabled={form.processing} className={primaryButton}>Create rule</button>
                        </div>
                    </form>
                </PageSection>

                <PageSection title="Recent automation activity" description="Audit-safe automation activity for this workspace.">
                    {activity.length === 0 ? (
                        <EmptyState title="No automation activity yet" />
                    ) : (
                        <div className="divide-y divide-slate-100">
                            {activity.map((item) => (
                                <div key={item.id} className="grid gap-2 px-4 py-3 text-sm md:grid-cols-[220px_minmax(0,1fr)_180px]">
                                    <div className="font-semibold text-slate-900">{item.action.replaceAll('_', ' ')}</div>
                                    <div className="truncate text-slate-600">{item.metadata?.automation_rule_name ?? item.actor?.name ?? 'System'}</div>
                                    <div className="text-xs text-slate-500 md:text-right">{item.created_at}</div>
                                </div>
                            ))}
                        </div>
                    )}
                </PageSection>
            </div>
        </AuthenticatedLayout>
    );
}

function Field({ label, error, children }) {
    return (
        <label className="block">
            <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</span>
            <div className="mt-1">{children}</div>
            {error && <span className="mt-1 block text-sm text-rose-600">{error}</span>}
        </label>
    );
}

function SummaryCard({ label, value }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4">
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-2 text-2xl font-semibold text-slate-950">{value}</div>
        </div>
    );
}
