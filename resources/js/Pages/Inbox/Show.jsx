import { Head, Link, useForm } from '@inertiajs/react';
import AppShell from '@/Layouts/AppShell';
import { Alert, Badge, Button, Field, LinkButton, Panel, PanelHeader, SelectField, TextArea, TextField } from '@/Components/Kit';

const STATE_TONE = {
    unprocessed: 'warn',
    clarification_needed: 'info',
    converted: 'good',
    dismissed: 'neutral',
};

/**
 * Capture review.
 *
 * Every field arrives pre-filled from what Miriam read, so the operator
 * corrects rather than retypes. The original wording is shown above the form
 * and is never editable here.
 */
export default function InboxShow({ item, destinations }) {
    const { data, setData, post, processing, errors } = useForm({
        title: item.title ?? '',
        description: item.details ?? '',
        due_date: item.proposed?.due_date ?? '',
        priority: item.proposed?.priority ?? 'medium',
        task_type: item.proposed?.task_type ?? 'task',
        project_id: item.proposed?.project_id ?? '',
        destination: 'today',
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('inbox.convert', [item.source, item.id]));
    };

    const uncertain = item.proposed?.confidence != null && item.proposed.confidence < 0.75;

    return (
        <AppShell
            title="Review capture"
            subtitle="Correct anything Miriam misread, then decide where it goes."
            breadcrumbs={[{ label: 'Inbox', href: route('inbox.index') }, { label: 'Review' }]}
            actions={<LinkButton href={route('inbox.index')}>Back to Inbox</LinkButton>}
        >
            <Head title="Review capture" />

            <div data-testid="inbox-show" className="mx-auto max-w-3xl space-y-4">
                <Panel>
                    <PanelHeader
                        title="What you actually said"
                        description={`${item.capture_source} · ${item.captured_at_local ?? ''}`}
                        action={<Badge tone={STATE_TONE[item.state] ?? 'neutral'}>{item.state_label}</Badge>}
                    />
                    <div className="px-4 py-4 sm:px-5">
                        <blockquote className="whitespace-pre-wrap rounded-control bg-surface-sunken px-3 py-2.5 text-sm leading-6 text-ink-muted">
                            {item.original_text}
                        </blockquote>
                        <p className="mt-2 text-xs text-ink-subtle">Kept on the record whatever you do next.</p>
                    </div>
                </Panel>

                {uncertain && (
                    <Alert tone="info" title="Miriam was not confident about this one">
                        Check the details below before converting.
                    </Alert>
                )}

                {item.task && (
                    <Alert tone="good" title="Already converted">
                        This capture became{' '}
                        <Link href={item.task.url} className="font-semibold underline">
                            {item.task.title}
                        </Link>
                        . Converting again reopens that same task rather than creating a second one.
                    </Alert>
                )}

                <Panel>
                    <PanelHeader title="What Miriam proposed" description="Everything here is editable." />
                    <form onSubmit={submit} className="space-y-4 px-4 py-4 sm:px-5">
                        <Field label="Title" htmlFor="title" error={errors.title} required>
                            <TextField id="title" value={data.title} error={errors.title} onChange={(event) => setData('title', event.target.value)} />
                        </Field>

                        <Field label="Details" htmlFor="description" error={errors.description}>
                            <TextArea id="description" rows={4} value={data.description} onChange={(event) => setData('description', event.target.value)} />
                        </Field>

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label="Due date" htmlFor="due_date" error={errors.due_date}>
                                <TextField id="due_date" type="date" value={data.due_date ?? ''} error={errors.due_date} onChange={(event) => setData('due_date', event.target.value)} />
                            </Field>
                            <Field label="Priority" htmlFor="priority" error={errors.priority}>
                                <SelectField id="priority" value={data.priority} onChange={(event) => setData('priority', event.target.value)}>
                                    {(item.priorities ?? []).map((value) => (
                                        <option key={value} value={value}>
                                            {value}
                                        </option>
                                    ))}
                                </SelectField>
                            </Field>
                            <Field label="Type" htmlFor="task_type" error={errors.task_type}>
                                <SelectField id="task_type" value={data.task_type} onChange={(event) => setData('task_type', event.target.value)}>
                                    {(item.task_types ?? []).map((value) => (
                                        <option key={value} value={value}>
                                            {value.replaceAll('_', ' ')}
                                        </option>
                                    ))}
                                </SelectField>
                            </Field>
                            <Field
                                label="Project"
                                htmlFor="project_id"
                                error={errors.project_id}
                                hint={
                                    item.proposed?.project_name && !item.proposed?.project_id
                                        ? `Read "${item.proposed.project_name}" but found no match`
                                        : undefined
                                }
                            >
                                <SelectField id="project_id" value={data.project_id ?? ''} onChange={(event) => setData('project_id', event.target.value)}>
                                    <option value="">No project</option>
                                    {(item.projects ?? []).map((project) => (
                                        <option key={project.id} value={project.id}>
                                            {project.name}
                                        </option>
                                    ))}
                                </SelectField>
                            </Field>
                        </div>

                        <Field label="Where should it go?" htmlFor="destination" error={errors.destination}>
                            <SelectField id="destination" value={data.destination} onChange={(event) => setData('destination', event.target.value)} className="sm:max-w-xs">
                                {Object.entries(destinations ?? {}).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </SelectField>
                        </Field>

                        <div className="flex flex-wrap gap-2 border-t border-line pt-4">
                            <Button type="submit" variant="primary" disabled={processing}>
                                {processing ? 'Converting…' : 'Convert to task'}
                            </Button>
                            <LinkButton href={route('inbox.index')}>Cancel</LinkButton>
                            {item.can_dismiss && (
                                <Button
                                    variant="ghost"
                                    disabled={processing}
                                    onClick={() => post(route('inbox.dismiss', [item.source, item.id]))}
                                    className="ml-auto"
                                >
                                    Dismiss
                                </Button>
                            )}
                        </div>
                    </form>
                </Panel>
            </div>
        </AppShell>
    );
}
