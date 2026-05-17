import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Link, useForm } from '@inertiajs/react';

export const statusLabels = {
    todo: 'To do',
    in_progress: 'In progress',
    blocked: 'Blocked',
    review: 'Review',
    completed: 'Completed',
    archived: 'Archived',
};

export const priorityLabels = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    urgent: 'Urgent',
};

export default function TaskForm({
    task = null,
    prefilledProject = null,
    workspaces,
    areas = [],
    portfolios = [],
    projects,
    users,
    statuses,
    priorities,
    taskTypes = [],
    submitLabel,
}) {
    const initialWorkspaceId = task?.workspace_id ?? prefilledProject?.workspace_id ?? workspaces[0]?.id ?? '';
    const initialProjectId = task?.project_id ?? prefilledProject?.id ?? '';
    const initialAreaId = task?.area_id ?? prefilledProject?.area_id ?? '';
    const initialPortfolioId = task?.portfolio_id ?? prefilledProject?.portfolio_id ?? '';

    const { data, setData, post, patch, processing, errors } = useForm({
        area_id: initialAreaId,
        portfolio_id: initialPortfolioId,
        workspace_id: initialWorkspaceId,
        project_id: initialProjectId,
        parent_task_id: task?.parent_task_id ?? '',
        task_type: task?.task_type ?? 'task',
        section: task?.section ?? '',
        title: task?.title ?? '',
        description: task?.description ?? '',
        status: task?.status ?? 'todo',
        priority: task?.priority ?? 'medium',
        assignee_id: task?.assignee_id ?? '',
        start_date: task?.start_date ?? '',
        due_date: task?.due_date ?? '',
        position: task?.position ?? 0,
    });

    const workspaceProjects = projects.filter(
        (project) => String(project.workspace_id) === String(data.workspace_id),
    );
    const areaPortfolios = portfolios.filter(
        (portfolio) => !data.area_id || String(portfolio.area_id) === String(data.area_id),
    );

    const submit = (event) => {
        event.preventDefault();

        if (task) {
            patch(route('tasks.update', task.id));
            return;
        }

        post(route('tasks.store'));
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-5 lg:grid-cols-2">
                <div>
                    <InputLabel htmlFor="title" value="Task title" />
                    <TextInput
                        id="title"
                        value={data.title}
                        onChange={(event) => setData('title', event.target.value)}
                        className="mt-1 block w-full"
                        autoFocus
                    />
                    <InputError message={errors.title} className="mt-2" />
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
                                project_id: '',
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
                    <InputLabel htmlFor="project_id" value="Project" />
                    <select
                        id="project_id"
                        value={data.project_id}
                        onChange={(event) => setData('project_id', event.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                    >
                        <option value="">No project</option>
                        {workspaceProjects.map((project) => (
                            <option key={project.id} value={project.id}>
                                {project.name}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.project_id} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="assignee_id" value="Assignee" />
                    <select
                        id="assignee_id"
                        value={data.assignee_id}
                        onChange={(event) => setData('assignee_id', event.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                    >
                        <option value="">Unassigned</option>
                        {users.map((user) => (
                            <option key={user.id} value={user.id}>
                                {user.name}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.assignee_id} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="section" value="Section" />
                    <TextInput
                        id="section"
                        value={data.section}
                        onChange={(event) => setData('section', event.target.value)}
                        className="mt-1 block w-full"
                        placeholder="Backlog, Launch, Operations"
                    />
                    <InputError message={errors.section} className="mt-2" />
                </div>
            </div>

            <div className="grid gap-5 lg:grid-cols-4">
                <div>
                    <InputLabel htmlFor="task_type" value="Task type" />
                    <select
                        id="task_type"
                        value={data.task_type}
                        onChange={(event) => setData('task_type', event.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                    >
                        {taskTypes.map((type) => <option key={type} value={type}>{type.replace('_', ' ')}</option>)}
                    </select>
                    <InputError message={errors.task_type} className="mt-2" />
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
                    <InputLabel htmlFor="priority" value="Priority" />
                    <select
                        id="priority"
                        value={data.priority}
                        onChange={(event) => setData('priority', event.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-slate-500 focus:ring-slate-500"
                    >
                        {priorities.map((priority) => (
                            <option key={priority} value={priority}>
                                {priorityLabels[priority]}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.priority} className="mt-2" />
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
            </div>

            <div className="flex items-center justify-end gap-3 border-t border-slate-200 pt-5">
                <Link
                    href={task ? route('tasks.show', task.id) : route('tasks.index')}
                    className="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:text-slate-950"
                >
                    Cancel
                </Link>
                <PrimaryButton disabled={processing}>{submitLabel}</PrimaryButton>
            </div>
        </form>
    );
}
