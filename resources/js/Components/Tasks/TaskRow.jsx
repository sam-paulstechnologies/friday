import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Icon, OverflowMenu, cx } from '@/Components/Kit';

const PRIORITY = {
    urgent: { tone: 'urgent', label: 'Urgent' },
    high: { tone: 'warn', label: 'High' },
    medium: { tone: 'neutral', label: 'Medium' },
    low: { tone: 'neutral', label: 'Low' },
};

/**
 * Due-date wording. Status is carried by text as well as colour, so it is
 * never communicated by colour alone.
 */
export function dueMeta(dueDate, today, completed) {
    if (!dueDate) return null;
    if (completed) return { label: dueDate, tone: 'neutral' };

    if (dueDate < today) return { label: `Overdue · ${dueDate}`, tone: 'urgent' };
    if (dueDate === today) return { label: 'Due today', tone: 'warn' };

    return { label: `Due ${dueDate}`, tone: 'neutral' };
}

export function TaskStatusControl({ task, onToggle, disabled }) {
    const completed = task.status === 'completed';

    return (
        <button
            type="button"
            role="checkbox"
            aria-checked={completed}
            aria-label={completed ? `Reopen ${task.title}` : `Complete ${task.title}`}
            disabled={disabled}
            onClick={() => onToggle?.(task, completed)}
            className={cx(
                'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border transition-colors',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 focus-visible:ring-offset-2',
                completed
                    ? 'border-good bg-good text-ink-inverse'
                    : 'border-line-strong text-transparent hover:border-good hover:text-good',
                disabled && 'cursor-not-allowed opacity-50',
            )}
        >
            <Icon name="check" className="h-3 w-3" strokeWidth={3} />
        </button>
    );
}

/**
 * One scannable task line.
 *
 * Desktop shows metadata inline; below `lg` the same metadata wraps under the
 * title rather than forcing a table layout sideways.
 */
export default function TaskRow({ task, today, onOpen, onToggle, showProject = true, dense = false }) {
    const [busy, setBusy] = useState(false);
    const completed = task.status === 'completed';
    const due = dueMeta(task.due_date, today, completed);
    const priority = PRIORITY[task.priority] ?? PRIORITY.medium;

    const transition = (name) => {
        setBusy(true);
        router.patch(route(name, task.id), {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    return (
        <li
            className={cx(
                'group relative flex items-start gap-3 px-4 py-2.5 transition-colors hover:bg-surface-sunken sm:px-5',
                dense && 'py-2',
            )}
        >
            <div className="pt-0.5">
                <TaskStatusControl task={task} onToggle={onToggle} disabled={busy} />
            </div>

            <div className="min-w-0 flex-1">
                <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                    <button
                        type="button"
                        onClick={() => onOpen?.(task)}
                        className={cx(
                            'min-w-0 truncate rounded text-left text-sm font-semibold',
                            completed ? 'text-ink-subtle line-through' : 'text-ink hover:text-brand-700',
                        )}
                    >
                        {task.title}
                    </button>

                    {task.workflow_label && task.workflow_state !== 'tasks' && (
                        <Badge tone={task.workflow_state === 'today' ? 'brand' : 'neutral'}>{task.workflow_label}</Badge>
                    )}
                </div>

                <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-subtle">
                    {due && (
                        <span
                            className={cx(
                                'inline-flex items-center gap-1 font-semibold',
                                due.tone === 'urgent' && 'text-urgent-ink',
                                due.tone === 'warn' && 'text-warn-ink',
                            )}
                        >
                            <Icon name="calendar" className="h-3 w-3" />
                            {due.label}
                        </span>
                    )}
                    {task.priority && task.priority !== 'medium' && (
                        <span className="inline-flex items-center gap-1">
                            <Icon name="flag" className="h-3 w-3" />
                            {priority.label}
                        </span>
                    )}
                    {showProject && task.project?.name && (
                        <span className="inline-flex min-w-0 items-center gap-1">
                            <Icon name="folder" className="h-3 w-3" />
                            <span className="truncate">{task.project.name}</span>
                        </span>
                    )}
                    {task.section && (
                        <span className="hidden min-w-0 items-center gap-1 sm:inline-flex">
                            <Icon name="list" className="h-3 w-3" />
                            <span className="truncate">{task.section}</span>
                        </span>
                    )}
                    {task.assignee?.name && (
                        <span className="hidden items-center gap-1 md:inline-flex">
                            <Icon name="user" className="h-3 w-3" />
                            {task.assignee.name}
                        </span>
                    )}
                    {task.source === 'web_quick_capture' && <Badge tone="neutral">Captured</Badge>}
                </div>
            </div>

            <div className="flex shrink-0 items-center gap-1">
                <Link
                    href={route('tasks.show', task.id)}
                    aria-label={`Open ${task.title} in full`}
                    className="hidden h-8 w-8 items-center justify-center rounded-control text-ink-subtle opacity-0 transition-opacity hover:bg-surface hover:text-ink focus-visible:opacity-100 group-hover:opacity-100 lg:inline-flex"
                >
                    <Icon name="external" className="h-4 w-4" />
                </Link>
                <OverflowMenu
                    label={`Actions for ${task.title}`}
                    items={[
                        { label: 'Open details', icon: 'external', onClick: () => onOpen?.(task) },
                        { label: 'Open full page', icon: 'arrowRight', href: route('tasks.show', task.id) },
                        { separator: true },
                        !completed && { label: 'Move to Today', icon: 'today', onClick: () => transition('today.tasks.today') },
                        !completed && { label: 'Move to Later', icon: 'clock', onClick: () => transition('today.tasks.later') },
                        !completed && { label: 'Mark Waiting', icon: 'send', onClick: () => transition('today.tasks.waiting') },
                        completed && { label: 'Reopen', icon: 'refresh', onClick: () => transition('tasks.restore') },
                    ]}
                />
            </div>
        </li>
    );
}
