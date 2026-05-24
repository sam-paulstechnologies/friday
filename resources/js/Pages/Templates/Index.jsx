import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { Badge, EmptyState, Panel, inputClass, primaryButton } from '@/Components/Ui';

export default function Index({ workspaces, templates }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        workspace_id: workspaces[0]?.id ?? '',
        name: '',
        description: '',
        task_titles: '',
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('templates.store'), {
            preserveScroll: true,
            onSuccess: () => reset('name', 'description', 'task_titles'),
        });
    };

    return (
        <AuthenticatedLayout title="Templates" subtitle="Reusable project starts">
            <Head title="Templates" />

            <div className="grid gap-6 xl:grid-cols-[420px_1fr]">
                <Panel>
                    <div className="border-b border-slate-200 p-5">
                        <h3 className="text-base font-semibold text-slate-950">Create Template</h3>
                        <p className="mt-1 text-sm text-slate-500">Capture repeatable task lists for future projects.</p>
                    </div>
                    <form onSubmit={submit} className="space-y-4 p-5">
                        <label className="block">
                            <span className="text-sm font-semibold text-slate-700">Workspace</span>
                            <select value={data.workspace_id} onChange={(event) => setData('workspace_id', event.target.value)} className={`${inputClass} mt-2 block w-full`}>
                                {workspaces.map((workspace) => (
                                    <option key={workspace.id} value={workspace.id}>{workspace.name}</option>
                                ))}
                            </select>
                        </label>

                        <label className="block">
                            <span className="text-sm font-semibold text-slate-700">Name</span>
                            <input value={data.name} onChange={(event) => setData('name', event.target.value)} className={`${inputClass} mt-2 block w-full`} />
                            {errors.name && <span className="mt-1 block text-sm text-rose-600">{errors.name}</span>}
                        </label>

                        <label className="block">
                            <span className="text-sm font-semibold text-slate-700">Description</span>
                            <textarea value={data.description} onChange={(event) => setData('description', event.target.value)} rows="3" className={`${inputClass} mt-2 block w-full`} />
                        </label>

                        <label className="block">
                            <span className="text-sm font-semibold text-slate-700">Template tasks</span>
                            <textarea value={data.task_titles} onChange={(event) => setData('task_titles', event.target.value)} rows="5" placeholder="One task per line" className={`${inputClass} mt-2 block w-full`} />
                        </label>

                        <button type="submit" disabled={processing} className={`${primaryButton} w-full`}>
                            Create Template
                        </button>
                    </form>
                </Panel>

                <Panel>
                    <div className="border-b border-slate-200 p-5">
                        <h3 className="text-base font-semibold text-slate-950">Project Templates</h3>
                        <p className="mt-1 text-sm text-slate-500">Start new projects from reusable task sets.</p>
                    </div>
                    <div className="divide-y divide-slate-100">
                        {templates.length === 0 ? (
                            <EmptyState title="No templates yet" description="Create a reusable project starter to speed up repeatable work." />
                        ) : (
                            templates.map((template) => (
                                <div key={template.id} className="p-5">
                                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <h4 className="font-semibold text-slate-950">{template.name}</h4>
                                            <p className="mt-1 text-sm text-slate-500">{template.description || 'No description.'}</p>
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                {template.tasks.map((task) => (
                                                    <Badge key={task.id}>{task.title}</Badge>
                                                ))}
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => router.post(route('templates.create-project', template.id))}
                                            className={primaryButton}
                                        >
                                            Create Project
                                        </button>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </Panel>
            </div>
        </AuthenticatedLayout>
    );
}
