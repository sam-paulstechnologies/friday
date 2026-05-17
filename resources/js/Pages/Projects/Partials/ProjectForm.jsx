import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Link, useForm } from '@inertiajs/react';

const statusLabels = {
    active: 'Active',
    on_hold: 'On hold',
    completed: 'Completed',
    archived: 'Archived',
};

const visibilityLabels = {
    workspace: 'Workspace',
    team: 'Team',
    private: 'Private',
};

export default function ProjectForm({
    project = null,
    workspaces,
    areas = [],
    portfolios = [],
    teams,
    statuses,
    visibilities,
    healthOptions = [],
    submitLabel,
}) {
    const { data, setData, post, patch, processing, errors } = useForm({
        area_id: project?.area_id ?? '',
        portfolio_id: project?.portfolio_id ?? '',
        workspace_id: project?.workspace_id ?? workspaces[0]?.id ?? '',
        team_id: project?.team_id ?? '',
        name: project?.name ?? '',
        description: project?.description ?? '',
        status: project?.status ?? 'active',
        visibility: project?.visibility ?? 'workspace',
        start_date: project?.start_date ?? '',
        due_date: project?.due_date ?? '',
        color: project?.color ?? '#2563eb',
        project_type: project?.project_type ?? '',
        health: project?.health ?? 'on_track',
        sort_order: project?.sort_order ?? 0,
    });

    const submit = (event) => {
        event.preventDefault();

        if (project) {
            patch(route('projects.update', project.id));
            return;
        }

        post(route('projects.store'));
    };

    const workspaceTeams = teams.filter(
        (team) => String(team.workspace_id) === String(data.workspace_id),
    );
    const areaPortfolios = portfolios.filter(
        (portfolio) => !data.area_id || String(portfolio.area_id) === String(data.area_id),
    );

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-5 lg:grid-cols-2">
                <div>
                    <InputLabel htmlFor="name" value="Project name" />
                    <TextInput
                        id="name"
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        className="mt-1 block w-full"
                        autoFocus
                    />
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="workspace_id" value="Workspace" />
                    <select
                        id="workspace_id"
                        value={data.workspace_id}
                        onChange={(event) => {
                            setData({
                                ...data,
                                workspace_id: event.target.value,
                                team_id: '',
                            });
                        }}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                    >
                        {workspaces.map((workspace) => (
                            <option key={workspace.id} value={workspace.id}>
                                {workspace.name}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.workspace_id} className="mt-2" />
                </div>
            </div>

            <div>
                <InputLabel htmlFor="description" value="Description" />
                <textarea
                    id="description"
                    value={data.description}
                    onChange={(event) => setData('description', event.target.value)}
                    rows="4"
                    className="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                />
                <InputError message={errors.description} className="mt-2" />
            </div>

            <div className="grid gap-5 lg:grid-cols-3">
                <div>
                    <InputLabel htmlFor="area_id" value="Area" />
                    <select
                        id="area_id"
                        value={data.area_id}
                        onChange={(event) => setData({ ...data, area_id: event.target.value, portfolio_id: '' })}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                    >
                        <option value="">No area</option>
                        {areas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                    </select>
                    <InputError message={errors.area_id} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="portfolio_id" value="Portfolio" />
                    <select
                        id="portfolio_id"
                        value={data.portfolio_id}
                        onChange={(event) => setData('portfolio_id', event.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                    >
                        <option value="">No portfolio</option>
                        {areaPortfolios.map((portfolio) => <option key={portfolio.id} value={portfolio.id}>{portfolio.name}</option>)}
                    </select>
                    <InputError message={errors.portfolio_id} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="team_id" value="Team" />
                    <select
                        id="team_id"
                        value={data.team_id}
                        onChange={(event) => setData('team_id', event.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                    >
                        <option value="">No team</option>
                        {workspaceTeams.map((team) => (
                            <option key={team.id} value={team.id}>
                                {team.name}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.team_id} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="status" value="Status" />
                    <select
                        id="status"
                        value={data.status}
                        onChange={(event) => setData('status', event.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                    >
                        {statuses.map((status) => (
                            <option key={status} value={status}>
                                {statusLabels[status]}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.status} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="visibility" value="Visibility" />
                    <select
                        id="visibility"
                        value={data.visibility}
                        onChange={(event) => setData('visibility', event.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                    >
                        {visibilities.map((visibility) => (
                            <option key={visibility} value={visibility}>
                                {visibilityLabels[visibility]}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.visibility} className="mt-2" />
                </div>
            </div>

            <div className="grid gap-5 lg:grid-cols-3">
                <div>
                    <InputLabel htmlFor="project_type" value="Project type" />
                    <TextInput
                        id="project_type"
                        value={data.project_type}
                        onChange={(event) => setData('project_type', event.target.value)}
                        className="mt-1 block w-full"
                        placeholder="client, venture, admin"
                    />
                    <InputError message={errors.project_type} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="health" value="Health" />
                    <select
                        id="health"
                        value={data.health}
                        onChange={(event) => setData('health', event.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                    >
                        {healthOptions.map((health) => <option key={health} value={health}>{health.replace('_', ' ')}</option>)}
                    </select>
                    <InputError message={errors.health} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="start_date" value="Start date" />
                    <TextInput
                        id="start_date"
                        type="date"
                        value={data.start_date}
                        onChange={(event) => setData('start_date', event.target.value)}
                        className="mt-1 block w-full"
                    />
                    <InputError message={errors.start_date} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="due_date" value="Due date" />
                    <TextInput
                        id="due_date"
                        type="date"
                        value={data.due_date}
                        onChange={(event) => setData('due_date', event.target.value)}
                        className="mt-1 block w-full"
                    />
                    <InputError message={errors.due_date} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="color" value="Color" />
                    <TextInput
                        id="color"
                        type="color"
                        value={data.color}
                        onChange={(event) => setData('color', event.target.value)}
                        className="mt-1 block h-10 w-full"
                    />
                    <InputError message={errors.color} className="mt-2" />
                </div>
            </div>

            <div className="flex items-center justify-end gap-3 border-t border-slate-200 pt-5">
                <Link
                    href={route('projects.index')}
                    className="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:text-slate-950"
                >
                    Cancel
                </Link>
                <PrimaryButton disabled={processing}>{submitLabel}</PrimaryButton>
            </div>
        </form>
    );
}
