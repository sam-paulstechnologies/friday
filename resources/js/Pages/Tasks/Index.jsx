import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppShell from '@/Layouts/AppShell';
import TaskDetailDrawer from '@/Components/Tasks/TaskDetailDrawer';
import TaskRow from '@/Components/Tasks/TaskRow';
import { useTaskCompletion } from '@/Components/Tasks/TaskList';
import {
    Button,
    EmptyState,
    FilterBar,
    Icon,
    LinkButton,
    Panel,
    SelectField,
    TextField,
    ViewTabs,
    cx,
} from '@/Components/Kit';

function Pagination({ links, meta }) {
    if (!links || links.length <= 3) return null;

    return (
        <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 sm:px-5">
            <p className="text-xs text-ink-subtle">
                Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} tasks
            </p>
            <ul className="flex flex-wrap items-center gap-1">
                {links.map((link, index) => (
                    <li key={`${link.label}-${index}`}>
                        <button
                            type="button"
                            disabled={!link.url}
                            aria-current={link.active ? 'page' : undefined}
                            onClick={() => link.url && router.visit(link.url, { preserveScroll: true, preserveState: true })}
                            className={cx(
                                'min-w-8 rounded-control px-2 py-1 text-xs font-semibold',
                                link.active ? 'bg-brand-600 text-ink-inverse' : 'text-ink-muted hover:bg-surface-sunken',
                                !link.url && 'cursor-not-allowed opacity-40',
                            )}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    </li>
                ))}
            </ul>
        </nav>
    );
}

/**
 * My Tasks — the canonical place to manage work.
 *
 * One server-paginated view at a time. Filters and the selected view live in
 * the URL, so a view is shareable and the back button behaves.
 */
export default function TasksIndex({ tasks, view, views, viewCounts, filters, statuses, priorities, projects, workflowStates }) {
    const [values, setValues] = useState(filters);
    const [openTaskId, setOpenTaskId] = useState(null);
    const toggleCompletion = useTaskCompletion();

    const rows = tasks?.data ?? [];
    const today = new Date().toISOString().slice(0, 10);

    // Filters apply on submit, not on every keystroke: no mount-time refetch
    // and no duplicate request per character.
    const applyFilters = (next = values) => {
        router.get(route('tasks.index'), { ...next, view }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const changeFilter = (key, value) => {
        const next = { ...values, [key]: value };
        setValues(next);
        applyFilters(next);
    };

    const tabs = Object.entries(views ?? {}).map(([key, label]) => ({
        key,
        label,
        count: viewCounts?.[key] ?? 0,
        href: route('tasks.index', { ...values, view: key }),
    }));

    return (
        <AppShell
            title="My Tasks"
            subtitle="Everything assigned to you or reported by you."
            actions={
                <LinkButton href={route('tasks.create')} variant="primary">
                    <Icon name="plus" className="h-4 w-4" />
                    Add task
                </LinkButton>
            }
            tabs={<ViewTabs items={tabs} current={view} />}
        >
            <Head title="My Tasks" />

            <div data-testid="tasks-page">
                <Panel className="overflow-hidden">
                    <FilterBar>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                applyFilters();
                            }}
                            className="relative min-w-0 flex-1 sm:max-w-xs"
                            role="search"
                        >
                            <label htmlFor="task-search" className="sr-only">
                                Search tasks
                            </label>
                            <Icon name="search" className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-subtle" />
                            <TextField
                                id="task-search"
                                type="search"
                                value={values.search ?? ''}
                                onChange={(event) => setValues({ ...values, search: event.target.value })}
                                placeholder="Search tasks"
                                className="pl-8"
                            />
                        </form>

                        <label htmlFor="filter-status" className="sr-only">
                            Filter by status
                        </label>
                        <SelectField id="filter-status" value={values.status ?? ''} onChange={(event) => changeFilter('status', event.target.value)} className="w-auto">
                            <option value="">All statuses</option>
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {status.replaceAll('_', ' ')}
                                </option>
                            ))}
                        </SelectField>

                        <label htmlFor="filter-priority" className="sr-only">
                            Filter by priority
                        </label>
                        <SelectField id="filter-priority" value={values.priority ?? ''} onChange={(event) => changeFilter('priority', event.target.value)} className="w-auto">
                            <option value="">All priorities</option>
                            {priorities.map((priority) => (
                                <option key={priority} value={priority}>
                                    {priority}
                                </option>
                            ))}
                        </SelectField>

                        <label htmlFor="filter-bucket" className="sr-only">
                            Filter by bucket
                        </label>
                        <SelectField id="filter-bucket" value={values.workflow_state ?? ''} onChange={(event) => changeFilter('workflow_state', event.target.value)} className="w-auto">
                            <option value="">Any bucket</option>
                            {(workflowStates ?? []).map((state) => (
                                <option key={state.value} value={state.value}>
                                    {state.label}
                                </option>
                            ))}
                        </SelectField>

                        <label htmlFor="filter-project" className="sr-only">
                            Filter by project
                        </label>
                        <SelectField id="filter-project" value={values.project_id ?? ''} onChange={(event) => changeFilter('project_id', event.target.value)} className="w-auto">
                            <option value="">All projects</option>
                            {projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.name}
                                </option>
                            ))}
                        </SelectField>

                        {(values.search || values.status || values.priority || values.project_id || values.workflow_state) && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                    const cleared = { search: '', status: '', priority: '', project_id: '', workflow_state: '' };
                                    setValues(cleared);
                                    applyFilters(cleared);
                                }}
                            >
                                Clear
                            </Button>
                        )}
                    </FilterBar>

                    {rows.length === 0 ? (
                        <EmptyState
                            icon="check"
                            title="Nothing in this view"
                            description="No task matches this view and these filters. Try another view, clear the filters, or add a task."
                            action={
                                <LinkButton href={route('tasks.create')} size="sm" variant="primary">
                                    Add task
                                </LinkButton>
                            }
                        />
                    ) : (
                        <ul className="divide-y divide-line">
                            {rows.map((task) => (
                                <TaskRow key={task.id} task={task} today={today} onOpen={(item) => setOpenTaskId(item.id)} onToggle={toggleCompletion} />
                            ))}
                        </ul>
                    )}

                    <Pagination links={tasks?.links} meta={{ from: tasks?.from, to: tasks?.to, total: tasks?.total }} />
                </Panel>
            </div>

            <TaskDetailDrawer taskId={openTaskId} open={openTaskId !== null} onClose={() => setOpenTaskId(null)} today={today} />
        </AppShell>
    );
}
