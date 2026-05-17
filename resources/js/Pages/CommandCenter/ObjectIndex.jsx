import { Badge, EmptyState, Panel, inputClass, primaryButton, secondaryButton } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';

export default function ObjectIndex({ title, subtitle, routeBase, closeLabel = 'Close', reject = false, items, openStatus, options, fields = [] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        title: '',
        description: '',
        area_id: '',
        portfolio_id: '',
        project_id: '',
        task_id: '',
        waiting_on: '',
        follow_up_date: '',
        decision: '',
        decision_due_date: '',
        severity: 'medium',
        impact: 'medium',
        probability: 'medium',
        mitigation: '',
        requested_by: '',
    });

    const submit = (event) => {
        event.preventDefault();
        post(route(`${routeBase}.store`), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const openItems = items.filter((item) => item.status === openStatus);
    const closedItems = items.filter((item) => item.status !== openStatus);

    return (
        <AuthenticatedLayout title={title} subtitle={subtitle}>
            <Head title={title} />
            <div className="grid gap-6 xl:grid-cols-[0.75fr_1.25fr]">
                <Panel className="p-5">
                    <h2 className="text-lg font-bold text-slate-950">Create {title.slice(0, -1)}</h2>
                    <form onSubmit={submit} className="mt-5 space-y-4">
                        <Field label="Title" error={errors.title}>
                            <input value={data.title} onChange={(event) => setData('title', event.target.value)} className={`${inputClass} w-full`} />
                        </Field>
                        <Field label="Description" error={errors.description}>
                            <textarea value={data.description} onChange={(event) => setData('description', event.target.value)} rows="3" className={`${inputClass} w-full`} />
                        </Field>
                        <div className="grid gap-3 md:grid-cols-2">
                            <Select label="Area" value={data.area_id} onChange={(value) => setData('area_id', value)} options={options.areas} />
                            <Select label="Portfolio" value={data.portfolio_id} onChange={(value) => setData('portfolio_id', value)} options={options.portfolios} />
                            <Select label="Project" value={data.project_id} onChange={(value) => setData('project_id', value)} options={options.projects} />
                            {fields.includes('task') && <Select label="Task" value={data.task_id} onChange={(value) => setData('task_id', value)} options={options.tasks.map((task) => ({ ...task, name: task.title }))} />}
                        </div>
                        {fields.includes('waiting_on') && <Field label="Waiting on"><input value={data.waiting_on} onChange={(event) => setData('waiting_on', event.target.value)} className={`${inputClass} w-full`} /></Field>}
                        {fields.includes('follow_up_date') && <Field label="Follow-up date"><input type="date" value={data.follow_up_date} onChange={(event) => setData('follow_up_date', event.target.value)} className={`${inputClass} w-full`} /></Field>}
                        {fields.includes('decision_due_date') && <Field label="Decision due date"><input type="date" value={data.decision_due_date} onChange={(event) => setData('decision_due_date', event.target.value)} className={`${inputClass} w-full`} /></Field>}
                        {fields.includes('decision') && <Field label="Decision"><textarea value={data.decision} onChange={(event) => setData('decision', event.target.value)} rows="2" className={`${inputClass} w-full`} /></Field>}
                        {fields.includes('severity') && <OptionField label="Severity" value={data.severity} onChange={(value) => setData('severity', value)} values={['low', 'medium', 'high', 'critical']} />}
                        {fields.includes('impact') && <OptionField label="Impact" value={data.impact} onChange={(value) => setData('impact', value)} values={['low', 'medium', 'high', 'critical']} />}
                        {fields.includes('probability') && <OptionField label="Probability" value={data.probability} onChange={(value) => setData('probability', value)} values={['low', 'medium', 'high']} />}
                        {fields.includes('mitigation') && <Field label="Mitigation"><textarea value={data.mitigation} onChange={(event) => setData('mitigation', event.target.value)} rows="2" className={`${inputClass} w-full`} /></Field>}
                        {fields.includes('requested_by') && <Field label="Requested by"><input value={data.requested_by} onChange={(event) => setData('requested_by', event.target.value)} className={`${inputClass} w-full`} /></Field>}
                        <button type="submit" disabled={processing} className={primaryButton}>Create</button>
                    </form>
                </Panel>

                <div className="space-y-5">
                    <ObjectList title="Open" routeBase={routeBase} closeLabel={closeLabel} reject={reject} items={openItems} />
                    <ObjectList title="Closed / secondary" routeBase={routeBase} closeLabel={closeLabel} items={closedItems} secondary />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function ObjectList({ title, routeBase, closeLabel, reject, items, secondary = false }) {
    return (
        <Panel>
            <div className="border-b border-slate-100 px-5 py-4">
                <h3 className="font-bold text-slate-950">{title}</h3>
            </div>
            {items.length === 0 ? <EmptyState title={`No ${title.toLowerCase()} items`} /> : (
                <div className="divide-y divide-slate-100">
                    {items.map((item) => (
                        <div key={item.id} className="px-5 py-4">
                            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div>
                                    <div className="font-semibold text-slate-950">{item.title}</div>
                                    <p className="mt-1 text-sm leading-6 text-slate-500">{item.description || 'No description'}</p>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        <Badge>{item.status}</Badge>
                                        {item.area && <Badge>{item.area.name}</Badge>}
                                        {item.portfolio && <Badge>{item.portfolio.name}</Badge>}
                                        {item.project && <Badge>{item.project.name}</Badge>}
                                        {item.task && <Link href={route('tasks.show', item.task.id)} className="text-xs font-semibold text-slate-600 underline">{item.task.title}</Link>}
                                    </div>
                                </div>
                                {!secondary && (
                                    <div className="flex flex-wrap gap-2">
                                        <button type="button" onClick={() => router.patch(route(`${routeBase}.close`, item.id), {}, { preserveScroll: true })} className={secondaryButton}>{closeLabel}</button>
                                        {reject && <button type="button" onClick={() => router.patch(route(`${routeBase}.reject`, item.id), {}, { preserveScroll: true })} className={secondaryButton}>Reject</button>}
                                    </div>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </Panel>
    );
}

function Field({ label, error, children }) {
    return (
        <label className="block">
            <span className="text-sm font-semibold text-slate-700">{label}</span>
            <div className="mt-1">{children}</div>
            {error && <div className="mt-1 text-sm text-rose-600">{error}</div>}
        </label>
    );
}

function Select({ label, value, onChange, options }) {
    return (
        <Field label={label}>
            <select value={value} onChange={(event) => onChange(event.target.value)} className={`${inputClass} w-full`}>
                <option value="">None</option>
                {options.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}
            </select>
        </Field>
    );
}

function OptionField({ label, value, onChange, values }) {
    return (
        <Field label={label}>
            <select value={value} onChange={(event) => onChange(event.target.value)} className={`${inputClass} w-full`}>
                {values.map((option) => <option key={option} value={option}>{option}</option>)}
            </select>
        </Field>
    );
}
