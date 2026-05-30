import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { inputClass, secondaryButton } from '@/Components/Ui';
import { Link, useForm } from '@inertiajs/react';

export default function PortfolioForm({ portfolio = null, workspaces = [], areas = [], users = [], statuses = [] }) {
    const { data, setData, post, patch, processing, errors } = useForm({
        workspace_id: portfolio?.workspace_id ?? workspaces[0]?.id ?? '',
        area_id: portfolio?.area_id ?? '',
        owner_id: portfolio?.owner_id ?? '',
        name: portfolio?.name ?? '',
        description: portfolio?.description ?? '',
        color: portfolio?.color ?? '#64748b',
        icon: portfolio?.icon ?? '',
        status: portfolio?.status ?? 'active',
        position: portfolio?.position ?? 0,
    });

    const submit = (event) => {
        event.preventDefault();
        portfolio ? patch(route('portfolios.update', portfolio.id)) : post(route('portfolios.store'));
    };

    return (
        <form onSubmit={submit} className="space-y-5">
            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Workspace" error={errors.workspace_id}>
                    <select value={data.workspace_id} onChange={(event) => setData('workspace_id', event.target.value)} className={inputClass}>
                        {workspaces.map((workspace) => <option key={workspace.id} value={workspace.id}>{workspace.name}</option>)}
                    </select>
                </Field>
                <Field label="Area" error={errors.area_id}>
                    <select value={data.area_id ?? ''} onChange={(event) => setData('area_id', event.target.value)} className={inputClass}>
                        <option value="">No area</option>
                        {areas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                    </select>
                </Field>
            </div>
            <Field label="Name" error={errors.name}>
                <TextInput value={data.name} onChange={(event) => setData('name', event.target.value)} className="w-full" />
            </Field>
            <Field label="Description" error={errors.description}>
                <textarea value={data.description ?? ''} onChange={(event) => setData('description', event.target.value)} className={`${inputClass} min-h-28 w-full`} />
            </Field>
            <div className="grid gap-4 md:grid-cols-4">
                <Field label="Owner" error={errors.owner_id}>
                    <select value={data.owner_id ?? ''} onChange={(event) => setData('owner_id', event.target.value)} className={inputClass}>
                        <option value="">No owner</option>
                        {users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                    </select>
                </Field>
                <Field label="Status" error={errors.status}>
                    <select value={data.status} onChange={(event) => setData('status', event.target.value)} className={inputClass}>
                        {statuses.map((status) => <option key={status} value={status}>{status.replaceAll('_', ' ')}</option>)}
                    </select>
                </Field>
                <Field label="Color" error={errors.color}>
                    <TextInput value={data.color ?? ''} onChange={(event) => setData('color', event.target.value)} className="w-full" />
                </Field>
                <Field label="Position" error={errors.position}>
                    <TextInput type="number" min="0" value={data.position ?? 0} onChange={(event) => setData('position', event.target.value)} className="w-full" />
                </Field>
            </div>
            <div className="flex gap-2">
                <PrimaryButton disabled={processing}>{portfolio ? 'Update portfolio' : 'Create portfolio'}</PrimaryButton>
                <Link href={portfolio ? route('portfolios.show', portfolio.id) : route('portfolios.index')} className={secondaryButton}>Cancel</Link>
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
