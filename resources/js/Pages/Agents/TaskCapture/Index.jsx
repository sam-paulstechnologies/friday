import { Badge, EmptyState, PageSection, inputClass, primaryButton, secondaryButton } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';

const priorityTone = {
    high: 'bg-rose-50 text-rose-700 ring-rose-100',
    medium: 'bg-amber-50 text-amber-700 ring-amber-100',
    low: 'bg-slate-100 text-slate-700 ring-slate-200',
};

const statusTone = {
    active: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    running: 'bg-cyan-50 text-cyan-700 ring-cyan-100',
    failed: 'bg-rose-50 text-rose-700 ring-rose-100',
};

const categoryTone = {
    coding: 'bg-cyan-50 text-cyan-700 ring-cyan-100',
    client_followup: 'bg-amber-50 text-amber-700 ring-amber-100',
    work_survival: 'bg-violet-50 text-violet-700 ring-violet-100',
    health: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    family: 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-100',
    finance: 'bg-blue-50 text-blue-700 ring-blue-100',
    content: 'bg-orange-50 text-orange-700 ring-orange-100',
    reminder: 'bg-indigo-50 text-indigo-700 ring-indigo-100',
    decision: 'bg-purple-50 text-purple-700 ring-purple-100',
    general_task: 'bg-slate-100 text-slate-700 ring-slate-200',
};

