import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge, Panel, inputClass, primaryButton, secondaryButton } from '@/Components/Ui';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const statusTone = {
    pending: 'bg-slate-100 text-slate-700 ring-slate-200',
    running: 'bg-sky-50 text-sky-700 ring-sky-100',
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    needs_review: 'bg-amber-50 text-amber-700 ring-amber-100',
    approved: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    rejected: 'bg-rose-50 text-rose-700 ring-rose-100',
    failed: 'bg-red-50 text-red-700 ring-red-100',
};

const priorityTone = {
    high: 'bg-red-50 text-red-700 ring-red-100',
    medium: 'bg-amber-50 text-amber-700 ring-amber-100',
    low: 'bg-slate-100 text-slate-700 ring-slate-200',
};

const defaultIdea = 'Build a WhatsApp sales agent for garages that finds garage numbers, sends templates, follows up, and books demos.';

function OutputCard({ output }) {
    const [copied, setCopied] = useState(null);
    const prompts = [
        ['Codex prompt', output.payload?.codex_prompt],
        ['Claude Code prompt', output.payload?.claude_code_prompt],
        ['Claude UI/review prompt', output.payload?.claude_ui_review_prompt],
    ].filter(([, value]) => value);

    const review = (routeName) => {
        router.post(route(routeName, output.id), {}, { preserveScroll: true });
    };

    const copy = async (label, value) => {
        await navigator.clipboard.writeText(value);
        setCopied(label);
    };

    return (
        <Panel className="p-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={statusTone[output.status] ?? statusTone.pending}>{output.status.replaceAll('_', ' ')}</Badge>
                        <Badge tone={priorityTone[output.priority] ?? priorityTone.medium}>{output.priority}</Badge>
                        <Badge>{output.agent_name}</Badge>
                    </div>
                    <h3 className="mt-3 text-xl font-semibold text-slate-950">{output.title}</h3>
                    <p className="mt-1 text-sm leading-6 text-slate-600">{output.suggested_next_action}</p>
                </div>

                <div className="flex shrink-0 flex-wrap gap-2">
                    <button type="button" onClick={() => review('agents.outputs.approve')} className={secondaryButton} disabled={output.status === 'approved'}>
                        Approve
                    </button>
                    <button type="button" onClick={() => review('agents.outputs.reject')} className={secondaryButton} disabled={output.status === 'rejected'}>
                        Reject
                    </button>
                    <button type="button" onClick={() => review('agents.outputs.send-to-today')} className={secondaryButton}>
                        Send to Today
                    </button>
                </div>
            </div>

            {prompts.length > 0 && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {prompts.map(([label, value]) => (
                        <button key={label} type="button" onClick={() => copy(label, value)} className={secondaryButton}>
                            {copied === label ? 'Copied' : `Copy ${label}`}
                        </button>
                    ))}
                </div>
            )}

            {output.markdown && (
                <pre className="mt-4 max-h-[520px] overflow-auto whitespace-pre-wrap rounded-md border border-slate-200 bg-slate-950 p-4 text-sm leading-6 text-slate-100">
                    {output.markdown}
                </pre>
            )}
        </Panel>
    );
}

function RunSummary({ selectedRun }) {
    if (!selectedRun) {
        return (
            <Panel className="p-6">
                <h2 className="text-lg font-semibold text-slate-950">No Agent OS run yet</h2>
                <p className="mt-2 text-sm leading-6 text-slate-600">
                    Enter a rough idea, run the pipeline, then review the generated output cards and logs here.
                </p>
            </Panel>
        );
    }

    return (
        <Panel className="p-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <Badge tone={statusTone[selectedRun.status] ?? statusTone.pending}>{selectedRun.status.replaceAll('_', ' ')}</Badge>
                    <h2 className="mt-3 text-lg font-semibold text-slate-950">{selectedRun.result?.summary ?? 'Agent OS run'}</h2>
                    <p className="mt-1 text-sm leading-6 text-slate-600">{selectedRun.result?.next_recommended_action}</p>
                </div>
                <div className="text-sm text-slate-500">{selectedRun.context_label}</div>
            </div>
        </Panel>
    );
}

