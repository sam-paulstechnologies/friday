import OperationsGraph from '@/Components/OperationsCenter/OperationsGraph';
import { Badge, inputClass, primaryButton, secondaryButton } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const defaultIdea = 'Build a WhatsApp sales agent for garages that finds garage numbers, sends templates, follows up, and books demos.';

function findOutput(outputs, agentKey) {
    return outputs.find((output) => output.agent_key === agentKey) ?? outputs[0] ?? null;
}

function outputTone(status) {
    return {
        needs_review: 'bg-amber-50 text-amber-700 ring-amber-100',
        approved: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        rejected: 'bg-rose-50 text-rose-700 ring-rose-100',
    }[status] ?? 'bg-slate-100 text-slate-700 ring-slate-200';
}

function outputStatus(status) {
    return String(status ?? 'unknown').replaceAll('_', ' ');
}

export default function Index({
    contexts = [],
    pipelineAgents = [],
    selectedRun = null,
    recentRuns = [],
    prefillAgent = '',
    graph,
    graphEndpoint,
    detailsEndpoint,
}) {
    const outputs = selectedRun?.outputs ?? [];
    const [selectedNode, setSelectedNode] = useState(null);
    const [copied, setCopied] = useState(false);
    const [logs, setLogs] = useState(null);
    const [loadingLogs, setLoadingLogs] = useState(false);
    const [form, setForm] = useState({
        idea: selectedRun?.original_input ?? defaultIdea,
        context_label: selectedRun?.context_label ?? contexts[0] ?? 'Miriam/Friday',
        mode: prefillAgent ? 'selected_agent' : 'full_pipeline',
        selected_agent: prefillAgent || pipelineAgents[0]?.key || 'research',
        force_continue: false,
    });

    const selectedOutput = useMemo(() => findOutput(outputs, selectedNode?.agent_key), [outputs, selectedNode?.agent_key]);

    const submit = (event) => {
        event.preventDefault();

        const payload = {
            idea: form.idea,
            context_label: form.context_label,
            mode: form.mode,
            selected_agent: form.mode === 'selected_agent' ? form.selected_agent : null,
            force_continue: form.force_continue,
        };

        const target = form.mode === 'selected_agent'
            ? route('agents.orchestrator.run-agent')
            : route('agents.orchestrator.run');

        router.post(target, payload, { preserveScroll: true });
    };

    const copyOutput = async () => {
        if (!selectedOutput) {
            return;
        }

        await navigator.clipboard.writeText(selectedOutput.markdown || selectedOutput.suggested_next_action || selectedOutput.title);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1400);
    };

    const review = (routeName) => {
        if (!selectedOutput) {
            return;
        }

        router.post(route(routeName, selectedOutput.id), {}, { preserveScroll: true });
    };

    const loadLogs = () => {
        if (!selectedRun?.logs_endpoint) {
            return;
        }

        setLoadingLogs(true);
        fetch(selectedRun.logs_endpoint, { headers: { Accept: 'application/json' } })
            .then((response) => response.json())
            .then((payload) => setLogs(payload.logs ?? []))
            .finally(() => setLoadingLogs(false));
    };

    const actionPanel = (
        <section className="rounded-lg border border-slate-800 bg-slate-950/45 p-3 text-slate-200">
            <div className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Run Agent OS</div>
            <form onSubmit={submit} className="space-y-3">
                <textarea
                    value={form.idea}
                    onChange={(event) => setForm({ ...form, idea: event.target.value })}
                    rows={5}
                    className={`${inputClass} w-full border-slate-700 bg-slate-950 text-slate-100 placeholder:text-slate-500 focus:border-violet-400 focus:ring-violet-400/30`}
                    placeholder="Enter one rough idea..."
                />
                <select
                    value={form.context_label}
                    onChange={(event) => setForm({ ...form, context_label: event.target.value })}
                    className={`${inputClass} w-full border-slate-700 bg-slate-950 text-slate-100 focus:border-violet-400 focus:ring-violet-400/30`}
                >
                    {contexts.map((context) => <option key={context} value={context}>{context}</option>)}
                </select>
                <select
                    value={form.mode}
                    onChange={(event) => setForm({ ...form, mode: event.target.value })}
                    className={`${inputClass} w-full border-slate-700 bg-slate-950 text-slate-100 focus:border-violet-400 focus:ring-violet-400/30`}
                >
                    <option value="full_pipeline">Run full pipeline</option>
                    <option value="selected_agent">Run selected agent only</option>
                </select>
                {form.mode === 'selected_agent' && (
                    <select
                        value={form.selected_agent}
                        onChange={(event) => setForm({ ...form, selected_agent: event.target.value })}
                        className={`${inputClass} w-full border-slate-700 bg-slate-950 text-slate-100 focus:border-violet-400 focus:ring-violet-400/30`}
                    >
                        {pipelineAgents.map((agent) => <option key={agent.key} value={agent.key}>{agent.name}</option>)}
                    </select>
                )}
                <label className="flex items-center gap-2 text-xs text-slate-300">
                    <input
                        type="checkbox"
                        checked={form.force_continue}
                        onChange={(event) => setForm({ ...form, force_continue: event.target.checked })}
                        className="rounded border-slate-600 bg-slate-950 text-violet-500 focus:ring-violet-500/40"
                    />
                    Force PRD after weak/rejected validation
                </label>
                <button type="submit" className={`${primaryButton} w-full bg-violet-600 hover:bg-violet-700`}>
                    {form.mode === 'selected_agent' ? 'Run Selected Agent' : 'Run Full Pipeline'}
                </button>
            </form>
        </section>
    );

    const selectedOutputPanel = selectedOutput ? (
        <section data-testid="agent-output-panel" className="rounded-lg border border-slate-800 bg-slate-950/45 p-3 text-slate-200">
            <div className="mb-2 flex items-start justify-between gap-2">
                <div>
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Selected output</div>
                    <div className="mt-1 text-sm font-semibold text-white">{selectedOutput.title}</div>
                    <div className="mt-1 text-xs text-slate-400">{selectedOutput.agent_name}</div>
                </div>
                <Badge tone={outputTone(selectedOutput.status)}>{outputStatus(selectedOutput.status)}</Badge>
            </div>
            <p className="text-xs leading-5 text-slate-300">{selectedOutput.suggested_next_action}</p>
            <div className="mt-3 grid grid-cols-2 gap-2">
                <button type="button" onClick={() => review('agents.outputs.approve')} className="rounded-md border border-lime-400/30 bg-lime-400/10 px-2 py-1.5 text-xs font-semibold text-lime-200" disabled={selectedOutput.status === 'approved'}>Approve</button>
                <button type="button" onClick={() => review('agents.outputs.reject')} className="rounded-md border border-red-400/30 bg-red-400/10 px-2 py-1.5 text-xs font-semibold text-red-200" disabled={selectedOutput.status === 'rejected'}>Reject</button>
                <button type="button" onClick={() => review('agents.outputs.send-to-today')} className="rounded-md border border-amber-400/30 bg-amber-400/10 px-2 py-1.5 text-xs font-semibold text-amber-200">Send to Today</button>
                <button type="button" onClick={copyOutput} className="rounded-md border border-blue-400/30 bg-blue-400/10 px-2 py-1.5 text-xs font-semibold text-blue-200">{copied ? 'Copied' : 'Copy'}</button>
            </div>
            {selectedOutput.markdown && (
                <details className="mt-3 rounded-md border border-slate-800 bg-slate-950/70 p-2">
                    <summary className="cursor-pointer text-xs font-semibold text-slate-300">Expand markdown</summary>
                    <pre className="premium-scrollbar mt-2 max-h-72 overflow-auto whitespace-pre-wrap text-xs leading-5 text-slate-300">{selectedOutput.markdown}</pre>
                </details>
            )}
        </section>
    ) : null;

    return (
        <AuthenticatedLayout
            title="Agent Orchestrator"
            subtitle="Developer-focused Miriam Journey Flow for review-only agent pipeline runs."
            actions={<Link href={route('agents.index')} className={secondaryButton}>All Agents</Link>}
        >
            <Head title="Agent Orchestrator" />

            <div className="space-y-4">
                <OperationsGraph
                    initialGraph={graph}
                    activeView="agent-orchestrator"
                    graphEndpoint={graphEndpoint}
                    detailsEndpoint={detailsEndpoint}
                    onSelectionChange={setSelectedNode}
                    actionPanel={actionPanel}
                    selectedOutputPanel={selectedOutputPanel}
                />

                <section className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h3 className="text-sm font-semibold text-slate-950">Progressive run logs</h3>
                                <p className="mt-1 text-xs text-slate-500">Logs are loaded only when requested for the selected run.</p>
                            </div>
                            <button type="button" onClick={loadLogs} disabled={!selectedRun?.logs_endpoint || loadingLogs} className={secondaryButton}>
                                {loadingLogs ? 'Loading...' : 'Load logs'}
                            </button>
                        </div>
                        {logs && (
                            <div className="premium-scrollbar mt-3 max-h-72 overflow-auto rounded-md border border-slate-200 bg-slate-50">
                                {logs.length === 0 ? (
                                    <div className="p-3 text-sm text-slate-500">No logs for this run.</div>
                                ) : logs.map((log) => (
                                    <div key={log.id} className="border-b border-slate-200 px-3 py-2 text-xs">
                                        <div className="font-semibold text-slate-800">{log.agent ?? 'Agent'} / {log.level}</div>
                                        <div className="mt-1 text-slate-600">{log.message}</div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <h3 className="text-sm font-semibold text-slate-950">Recent runs</h3>
                        <div className="mt-3 space-y-2">
                            {recentRuns.length === 0 ? (
                                <p className="text-sm text-slate-500">No Agent OS runs yet.</p>
                            ) : recentRuns.map((run) => (
                                <Link key={run.id} href={route('agents.orchestrator.index', { run: run.id })} className="block rounded-md border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="truncate font-semibold text-slate-900">Run #{run.id}</span>
                                        <Badge>{outputStatus(run.status)}</Badge>
                                    </div>
                                    <div className="mt-1 truncate text-xs text-slate-500">{run.context_label}</div>
                                </Link>
                            ))}
                        </div>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
