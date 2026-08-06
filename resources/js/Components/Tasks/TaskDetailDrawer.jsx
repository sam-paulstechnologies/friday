import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Badge, Button, Drawer, EmptyState, ErrorState, Icon, LoadingState, cx } from '@/Components/Kit';
import { dueMeta, TaskStatusControl } from './TaskRow';

function Row({ label, children }) {
    if (children === null || children === undefined || children === '') return null;

    return (
        <div className="grid grid-cols-[7.5rem_minmax(0,1fr)] items-start gap-3 py-2">
            <dt className="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{label}</dt>
            <dd className="min-w-0 text-sm text-ink">{children}</dd>
        </div>
    );
}

/** Progressive disclosure: advanced detail stays folded until asked for. */
function Disclosure({ title, count, children, defaultOpen = false }) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <div className="border-t border-line">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                className="flex w-full items-center justify-between px-4 py-3 text-left text-sm font-semibold text-ink hover:bg-surface-sunken sm:px-5"
            >
                <span className="flex items-center gap-2">
                    {title}
                    {count > 0 && <span className="rounded-full bg-surface-sunken px-1.5 text-xs text-ink-muted">{count}</span>}
                </span>
                <Icon name="chevronDown" className={cx('h-4 w-4 text-ink-subtle transition-transform', !open && '-rotate-90')} />
            </button>
            {open && <div className="px-4 pb-4 sm:px-5">{children}</div>}
        </div>
    );
}

/**
 * Task detail.
 *
 * A right-hand drawer on desktop and a full-height sheet on phones, loaded
 * from a policy-guarded JSON endpoint so it cannot show a record the operator
 * is not allowed to see.
 */
