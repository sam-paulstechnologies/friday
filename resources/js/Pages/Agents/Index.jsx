import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge, Panel, primaryButton, secondaryButton } from '@/Components/Ui';
import { Head, Link } from '@inertiajs/react';

const categoryTone = {
    orchestration: 'bg-rose-50 text-rose-700 ring-rose-100',
    research: 'bg-sky-50 text-sky-700 ring-sky-100',
    validation: 'bg-amber-50 text-amber-700 ring-amber-100',
    documentation: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    routing: 'bg-violet-50 text-violet-700 ring-violet-100',
    prompting: 'bg-indigo-50 text-indigo-700 ring-indigo-100',
    quality: 'bg-slate-100 text-slate-700 ring-slate-200',
    go_to_market: 'bg-pink-50 text-pink-700 ring-pink-100',
    capture: 'bg-orange-50 text-orange-700 ring-orange-100',
};

export default function Index({ agents = [] }) {
    return (
        <AuthenticatedLayout
            title="Agents"
            subtitle="Rule-based Miriam agents that create reviewable outputs only."
            actions={<Link href={route('agents.orchestrator.index')} className={primaryButton}>Open Orchestrator</Link>}
        >
            <Head title="Agents" />

            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {agents.map((agent) => (
                    <Panel key={agent.key} className="flex min-h-52 flex-col justify-between p-5">
                        <div>
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-lg font-semibold text-slate-950">{agent.name}</h2>
                                    <p className="mt-2 text-sm leading-6 text-slate-600">{agent.description}</p>
                                </div>
                                <Badge tone={categoryTone[agent.category] ?? categoryTone.quality}>{agent.category.replaceAll('_', ' ')}</Badge>
                            </div>
                        </div>

                        <div className="mt-5 flex flex-wrap gap-2">
                            <Link href={agent.href} className={agent.key === 'agent-orchestrator' ? primaryButton : secondaryButton}>
                                Open
                            </Link>
                            {agent.key !== 'task-capture' && (
                                <Link href={route('agents.orchestrator.index', { agent: agent.key })} className={secondaryButton}>
                                    Run selected
                                </Link>
                            )}
                        </div>
                    </Panel>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
