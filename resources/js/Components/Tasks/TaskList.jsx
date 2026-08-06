import { router } from '@inertiajs/react';
import { EmptyState, Panel, PanelHeader } from '@/Components/Kit';
import TaskRow from './TaskRow';

/**
 * A titled group of task rows.
 *
 * Rows are deduplicated by id here so the same task can never be rendered
 * twice inside one section.
 */
export function TaskSection({ title, description, tasks = [], today, onOpen, onToggle, action, emptyTitle, emptyDescription, emptyAction, dense }) {
    const seen = new Set();
    const unique = tasks.filter((task) => {
        if (seen.has(task.id)) return false;
        seen.add(task.id);

        return true;
    });

    return (
        <Panel>
            <PanelHeader title={title} description={description} count={unique.length} action={action} />
            {unique.length === 0 ? (
                <EmptyState icon="check" title={emptyTitle ?? 'Nothing here'} description={emptyDescription} action={emptyAction} />
            ) : (
                <ul className="divide-y divide-line">
                    {unique.map((task) => (
                        <TaskRow key={task.id} task={task} today={today} onOpen={onOpen} onToggle={onToggle} dense={dense} />
                    ))}
                </ul>
            )}
        </Panel>
    );
}

/** Toggle completion through the canonical endpoints, never by local state. */
export function useTaskCompletion() {
    return (task, completed) => {
        const routeName = completed ? 'tasks.restore' : 'tasks.complete';

        router.patch(route(routeName, task.id), {}, { preserveScroll: true });
    };
}

export default TaskSection;
