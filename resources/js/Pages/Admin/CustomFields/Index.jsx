import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

const typeLabels = {
    text: 'Text',
    number: 'Number',
    date: 'Date',
    select: 'Select',
    boolean: 'Boolean',
};

const appliesLabels = {
    project: 'Project',
    task: 'Task',
    both: 'Project and task',
};

export default function Index({ workspaces, customFields, fieldTypes, appliesTo }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        workspace_id: workspaces[0]?.id ?? '',
        name: '',
        field_type: 'text',
        applies_to: 'task',
        options: '',
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('admin.custom-fields.store'), {
            preserveScroll: true,
            onSuccess: () => reset('name', 'options'),
        });
    };

    return (
        <AuthenticatedLayout title="Custom Fields" subtitle="Admin settings">
            <Head title="Custom Fields" />

            <div className="grid gap-6 xl:grid-cols-[420px_1fr]">
                <section className="rounded-lg border border-slate-200 bg-white shadow-sm shadow-slate-200/60">
                    <div className="border-b border-slate-200 p-5">
                        <h3 className="text-base font-semibold text-slate-950">Create Field</h3>
                        <p className="mt-1 text-sm text-slate-500">Add workspace metadata for projects or tasks.</p>
                    </div>
                    <form onSubmit={submit} className="space-y-4 p-5">
                        <label className="block">
                            <span className="text-sm font-semibold text-slate-700">Workspace</span>
                            <select value={data.workspace_id} onChange={(event) => setData('workspace_id', event.target.value)} className="mt-2 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                                {workspaces.map((workspace) => (
                                    <option key={workspace.id} value={workspace.id}>{workspace.name}</option>
                                ))}
                            </select>
                            {errors.workspace_id && <span className="mt-1 block text-sm text-rose-600">{errors.workspace_id}</span>}
                        </label>

                        <label className="block">
                            <span className="text-sm font-semibold text-slate-700">Name</span>
                            <input value={data.name} onChange={(event) => setData('name', event.target.value)} className="mt-2 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" />
                            {errors.name && <span className="mt-1 block text-sm text-rose-600">{errors.name}</span>}
                        </label>

                        <div className="grid gap-4 md:grid-cols-2">
                            <label className="block">
                                <span className="text-sm font-semibold text-slate-700">Type</span>
                                <select value={data.field_type} onChange={(event) => setData('field_type', event.target.value)} className="mt-2 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                                    {fieldTypes.map((type) => (
                                        <option key={type} value={type}>{typeLabels[type]}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="block">
                                <span className="text-sm font-semibold text-slate-700">Applies to</span>
                                <select value={data.applies_to} onChange={(event) => setData('applies_to', event.target.value)} className="mt-2 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                                    {appliesTo.map((target) => (
                                        <option key={target} value={target}>{appliesLabels[target]}</option>
                                    ))}
                                </select>
                            </label>
                        </div>

                        <label className="block">
                            <span className="text-sm font-semibold text-slate-700">Select options</span>
                            <textarea value={data.options} onChange={(event) => setData('options', event.target.value)} rows="3" placeholder="One option per line" className="mt-2 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" />
                            {errors.options && <span className="mt-1 block text-sm text-rose-600">{errors.options}</span>}
                        </label>

                        <button type="submit" disabled={processing} className="w-full rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50">
                            Create Custom Field
                        </button>
                    </form>
                </section>

                <section className="rounded-lg border border-slate-200 bg-white shadow-sm shadow-slate-200/60">
                    <div className="border-b border-slate-200 p-5">
                        <h3 className="text-base font-semibold text-slate-950">Fields</h3>
                        <p className="mt-1 text-sm text-slate-500">Available custom fields across workspaces.</p>
                    </div>
                    <div className="divide-y divide-slate-100">
                        {customFields.length === 0 ? (
                            <div className="p-5 text-sm text-slate-500">No custom fields yet.</div>
                        ) : (
                            customFields.map((field) => (
                                <div key={field.id} className="grid gap-3 p-5 md:grid-cols-4 md:items-center">
                                    <div>
                                        <div className="font-semibold text-slate-950">{field.name}</div>
                                        <div className="text-xs text-slate-500">{field.key}</div>
                                    </div>
                                    <div className="text-sm text-slate-600">{field.workspace?.name}</div>
                                    <div className="text-sm text-slate-600">{typeLabels[field.field_type]}</div>
                                    <div className="text-sm text-slate-600">{appliesLabels[field.applies_to]}</div>
                                </div>
                            ))
                        )}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
