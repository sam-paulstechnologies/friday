import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { inputClass, secondaryButton } from '@/Components/Ui';
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
    labels = [],
    statuses,
    priorities,
    taskTypes = [],
    recurrenceTypes = [],
    workflowStates = [],
    sections = [],
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
        workflow_state: task?.workflow_state ?? '',
        title: task?.title ?? '',
        description: task?.description ?? '',
        status: task?.status ?? 'todo',
        priority: task?.priority ?? 'medium',
        assignee_id: task?.assignee_id ?? '',
        start_date: task?.start_date ?? '',
        due_date: task?.due_date ?? '',
        recurrence_type: task?.recurrence_type ?? 'none',
        recurrence_interval: task?.recurrence_interval ?? 1,
        recurrence_ends_at: task?.recurrence_ends_at ?? '',
        label_ids: task?.labels?.map((label) => label.id) ?? [],
        new_labels: '',
        position: task?.position ?? 0,
    });

    const workspaceProjects = projects.filter(
        (project) => String(project.workspace_id) === String(data.workspace_id),
    );
    const areaPortfolios = portfolios.filter(
        (portfolio) => !data.area_id || String(portfolio.area_id) === String(data.area_id),
    );
    const workspaceLabels = labels.filter(
        (label) => String(label.workspace_id) === String(data.workspace_id),
    );
    const toggleLabel = (labelId) => {
        const selected = data.label_ids.map((id) => Number(id));

        setData('label_ids', selected.includes(labelId)
            ? selected.filter((id) => id !== labelId)
            : [...selected, labelId]);
    };

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
                        data-testid="task-title-input"
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
                                label_ids: [],
                            });
                        }}
                        className={`${inputClass} mt-1 block w-full`}
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
                    className={`${inputClass} mt-1 block w-full`}
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
                        className={`${inputClass} mt-1 block w-full`}
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
                        className={`${inputClass} mt-1 block w-full`}
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
                        className={`${inputClass} mt-1 block w-full`}
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
                        className={`${inputClass} mt-1 block w-full`}
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

                {/* The daily bucket. Canonical values only - this one drives
                    behaviour, so it can never be free text. */}
                <div>
                    <InputLabel htmlFor="workflow_state" value="Bucket" />
                    <select
                        id="workflow_state"
                        value={data.workflow_state}
                        onChange={(event) => setData('workflow_state', event.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500/25"
                    >
                        <option value="">Not scheduled</option>
                        {workflowStates.map((state) => (
                            <option key={state.value} value={state.value}>
                                {state.label}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.workflow_state} className="mt-2" />
                    <p className="mt-1 text-xs text-slate-500">Where this sits in your day. Changes list membership.</p>
                </div>

                {/* The operator's own grouping label inside a project. Existing
                    labels are offered so a typo cannot invent a phantom one. */}
                <div>
                    <InputLabel htmlFor="section" value="Section" />
                    <input
                        id="section"
                        list="task-section-options"
                        value={data.section}
                        onChange={(event) => setData('section', event.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500/25"
                        placeholder="Choose or add a section"
                        autoComplete="off"
                    />
                    <datalist id="task-section-options">
                        {sections.map((section) => (
                            <option key={section} value={section} />
                        ))}
                    </datalist>
                    <InputError message={errors.section} className="mt-2" />
                    <p className="mt-1 text-xs text-slate-500">A grouping label inside the project, like a phase.</p>
                </div>
            </div>

            <div className="grid gap-5 lg:grid-cols-4">
                <div>
                    <InputLabel htmlFor="task_type" value="Task type" />
                    <select
                        id="task_type"
                        value={data.task_type}
                        onChange={(event) => setData('task_type', event.target.value)}
                        className={`${inputClass} mt-1 block w-full`}
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
                        className={`${inputClass} mt-1 block w-full`}
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
                        className={`${inputClass} mt-1 block w-full`}
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

            <div className="grid gap-5 lg:grid-cols-2">
                <div>
                    <InputLabel value="Labels" />
                    <div className="mt-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                        {workspaceLabels.length === 0 ? (
                            <p className="text-sm text-slate-500">No labels in this workspace yet.</p>
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {workspaceLabels.map((label) => (
                                    <label key={label.id} className="inline-flex cursor-pointer items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={data.label_ids.map((id) => Number(id)).includes(label.id)}
                                            onChange={() => toggleLabel(label.id)}
                                            className="rounded border-slate-300 text-slate-900 focus:ring-slate-500"
                                        />
                                        <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: label.color ?? '#475569' }} />
                                        {label.name}
                                    </label>
                                ))}
                            </div>
                        )}
                        <TextInput
                            id="new_labels"
                            value={data.new_labels}
                            onChange={(event) => setData('new_labels', event.target.value)}
                            className="mt-3 block w-full"
                            placeholder="Add new labels, separated by commas"
                        />
                    </div>
                    <InputError message={errors.label_ids || errors.new_labels} className="mt-2" />
                </div>

                <div className="grid gap-5 sm:grid-cols-3">
                    <div>
                        <InputLabel htmlFor="recurrence_type" value="Recurrence" />
                        <select
                            id="recurrence_type"
                            value={data.recurrence_type}
                            onChange={(event) => setData('recurrence_type', event.target.value)}
                            className={`${inputClass} mt-1 block w-full`}
                        >
                            {(recurrenceTypes.length ? recurrenceTypes : ['none', 'daily', 'weekly', 'monthly']).map((type) => (
                                <option key={type} value={type}>{type}</option>
                            ))}
                        </select>
                        <InputError message={errors.recurrence_type} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="recurrence_interval" value="Every" />
                        <TextInput
                            id="recurrence_interval"
                            type="number"
                            min="1"
                            max="12"
                            value={data.recurrence_interval}
                            onChange={(event) => setData('recurrence_interval', event.target.value)}
                            className="mt-1 block w-full"
                        />
                        <InputError message={errors.recurrence_interval} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="recurrence_ends_at" value="Ends" />
                        <TextInput
                            id="recurrence_ends_at"
                            type="date"
                            value={data.recurrence_ends_at}
                            onChange={(event) => setData('recurrence_ends_at', event.target.value)}
                            className="mt-1 block w-full"
                        />
                        <InputError message={errors.recurrence_ends_at} className="mt-2" />
                    </div>
                </div>
            </div>

            <div className="flex items-center justify-end gap-3 border-t border-slate-200 pt-5">
                <Link
                    href={task ? route('tasks.show', task.id) : route('tasks.index')}
                    className={secondaryButton}
                >
                    Cancel
                </Link>
                <PrimaryButton data-testid="task-submit-button" disabled={processing}>{submitLabel}</PrimaryButton>
            </div>
        </form>
    );
}