export default function Index({ agent, selectedRun, recentRuns = [] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        input: '',
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('agents.task-capture.run'), {
            preserveScroll: true,
            onSuccess: () => reset('input'),
        });
    };

    return (
        <AuthenticatedLayout
            title={agent.name}
            subtitle="Paste a messy capture and Miriam will turn it into reviewable task proposals with a visible run log."
        >
            <Head title="Task Capture Agent" />

            <div data-testid="task-capture-agent-page" className="grid gap-5 xl:grid-cols-[minmax(0,1.25fr)_420px]">
                <div className="space-y-5">
                    <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/50">
                        <div className="flex flex-col gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge tone="bg-slate-950 text-white ring-slate-950">Rule-based MVP</Badge>
                                    <Badge tone={statusTone[agent.status] ?? statusTone.active}>{agent.status}</Badge>
                                </div>
                                <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">{agent.description}</p>
                            </div>
                        </div>

                        <form onSubmit={submit} className="mt-4 space-y-3">
                            <label htmlFor="task-capture-input" className="text-sm font-semibold text-slate-900">Raw capture</label>
                            <textarea
                                id="task-capture-input"
                                value={data.input}
                                onChange={(event) => setData('input', event.target.value)}
                                rows={8}
                                placeholder="Example: Need to finish SayaraForce manager UI and follow up with Mecline tomorrow"
                                className={`${inputClass} w-full resize-y leading-6`}
                            />
                            {errors.input && <div className="text-sm font-semibold text-rose-600">{errors.input}</div>}
                            <div className="flex flex-wrap items-center gap-2">
                                <button type="submit" disabled={processing} className={primaryButton}>
                                    {processing ? 'Running...' : 'Run Agent'}
                                </button>
                                <span className="text-xs font-medium text-slate-500">No external AI API. No task is created until you review the result.</span>
                            </div>
                        </form>
                    </section>

                    <RunResult run={selectedRun} />
                </div>

                <aside className="space-y-5">
                    <PageSection title="Recent Runs" description="Latest Task Capture Agent activity.">
                        {recentRuns.length === 0 ? (
                            <EmptyState title="No runs yet" description="Paste a capture and run the agent to create the first log trail." />
                        ) : (
                            <div className="divide-y divide-slate-100">
                                {recentRuns.map((run) => (
                                    <Link key={run.id} href={route('agents.task-capture.index', { run: run.id })} className="block px-4 py-3 hover:bg-slate-50">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <div className="truncate text-sm font-semibold text-slate-950">{run.generated_task_title ?? `Run #${run.id}`}</div>
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {(run.categories ?? []).map((category) => <CategoryBadge key={category} category={category} />)}
                                                </div>
                                            </div>
                                            <Badge tone={statusTone[run.status] ?? statusTone.running}>{run.status}</Badge>
                                        </div>
                                        <div className="mt-2 flex flex-wrap gap-2 text-xs font-medium text-slate-500">
                                            {run.priority && <span>{run.priority} priority</span>}
                                            {run.due_label && <span>{run.due_label.replaceAll('_', ' ')}</span>}
                                            {run.created_at && <span>{run.created_at}</span>}
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </PageSection>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}

function RunResult({ run }) {
    if (!run) {
        return (
            <PageSection title="Agent Result" description="The classification, task proposals, and logs appear here after a run.">
                <EmptyState title="Ready for capture" description="Messy input is fine. The current agent uses deterministic rules so the result is transparent and easy to review." />
            </PageSection>
        );
    }

    return (
        <div className="space-y-5" data-testid="agent-run-result">
            <PageSection
                title={`Run #${run.id}`}
                description="Structured classification and task proposals."
                action={<Badge tone={statusTone[run.status] ?? statusTone.running}>{run.status}</Badge>}
            >
                <div className="space-y-4 p-4">
                    <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Original input</div>
                        <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-800">{run.original_input}</p>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Metric label="Categories">
                            <div className="flex flex-wrap gap-1">
                                {(run.result?.categories ?? []).map((category) => <CategoryBadge key={category} category={category} />)}
                            </div>
                        </Metric>
                        <Metric label="Project / Client">
                            {(run.result?.detected_projects ?? []).length > 0 ? run.result.detected_projects.join(', ') : 'None detected'}
                        </Metric>
                        <Metric label="Priority">
                            <Badge tone={priorityTone[run.result?.priority] ?? priorityTone.low}>{run.result?.priority ?? 'low'}</Badge>
                        </Metric>
                        <Metric label="Due">
                            {run.result?.due_label?.replaceAll('_', ' ') ?? 'no due date'}
                        </Metric>
                    </div>

                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <div className="text-xs font-semibold uppercase tracking-wide text-amber-700">Suggested next action</div>
                        <p className="mt-2 text-sm font-semibold leading-6 text-slate-950">{run.result?.suggested_next_action ?? 'Review the generated task proposal.'}</p>
                    </div>

                    <div className="grid gap-3 lg:grid-cols-2">
                        {(run.outputs ?? []).map((output) => <OutputCard key={output.id} output={output} />)}
                    </div>

                    {run.error_message && (
                        <div className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
                            {run.error_message}
                        </div>
                    )}
                </div>
            </PageSection>

            <PageSection title="Run Logs" description="Visible audit trail for this agent run.">
                <div className="divide-y divide-slate-100">
                    {(run.logs ?? []).length === 0 ? (
                        <EmptyState title="No logs recorded" />
                    ) : (
                        run.logs.map((log) => (
                            <div key={log.id} className="grid gap-2 px-4 py-3 text-sm md:grid-cols-[120px_minmax(0,1fr)_180px]">
                                <Badge tone={log.level === 'error' ? statusTone.failed : 'bg-slate-100 text-slate-700 ring-slate-200'}>{log.level}</Badge>
                                <div className="font-medium text-slate-800">{log.message}</div>
                                <div className="text-xs font-medium text-slate-500 md:text-right">{log.occurred_at}</div>
                            </div>
                        ))
                    )}
                </div>
            </PageSection>
        </div>
    );
}

function Metric({ label, children }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-3">
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-2 text-sm font-semibold text-slate-950">{children}</div>
        </div>
    );
}

function OutputCard({ output }) {
    // "Review as task" used to open an empty form and discard everything the
    // agent had just parsed. It now sends the proposal - with the original
    // wording - through the same capture pipeline as Slack and Quick Capture.
    const sendToInbox = () => {
        router.post(route('agents.task-capture.capture', output.id), {}, { preserveScroll: true });
    };

    return (
        <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/40">
            <div className="flex flex-wrap items-center gap-2">
                <CategoryBadge category={output.category} />
                <Badge tone={priorityTone[output.priority] ?? priorityTone.low}>{output.priority}</Badge>
                <Badge>{output.due_label.replaceAll('_', ' ')}</Badge>
            </div>
            <h3 className="mt-3 text-base font-semibold leading-6 text-slate-950">{output.generated_task_title}</h3>
            <p className="mt-2 text-sm leading-6 text-slate-600">{output.suggested_next_action}</p>
            {(output.detected_projects ?? []).length > 0 && (
                <div className="mt-3 text-xs font-semibold text-slate-500">Detected: {output.detected_projects.join(', ')}</div>
            )}
            <div className="mt-4 flex flex-wrap gap-2">
                <button type="button" onClick={sendToInbox} className={primaryButton}>
                    Send to Inbox to review
                </button>
            </div>
            <p className="mt-2 text-xs text-slate-500">
                Keeps your original wording and this proposal, so you never retype it.
            </p>
        </article>
    );
}

function CategoryBadge({ category }) {
    return <Badge tone={categoryTone[category] ?? categoryTone.general_task}>{category.replaceAll('_', ' ')}</Badge>;
}