export default function TaskDetailDrawer({ taskId, open, onClose, today }) {
    const [state, setState] = useState({ status: 'idle', data: null, error: null });

    useEffect(() => {
        if (!open || !taskId) return;

        let cancelled = false;
        setState({ status: 'loading', data: null, error: null });

        fetch(route('tasks.panel', taskId), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(response.status === 403 ? 'You do not have access to this task.' : 'Miriam could not load this task.');
                }

                return response.json();
            })
            .then((data) => {
                if (!cancelled) setState({ status: 'ready', data, error: null });
            })
            .catch((error) => {
                if (!cancelled) setState({ status: 'error', data: null, error: error.message });
            });

        return () => {
            cancelled = true;
        };
    }, [open, taskId]);

    const task = state.data?.task;
    const canUpdate = state.data?.can?.update ?? false;

    const act = (routeName) => {
        router.patch(route(routeName, taskId), {}, { preserveScroll: true, onSuccess: onClose });
    };

    const completed = task?.status === 'completed';
    const due = task ? dueMeta(task.due_date, today, completed) : null;

    return (
        <Drawer
            open={open}
            onClose={onClose}
            title={task?.title ?? 'Task'}
            description={task ? `${task.workflow_label ?? 'Task'}${task.project?.name ? ` · ${task.project.name}` : ''}` : undefined}
            footer={
                task && (
                    <div className="flex flex-wrap items-center gap-2">
                        {canUpdate && !completed && (
                            <Button variant="primary" onClick={() => act('tasks.complete')}>
                                Mark complete
                            </Button>
                        )}
                        {canUpdate && completed && (
                            <Button variant="primary" onClick={() => act('tasks.restore')}>
                                Reopen
                            </Button>
                        )}
                        {canUpdate && !completed && <Button onClick={() => act('today.tasks.today')}>Today</Button>}
                        {canUpdate && !completed && <Button onClick={() => act('today.tasks.later')}>Later</Button>}
                        {canUpdate && !completed && <Button onClick={() => act('today.tasks.waiting')}>Waiting</Button>}
                        <Link href={route('tasks.show', task.id)} className="ml-auto text-sm font-semibold text-brand-700 hover:underline">
                            Open full page
                        </Link>
                    </div>
                )
            }
        >
            {state.status === 'loading' && <LoadingState label="Loading task" rows={5} />}

            {state.status === 'error' && (
                <ErrorState title="Could not open this task" description={state.error} action={<Button onClick={onClose}>Close</Button>} />
            )}

            {state.status === 'ready' && task && (
                <div>
                    <div className="flex items-start gap-3 px-4 py-4 sm:px-5">
                        <TaskStatusControl
                            task={task}
                            disabled={!canUpdate}
                            onToggle={() => act(completed ? 'tasks.restore' : 'tasks.complete')}
                        />
                        <div className="min-w-0 flex-1">
                            <h3 className={cx('text-base font-bold', completed ? 'text-ink-subtle line-through' : 'text-ink')}>{task.title}</h3>
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {task.workflow_label && <Badge tone={task.workflow_state === 'today' ? 'brand' : 'neutral'}>{task.workflow_label}</Badge>}
                                {due && <Badge tone={due.tone}>{due.label}</Badge>}
                                {task.priority && task.priority !== 'medium' && <Badge tone={task.priority === 'urgent' ? 'urgent' : 'warn'}>{task.priority}</Badge>}
                                {completed && <Badge tone="good">Completed</Badge>}
                            </div>
                        </div>
                    </div>

                    {task.description && (
                        <div className="border-t border-line px-4 py-4 sm:px-5">
                            <p className="whitespace-pre-wrap text-sm leading-6 text-ink-muted">{task.description}</p>
                        </div>
                    )}

                    <div className="border-t border-line px-4 py-2 sm:px-5">
                        <dl className="divide-y divide-line">
                            <Row label="Project">{task.project?.name}</Row>
                            <Row label="Section">{task.section}</Row>
                            <Row label="Assignee">{task.assignee?.name}</Row>
                            <Row label="Due">{task.due_date}</Row>
                            <Row label="Start">{task.start_date}</Row>
                            <Row label="Type">{task.task_type}</Row>
                            <Row label="Recurrence">{task.recurrence_type !== 'none' ? task.recurrence_type : null}</Row>
                            <Row label="Completed">{task.completed_at}</Row>
                        </dl>
                    </div>

                    {/* Capture provenance in plain words, never raw JSON. */}
                    {(task.source || task.source_metadata?.original_text) && (
                        <Disclosure title="Where this came from">
                            <div className="space-y-2 text-sm">
                                {task.source_metadata?.original_text && (
                                    <div className="rounded-control bg-surface-sunken px-3 py-2">
                                        <p className="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Captured wording</p>
                                        <p className="mt-1 whitespace-pre-wrap text-ink-muted">{task.source_metadata.original_text}</p>
                                    </div>
                                )}
                                <p className="text-ink-muted">
                                    Captured via{' '}
                                    <span className="font-semibold text-ink">
                                        {task.source === 'web_quick_capture' ? 'Quick Capture' : task.source === 'slack' ? 'Slack' : (task.source ?? 'the web app')}
                                    </span>
                                    {task.source_metadata?.converted_via ? `, converted from the ${task.source_metadata.converted_via}.` : '.'}
                                </p>
                            </div>
                        </Disclosure>
                    )}

                    <Disclosure title="Subtasks" count={task.subtasks?.length ?? 0}>
                        {task.subtasks?.length ? (
                            <ul className="space-y-1.5">
                                {task.subtasks.map((subtask) => (
                                    <li key={subtask.id} className="flex items-center gap-2 text-sm">
                                        <Icon name="check" className={cx('h-3.5 w-3.5', subtask.status === 'completed' ? 'text-good' : 'text-ink-subtle')} />
                                        <span className={cx('truncate', subtask.status === 'completed' && 'text-ink-subtle line-through')}>{subtask.title}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-ink-subtle">No subtasks.</p>
                        )}
                    </Disclosure>

                    <Disclosure title="Comments" count={task.comments?.length ?? 0}>
                        {task.comments?.length ? (
                            <ul className="space-y-3">
                                {task.comments.map((comment) => (
                                    <li key={comment.id} className="text-sm">
                                        <p className="text-xs font-semibold text-ink">{comment.user?.name ?? 'Someone'}</p>
                                        <p className="mt-0.5 whitespace-pre-wrap text-ink-muted">{comment.body}</p>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <EmptyState icon="list" title="No comments yet" description="Open the full page to add one." />
                        )}
                    </Disclosure>

                    <Disclosure title="Activity" count={task.activities?.length ?? 0}>
                        {task.activities?.length ? (
                            <ol className="space-y-2">
                                {task.activities.map((activity) => (
                                    <li key={activity.id} className="text-sm text-ink-muted">
                                        <span className="text-ink">{activity.description ?? activity.action}</span>
                                        <span className="ml-1 text-xs text-ink-subtle">{activity.created_at}</span>
                                    </li>
                                ))}
                            </ol>
                        ) : (
                            <p className="text-sm text-ink-subtle">No activity recorded.</p>
                        )}
                    </Disclosure>
                </div>
            )}
        </Drawer>
    );
}
