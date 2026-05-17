import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';

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
                <section className="rounded-lg border border-slate-200 bg-white shadow-sm shadow-slate-200/60">
                    <div className="border-b border-slate-200 p-5">
                        <h3 className="text-base font-semibold text-slate-950">Create Template</h3>
                        <p className="mt-1 text-sm text-slate-500">Capture repeatable task lists for future projects.</p>
                    </div>
                    <form onSubmit={submit} className="space-y-4 p-5">
                        <label className="block">
                            <span className="text-sm font-semibold text-slate-700">Workspace</span>
                            <select value={data.workspace_id} onChange={(event) => setData('workspace_id', event.target.value)} className="mt-2 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                                {workspaces.map((workspace) => (
                                    <option key={workspace.id} value={workspace.id}>{workspace.name}</option>
                                ))}
                            </select>
                        </label>

                        <label className="block">
                            <span className="text-sm font-semibold text-slate-700">Name</span>
                            <input value={data.name} onChange={(event) => setData('name', event.target.value)} className="mt-2 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" />
                            {errors.name && <span className="mt-1 block text-sm text-rose-600">{errors.name}</span>}
                        </label>

                        <label className="block">
                            <span className="text-sm font-semibold text-slate-700">Description</span>
                            <textarea value={data.description} onChange={(event) => setData('description', event.target.value)} rows="3" className="mt-2 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" />
                        </label>

                        <label className="block">
                            <span className="text-sm font-semibold text-slate-700">Template tasks</span>
                            <textarea value={data.task_titles} onChange={(event) => setData('task_titles', event.target.value)} rows="5" placeholder="One task per line" className="mt-2 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" />
                        </label>

                        <button type="submit" disabled={processing} className="w-full rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50">
                            Create Template
                        </button>
                    </form>
                </section>

                <section className="rounded-lg border border-slate-200 bg-white shadow-sm shadow-slate-200/60">
                    <div className="border-b border-slate-200 p-5">
                        <h3 className="text-base font-semibold text-slate-950">Project Templates</h3>
                        <p className="mt-1 text-sm text-slate-500">Start new projects from reusable task sets.</p>
                    </div>
                    <div className="divide-y divide-slate-100">
                        {templates.length === 0 ? (
                            <div className="p-5 text-sm text-slate-500">No templates yet.</div>
                        ) : (
                            templates.map((template) => (
                                <div key={template.id} className="p-5">
                                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <h4 className="font-semibold text-slate-950">{template.name}</h4>
                                            <p className="mt-1 text-sm text-slate-500">{template.description || 'No description.'}</p>
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                {template.tasks.map((task) => (
                                                    <span key={task.id} className="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">
                                                        {task.title}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => router.post(route('templates.create-project', template.id))}
                                            className="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800"
                                        >
                                            Create Project
                                        </button>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