export default function Index({ contexts = [], pipelineAgents = [], selectedRun = null, recentRuns = [], prefillAgent = '' }) {
    const initialAgent = useMemo(() => (
        pipelineAgents.some((agent) => agent.key === prefillAgent) ? prefillAgent : 'research'
    ), [pipelineAgents, prefillAgent]);
    const [idea, setIdea] = useState(selectedRun?.original_input ?? defaultIdea);
    const [contextLabel, setContextLabel] = useState(selectedRun?.context_label ?? contexts[0] ?? 'Miriam/Friday');
    const [mode, setMode] = useState(prefillAgent ? 'selected_agent' : 'full_pipeline');
    const [selectedAgent, setSelectedAgent] = useState(initialAgent);
    const [forceContinue, setForceContinue] = useState(false);

    const submit = (event) => {
        event.preventDefault();
        const payload = {
            idea,
            context_label: contextLabel,
            mode,
            selected_agent: mode === 'selected_agent' ? selectedAgent : null,
            force_continue: forceContinue,
        };
        const target = mode === 'selected_agent' ? route('agents.orchestrator.run-agent') : route('agents.orchestrator.run');

        router.post(target, payload, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            title="Agent Orchestrator"
            subtitle="Run one rough idea through Miriam's controlled, review-only business/build pipeline."
            actions={<Link href={route('agents.index')} className={secondaryButton}>All Agents</Link>}
        >
            <Head title="Agent Orchestrator" />

            <div className="grid gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.4fr)]">
                <div className="space-y-5">
                    <Panel className="p-5">
                        <form onSubmit={submit} className="space-y-4">
                            <div>
                                <label htmlFor="idea" className="text-sm font-semibold text-slate-800">Raw idea</label>
                                <textarea
                                    id="idea"
                                    value={idea}
                                    onChange={(event) => setIdea(event.target.value)}
                                    rows={8}
                                    className={`${inputClass} mt-2 w-full`}
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label htmlFor="context_label" className="text-sm font-semibold text-slate-800">Context / product</label>
                                    <select id="context_label" value={contextLabel} onChange={(event) => setContextLabel(event.target.value)} className={`${inputClass} mt-2 w-full`}>
                                        {contexts.map((context) => <option key={context} value={context}>{context}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label htmlFor="mode" className="text-sm font-semibold text-slate-800">Pipeline mode</label>
                                    <select id="mode" value={mode} onChange={(event) => setMode(event.target.value)} className={`${inputClass} mt-2 w-full`}>
                                        <option value="full_pipeline">Run full pipeline</option>
                                        <option value="selected_agent">Run selected agent only</option>
                                    </select>
                                </div>
                            </div>

                            {mode === 'selected_agent' && (
                                <div>
                                    <label htmlFor="selected_agent" className="text-sm font-semibold text-slate-800">Selected agent</label>
                                    <select id="selected_agent" value={selectedAgent} onChange={(event) => setSelectedAgent(event.target.value)} className={`${inputClass} mt-2 w-full`}>
                                        {pipelineAgents.map((agent) => <option key={agent.key} value={agent.key}>{agent.name}</option>)}
                                    </select>
                                </div>
                            )}

                            <label className="flex items-start gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                <input type="checkbox" checked={forceContinue} onChange={(event) => setForceContinue(event.target.checked)} className="mt-1 rounded border-slate-300 text-rose-600 focus:ring-rose-500" />
                                <span>Force continuation after a rejected or research-needed verdict</span>
                            </label>

                            <button type="submit" className={primaryButton}>Run Agent</button>
                        </form>
                    </Panel>

                    <Panel className="p-5">
                        <h2 className="text-lg font-semibold text-slate-950">Recent runs</h2>
                        <div className="mt-3 space-y-2">
                            {recentRuns.length === 0 && <p className="text-sm text-slate-500">No recent Agent OS runs.</p>}
                            {recentRuns.map((run) => (
                                <Link key={run.id} href={route('agents.orchestrator.index', { run: run.id })} className="block rounded-md border border-slate-200 p-3 hover:bg-slate-50">
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-sm font-semibold text-slate-900">Run #{run.id}</span>
                                        <Badge tone={statusTone[run.status] ?? statusTone.pending}>{run.status.replaceAll('_', ' ')}</Badge>
                                    </div>
                                    <p className="mt-1 truncate text-sm text-slate-500">{run.summary ?? run.context_label}</p>
                                </Link>
                            ))}
                        </div>
                    </Panel>
                </div>

                <div className="space-y-5">
                    <RunSummary selectedRun={selectedRun} />

                    {selectedRun?.outputs?.length > 0 && (
                        <div className="space-y-4">
                            {selectedRun.outputs.map((output) => <OutputCard key={output.id} output={output} />)}
                        </div>
                    )}

                    {selectedRun?.logs?.length > 0 && (
                        <Panel className="p-5">
                            <h2 className="text-lg font-semibold text-slate-950">Run logs</h2>
                            <div className="mt-4 space-y-3">
                                {selectedRun.logs.map((log) => (
                                    <div key={log.id} className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <div className="flex flex-wrap items-center gap-2 text-sm">
                                            <Badge tone={log.level === 'error' ? statusTone.failed : (log.level === 'warning' ? statusTone.needs_review : statusTone.completed)}>{log.level}</Badge>
                                            <span className="font-semibold text-slate-800">{log.agent ?? 'Agent OS'}</span>
                                            <span className="text-slate-500">{log.occurred_at}</span>
                                        </div>
                                        <p className="mt-2 text-sm leading-6 text-slate-700">{log.message}</p>
                                    </div>
                                ))}
                            </div>
                        </Panel>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
