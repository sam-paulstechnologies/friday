import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { priorityLabels, statusLabels } from './Partials/TaskForm';
import { Avatar, Badge, DueDate, EmptyState, MetadataItem, Panel, inputClass, primaryButton, secondaryButton, priorityTone, statusTone } from '@/Components/Ui';

function DetailCard({ label, value }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60">
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-2 text-sm font-semibold text-slate-950">{value}</div>
        </div>
    );
}

function CommentThread({ task }) {
    const [editingId, setEditingId] = useState(null);
    const { data, setData, post, patch, delete: destroy, processing, reset, errors } = useForm({
        body: '',
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('tasks.comments.store', task.id), {
            preserveScroll: true,
            onSuccess: () => reset('body'),
        });
    };

    const startEdit = (comment) => {
        setEditingId(comment.id);
        setData('body', comment.body);
    };

    const saveEdit = (comment) => {
        patch(route('task-comments.update', comment.id), {
            preserveScroll: true,
            onSuccess: () => {
                setEditingId(null);
                reset('body');
            },
        });
    };

    return (
        <Panel>
            <div className="border-b border-slate-200 p-5">
                <h3 className="text-base font-semibold text-slate-950">Comments</h3>
                <p className="mt-1 text-sm text-slate-500">Discuss task details and decisions.</p>
            </div>

            <form onSubmit={submit} className="border-b border-slate-200 p-5">
                <textarea
                    value={editingId ? '' : data.body}
                    onChange={(event) => setData('body', event.target.value)}
                    disabled={Boolean(editingId)}
                    rows="3"
                    placeholder="Add a comment..."
                    className={`${inputClass} block w-full`}
                />
                {errors.body && <div className="mt-2 text-sm text-rose-600">{errors.body}</div>}
                <div className="mt-3 flex justify-end">
                    <button
                        type="submit"
                        disabled={processing || Boolean(editingId)}
                        className={primaryButton}
                    >
                        Add Comment
                    </button>
                </div>
            </form>

            <div className="divide-y divide-slate-100">
                {task.comments.length === 0 ? (
                    <EmptyState title="No comments yet" description="Start the thread with an update, question, or decision note." />
                ) : (
                    task.comments.map((comment) => (
                        <div key={comment.id} className="p-5">
                            <div className="flex items-start gap-3">
                                <Avatar name={comment.user?.name ?? 'Unknown user'} />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-semibold text-slate-950">
                                            {comment.user?.name ?? 'Unknown user'}
                                        </span>
                                        <span className="text-xs text-slate-500">{comment.created_at}</span>
                                    </div>

                                    {editingId === comment.id ? (
                                        <div className="mt-3 space-y-3">
                                            <textarea
                                                value={data.body}
                                                onChange={(event) => setData('body', event.target.value)}
                                                rows="3"
                                                className={`${inputClass} block w-full`}
                                            />
                                            <div className="flex gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => saveEdit(comment)}
                                                    className={primaryButton}
                                                >
                                                    Save
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setEditingId(null);
                                                        reset('body');
                                                    }}
                                                    className={secondaryButton}
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    ) : (
                                        <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700">
                                            {comment.body}
                                        </p>
                                    )}
                                </div>

                                {comment.can_manage && editingId !== comment.id && (
                                    <div className="flex gap-2">
                                        <button
                                            type="button"
                                            onClick={() => startEdit(comment)}
                                            className="text-xs font-semibold text-slate-500 hover:text-slate-950"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => destroy(route('task-comments.destroy', comment.id), { preserveScroll: true })}
                                            className="text-xs font-semibold text-rose-600 hover:text-rose-700"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                )}
                            </div>
                        </div>
                    ))
                )}
            </div>
        </Panel>
    );
}

