import { Badge, EmptyState, PageSection, primaryButton, secondaryButton } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

const prompts = [
    'What should I focus on today?',
    'Plan my week',
    'Who is overloaded?',
    'Create a task to call John tomorrow',
];

export default function Index({ assistant }) {
    const [message, setMessage] = useState('');
    const [items, setItems] = useState([]);
    const [pendingAction, setPendingAction] = useState(null);
    const [loading, setLoading] = useState(false);

    const ask = async (text = message) => {
        if (!text.trim() || loading) return;
        setItems((existing) => [...existing, { role: 'user', content: text }]);
        setMessage('');
        setLoading(true);
        try {
            const response = await window.axios.post(route('assistant.message'), { message: text });
            setItems((existing) => [...existing, { role: 'assistant', content: response.data.message }]);
            setPendingAction(response.data.action ?? null);
        } finally {
            setLoading(false);
        }
    };

    const createTask = async () => {
        if (!pendingAction?.payload) return;
        setLoading(true);
        try {
            const response = await window.axios.post(route('assistant.actions.create-task'), pendingAction.payload);
            setItems((existing) => [...existing, { role: 'assistant', content: response.data.message }]);
            setPendingAction(null);
        } finally {
            setLoading(false);
        }
    };

    return (
        <AuthenticatedLayout title="Assistant" subtitle="Ask Miriam about today, projects, workload, and safe task creation.">
            <Head title="Assistant" />
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
                <PageSection title="Chat" description="The assistant uses accessible workspace data only.">
                    <div className="space-y-3 p-4">
                        {!assistant.enabled && (
                            <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                AI Assistant is disabled. Enable it with safe local/mock configuration when ready.
                            </div>
                        )}
                        <div className="min-h-80 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            {items.length === 0 ? <EmptyState title="Ask Miriam anything work-related" /> : items.map((item, index) => (
                                <div key={index} className={`rounded-lg px-3 py-2 text-sm leading-6 ${item.role === 'user' ? 'ml-10 bg-rose-50 text-slate-950' : 'mr-10 bg-white text-slate-700'}`}>
                                    <div className="whitespace-pre-wrap">{item.content}</div>
                                </div>
                            ))}
                        </div>
                        {pendingAction?.type === 'create_task' && (
                            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm">
                                <div className="font-semibold text-amber-900">Confirm task creation</div>
                                <div className="mt-1 text-amber-800">{pendingAction.payload.title}</div>
                                <div className="mt-3 flex gap-2">
                                    <button type="button" onClick={createTask} disabled={loading} className={primaryButton}>Create task</button>
                                    <button type="button" onClick={() => setPendingAction(null)} className={secondaryButton}>Cancel</button>
                                </div>
                            </div>
                        )}
                        <form onSubmit={(event) => { event.preventDefault(); ask(); }} className="flex gap-2">
                            <input value={message} onChange={(event) => setMessage(event.target.value)} className="min-w-0 flex-1 rounded-md border-slate-300 text-sm" placeholder="Ask Miriam..." />
                            <button type="submit" disabled={loading} className={primaryButton}>Send</button>
                        </form>
                    </div>
                </PageSection>
                <div className="space-y-4">
                    <PageSection title="Status">
                        <div className="space-y-2 p-4 text-sm">
                            <Status label="Enabled" value={assistant.enabled ? 'Yes' : 'No'} />
                            <Status label="Provider" value={assistant.provider} />
                            <Status label="Model" value={assistant.model ?? 'Mock/local'} />
                            <Status label="API key" value={assistant.api_key_configured ? 'Configured' : 'Not configured'} />
                        </div>
                    </PageSection>
                    <PageSection title="Quick prompts">
                        <div className="space-y-2 p-4">
                            {prompts.map((prompt) => <button key={prompt} type="button" onClick={() => ask(prompt)} className="block w-full rounded-md border border-slate-200 px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-slate-50">{prompt}</button>)}
                        </div>
                    </PageSection>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Status({ label, value }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <span className="text-slate-500">{label}</span>
            <Badge>{value}</Badge>
        </div>
    );
}
