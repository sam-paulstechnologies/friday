import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { inputClass, secondaryButton } from '@/Components/Ui';
import { Link, useForm } from '@inertiajs/react';

export default function GoalForm({ goal = null, workspaces = [], users = [], projects = [], statuses = [] }) {
    const { data, setData, post, patch, processing, errors } = useForm({
        workspace_id: goal?.workspace_id ?? workspaces[0]?.id ?? '',
        owner_id: goal?.owner_id ?? '',
        title: goal?.title ?? '',
        description: goal?.description ?? '',
        status: goal?.status ?? 'not_started',
        target_date: goal?.target_date ?? '',
        progress_percentage: goal?.progress_percentage ?? 0,
        project_ids: goal?.project_ids ?? [],
    });

    const submit = (event) => {
        event.preventDefault();
        goal ? patch(route('goals.update', goal.id)) : post(route('goals.store'));
    };

    const toggleProject = (projectId) => {
        const id = Number(projectId);
        setData('project_ids', data.project_ids.map(Number).includes(id)
            ? data.project_ids.filter((current) => Number(current) !== id)
            : [...data.project_ids, id]);
    };

    return (
        <form onSubmit={submit} className="space-y-5">
            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Workspace" error={errors.workspace_id}>
                    <select value={data.workspace_id} onChange={(event) => setData('workspace_id', event.target.value)} className={inputClass}>
                        {workspaces.map((workspace) => <option key={workspace.id} value={workspace.id}>{workspace.name}</option>)}
                    </select>
                </Field>
                <Field label="Owner" error={errors.owner_id}>
                    <select value={data.owner_id ?? ''} onChange={(event) => setData('owner_id', event.target.value)} className={inputClass}>
                        <option value="">No owner</option>
                        {users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                    </select>
                </Field>
            </div>

            <Field label="Title" error={errors.title}>
                <TextInput value={data.title} onChange={(event) => setData('title', event.target.value)} className="w-full" />
            </Field>

            <Field label="Description" error={errors.description}>
                <textarea value={data.description ?? ''} onChange={(event) => setData('description', event.target.value)} className={`${inputClass} min-h-28 w-full`} />
            </Field>

            <div className="grid gap-4 md:grid-cols-3">
                <Field label="Status" error={errors.status}>
                    <select value={data.status} onChange={(event) => setData('status', event.target.value)} className={inputClass}>
                        {statuses.map((status) => <option key={status} value={status}>{status.replaceAll('_', ' ')}</option>)}
                    </select>
                </Field>
                <Field label="Target date" error={errors.target_date}>
                    <TextInput type="date" value={data.target_date ?? ''} onChange={(event) => setData('target_date', event.target.value)} className="w-full" />
                </Field>
                <Field label="Progress %" error={errors.progress_percentage}>
                    <TextInput type="number" min="0" max="100" value={data.progress_percentage} onChange={(event) => setData('progress_percentage', event.target.value)} className="w-full" />
                </Field>
            </div>

            <section className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <div className="text-sm font-semibold text-slate-950">Linked projects</div>
                <div className="mt-3 grid gap-2 md:grid-cols-2">
                    {projects.map((project) => (
                        <label key={project.id} className="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm">
                            <input type="checkbox" checked={data.project_ids.map(Number).includes(Number(project.id))} onChange={() => toggleProject(project.id)} />
                            <span>{project.name}</span>
                        </label>
                    ))}
                </div>
            </section>

            <div className="flex gap-2">
                <PrimaryButton disabled={processing}>{goal ? 'Update goal' : 'Create goal'}</PrimaryButton>
                <Link href={goal ? route('goals.show', goal.id) : route('goals.index')} className={secondaryButton}>Cancel</Link>
            </div>
        </form>
    );
}

function Field({ label, error, children }) {
    return (
        <div>
            <InputLabel value={label} />
            <div className="mt-1">{children}</div>
            <InputError message={error} className="mt-2" />
        </div>
    );
}