function ActivityTimeline({ activities }) {
    return (
        <Panel>
            <div className="border-b border-slate-200 p-5">
                <h3 className="text-base font-semibold text-slate-950">Activity</h3>
                <p className="mt-1 text-sm text-slate-500">Recent task changes.</p>
            </div>

            <div className="divide-y divide-slate-100">
                {activities.length === 0 ? (
                    <EmptyState title="No activity yet" description="Status, priority, assignee, and due-date changes will appear here." />
                ) : (
                    activities.map((activity) => (
                        <div key={activity.id} className="p-4">
                            <div className="flex items-start gap-3">
                                <span className="mt-1 h-2 w-2 rounded-full bg-slate-400" />
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-semibold text-slate-950">
                                            {activity.action.replaceAll('_', ' ')}
                                        </span>
                                        <span className="text-xs text-slate-500">{activity.created_at}</span>
                                    </div>
                                    {activity.description && (
                                        <p className="mt-1 text-sm text-slate-600">{activity.description}</p>
                                    )}
                                    {(activity.old_value || activity.new_value) && (
                                        <p className="mt-1 text-xs text-slate-500">
                                            {activity.old_value ?? 'empty'} {'->'} {activity.new_value ?? 'empty'}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </div>
                    ))
                )}
            </div>
        </Panel>
    );
}

function formatBytes(size) {
    if (!size) {
        return 'Unknown size';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let value = size;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(value >= 10 || unit === 0 ? 0 : 1)} ${units[unit]}`;
}

function AttachmentsSection({ task }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        file: null,
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('tasks.attachments.store', task.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => reset('file'),
        });
    };

    return (
        <Panel>
            <div className="border-b border-slate-200 p-5">
                <h3 className="text-base font-semibold text-slate-950">Attachments</h3>
                <p className="mt-1 text-sm text-slate-500">Upload supporting files for this task.</p>
            </div>

            <form onSubmit={submit} className="border-b border-slate-200 p-5">
                <div className="flex flex-col gap-3 md:flex-row md:items-center">
                    <input
                        type="file"
                        onChange={(event) => setData('file', event.target.files[0])}
                        className="block w-full rounded-xl border border-slate-200 bg-white text-sm text-slate-600 shadow-sm shadow-slate-200/60 file:mr-4 file:border-0 file:bg-slate-950 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white focus:border-emerald-500 focus:ring-emerald-500/30"
                    />
                    <button
                        type="submit"
                        disabled={processing || !data.file}
                        className={primaryButton}
                    >
                        Upload
                    </button>
                </div>
                {errors.file && <div className="mt-2 text-sm text-rose-600">{errors.file}</div>}
                <p className="mt-2 text-xs text-slate-500">
                    Allowed: PDF, Office files, images, TXT, CSV. Max 10MB.
                </p>
            </form>

            <div className="divide-y divide-slate-100">
                {task.attachments.length === 0 ? (
                    <EmptyState title="No attachments yet" description="Attach briefs, screenshots, contracts, or working files." />
                ) : (
                    task.attachments.map((attachment) => (
                        <div key={attachment.id} className="flex flex-col gap-3 p-5 lg:flex-row lg:items-center lg:justify-between">
                            <div className="min-w-0">
                                <div className="truncate text-sm font-semibold text-slate-950">
                                    {attachment.original_name}
                                </div>
                                <div className="mt-1 flex flex-wrap gap-2 text-xs text-slate-500">
                                    <span>{formatBytes(attachment.size)}</span>
                                    <span>Uploaded by {attachment.user?.name ?? 'Unknown user'}</span>
                                    <span>{attachment.created_at}</span>
                                </div>
                            </div>
                            <div className="flex gap-2">
                                <a
                                    href={attachment.download_url}
                                    className={secondaryButton}
                                >
                                    Download
                                </a>
                                {attachment.can_delete && (
                                    <button
                                        type="button"
                                        onClick={() => router.delete(route('task-attachments.destroy', attachment.id), { preserveScroll: true })}
                                        className="inline-flex items-center justify-center rounded-xl bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 ring-1 ring-rose-100 transition hover:bg-rose-100"
                                    >
                                        Delete
                                    </button>
                                )}
                            </div>
                        </div>
                    ))
                )}
            </div>
        </Panel>
    );
}

function CustomFieldsSection({ task, customFields }) {
    const initialValues = Object.fromEntries(customFields.map((field) => [field.id, field.value ?? '']));
    const { data, setData, patch, processing, errors } = useForm({
        values: initialValues,
    });

    const submit = (event) => {
        event.preventDefault();
        patch(route('tasks.custom-fields.update', task.id), { preserveScroll: true });
    };

    return (
        <Panel>
            <div className="border-b border-slate-200 p-5">
                <h3 className="text-base font-semibold text-slate-950">Custom Fields</h3>
                <p className="mt-1 text-sm text-slate-500">Workspace-specific task metadata.</p>
            </div>

            {customFields.length === 0 ? (
                <EmptyState title="No custom fields" description="Workspace-specific task metadata will appear here once configured." />
            ) : (
                <form onSubmit={submit} className="space-y-4 p-5">
                    <div className="grid gap-4 md:grid-cols-2">
                        {customFields.map((field) => (
                            <label key={field.id} className="block">
                                <span className="text-sm font-semibold text-slate-700">{field.name}</span>
                                {field.field_type === 'select' ? (
                                    <select
                                        value={data.values[field.id] ?? ''}
                                        onChange={(event) => setData('values', { ...data.values, [field.id]: event.target.value })}
                                        className={`${inputClass} mt-2 block w-full`}
                                    >
                                        <option value="">Not set</option>
                                        {field.options.map((option) => (
                                            <option key={option} value={option}>{option}</option>
                                        ))}
                                    </select>
                                ) : field.field_type === 'boolean' ? (
                                    <select
                                        value={data.values[field.id] ?? ''}
                                        onChange={(event) => setData('values', { ...data.values, [field.id]: event.target.value })}
                                        className={`${inputClass} mt-2 block w-full`}
                                    >
                                        <option value="">Not set</option>
                                        <option value="1">Yes</option>
                                        <option value="0">No</option>
                                    </select>
                                ) : (
                                    <input
                                        type={field.field_type === 'date' ? 'date' : field.field_type === 'number' ? 'number' : 'text'}
                                        value={data.values[field.id] ?? ''}
                                        onChange={(event) => setData('values', { ...data.values, [field.id]: event.target.value })}
                                        className={`${inputClass} mt-2 block w-full`}
                                    />
                                )}
                                {errors[`values.${field.id}`] && (
                                    <span className="mt-1 block text-sm text-rose-600">{errors[`values.${field.id}`]}</span>
                                )}
                            </label>
                        ))}
                    </div>
                    <div className="flex justify-end">
                        <button
                            type="submit"
                            disabled={processing}
                            className={primaryButton}
                        >
                            Save Custom Fields
                        </button>
                    </div>
                </form>
            )}
        </Panel>
    );
}

export default function Show({ task, customFields = [] }) {
    const complete = () => router.patch(route('tasks.complete', task.id));
    const archive = () => router.patch(route('tasks.archive', task.id));

    return (
        <AuthenticatedLayout title={task.title} subtitle={task.project?.name ?? task.workspace?.name}>
            <Head title={task.title} />

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                <main className="space-y-5">
                    <Panel className="overflow-hidden">
                        <div className="bg-[radial-gradient(circle_at_top_left,_rgba(139,92,246,0.12),_transparent_30%),linear-gradient(135deg,_#ffffff,_#f8fafc_58%,_#f5f3ff)] p-6 sm:p-8">
                            <div className="flex flex-wrap gap-2">
                                <Badge tone={statusTone[task.status]}>{statusLabels[task.status]}</Badge>
                                <Badge tone={priorityTone[task.priority]}>{priorityLabels[task.priority]}</Badge>
                                {task.task_type && <Badge>{task.task_type.replace('_', ' ')}</Badge>}
                                {task.area && <Badge>{task.area.name}</Badge>}
                                {task.portfolio && <Badge>{task.portfolio.name}</Badge>}
                            </div>
                            <h2 className="mt-4 text-3xl font-bold tracking-tight text-slate-950">{task.title}</h2>
                            <p className="mt-4 max-w-3xl whitespace-pre-wrap text-sm leading-7 text-slate-600">
                                {task.description || 'No description has been added yet. Use Edit to add the task brief, outcome, and decision context.'}
                            </p>
                        </div>
                    </Panel>

                    <Panel className="p-5">
                        <div className="flex items-start gap-3">
                            <span className="mt-1 flex h-8 w-8 items-center justify-center rounded-xl border border-dashed border-slate-300 text-xs font-bold text-slate-400">S</span>
                            <div>
                                <h3 className="text-base font-bold text-slate-950">Subtasks</h3>
                                <p className="mt-1 text-sm leading-6 text-slate-500">
                                    Nested task support exists in the schema. A full subtask workflow is reserved for a later phase.
                                </p>
                            </div>
                        </div>
                    </Panel>

                    <CommentThread task={task} />
                    <AttachmentsSection task={task} />
                    <ActivityTimeline activities={task.activities} />
                </main>

                <aside className="space-y-4 xl:sticky xl:top-24 xl:self-start">
                    <Panel className="p-4">
                        <div className="flex flex-col gap-2">
                            <Link href={route('tasks.edit', task.id)} className={secondaryButton}>Edit Task</Link>
                            {task.status !== 'completed' && task.status !== 'archived' && (
                                <button type="button" onClick={complete} className={primaryButton}>Mark Complete</button>
                            )}
                            {task.status !== 'archived' && (
                                <button type="button" onClick={archive} className="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">Archive</button>
                            )}
                        </div>
                    </Panel>

                    <Panel className="p-4">
                        <h3 className="text-sm font-bold text-slate-950">Details</h3>
                        <div className="mt-4 space-y-3">
                            <MetadataItem label="Status"><Badge tone={statusTone[task.status]}>{statusLabels[task.status]}</Badge></MetadataItem>
                            <MetadataItem label="Priority"><Badge tone={priorityTone[task.priority]}>{priorityLabels[task.priority]}</Badge></MetadataItem>
                            <MetadataItem label="Assignee">
                                <div className="flex items-center gap-2"><Avatar name={task.assignee?.name ?? 'Unassigned'} size="sm" /> {task.assignee?.name ?? 'Unassigned'}</div>
                            </MetadataItem>
                            <MetadataItem label="Reporter" value={task.reporter?.name ?? 'Unknown'} />
                            <MetadataItem label="Project" value={task.project?.name ?? 'No project'} />
                            <MetadataItem label="Area" value={task.area?.name ?? 'No area'} />
                            <MetadataItem label="Portfolio" value={task.portfolio?.name ?? 'No portfolio'} />
                            <MetadataItem label="Task type" value={task.task_type ? task.task_type.replace('_', ' ') : 'Task'} />
                            <MetadataItem label="Start date" value={task.start_date ?? 'Not set'} />
                            <MetadataItem label="Due date"><DueDate date={task.due_date} status={task.status} /></MetadataItem>
                            <MetadataItem label="Section" value={task.section ?? 'No section'} />
                        </div>
                    </Panel>

                    <CustomFieldsSection task={task} customFields={customFields} />
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
