import { primaryButton, secondaryButton } from '@/Components/Ui';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

export default function AssistantPanel() {
    const [open, setOpen] = useState(false);
    const [message, setMessage] = useState('');
    const [messages, setMessages] = useState([
        { role: 'assistant', content: 'Ask Miriam what to focus on today, to summarize a project, or to draft a task.' },
    ]);
    const [pendingAction, setPendingAction] = useState(null);
    const [loading, setLoading] = useState(false);
    const [voiceNotice, setVoiceNotice] = useState('');

    const send = async (event) => {
        event.preventDefault();
        const text = message.trim();
        if (!text || loading) return;

        setMessages((items) => [...items, { role: 'user', content: text }]);
        setMessage('');
        setLoading(true);

        try {
            const response = await window.axios.post(route('assistant.message'), { message: text });
            setMessages((items) => [...items, { role: 'assistant', content: response.data.message }]);
            setPendingAction(response.data.action ?? null);
        } catch {
            setMessages((items) => [...items, { role: 'assistant', content: 'I could not answer that safely. Please try again.' }]);
        } finally {
            setLoading(false);
        }
    };

    const createTask = async () => {
        if (!pendingAction?.payload) return;

        setLoading(true);
        try {
            const response = await window.axios.post(route('assistant.actions.create-task'), pendingAction.payload);
            setMessages((items) => [...items, { role: 'assistant', content: `Task created. ${response.data.action?.task_url ? 'Open it from the link below.' : ''}`, url: response.data.action?.task_url }]);
            setPendingAction(null);
        } catch {
            setMessages((items) => [...items, { role: 'assistant', content: 'I could not create that task with your current workspace permissions.' }]);
        } finally {
            setLoading(false);
        }
    };

    const startVoice = () => {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            setVoiceNotice('Voice dictation coming soon.');
            return;
        }

        const recognition = new SpeechRecognition();
        recognition.lang = 'en-US';
        recognition.onresult = (event) => setMessage(event.results[0][0].transcript);
        recognition.start();
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="fixed bottom-5 right-5 z-40 inline-flex h-12 w-12 items-center justify-center rounded-full bg-slate-950 text-sm font-bold text-white shadow-lg ring-1 ring-slate-800 hover:bg-slate-800"
                aria-label="Open Miriam Assistant"
                title="Miriam Assistant"
            >
                M
            </button>

            {open && (
                <div className="fixed inset-0 z-50">
                    <button type="button" aria-label="Close assistant" onClick={() => setOpen(false)} className="absolute inset-0 bg-slate-950/25" />
                    <aside className="absolute bottom-0 right-0 top-0 flex w-full max-w-md flex-col border-l border-slate-200 bg-white shadow-xl">
                        <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                            <div>
                                <div className="text-sm font-semibold text-slate-950">Miriam Assistant</div>
                                <div className="text-xs text-slate-500">Workspace-aware, action-safe</div>
                            </div>
                            <button type="button" onClick={() => setOpen(false)} className={secondaryButton}>Close</button>
                        </div>

                        <div className="premium-scrollbar flex-1 space-y-3 overflow-y-auto p-4">
                            {messages.map((item, index) => (
                                <div key={index} className={`rounded-lg px-3 py-2 text-sm leading-6 ${item.role === 'user' ? 'ml-8 bg-rose-50 text-slate-950' : 'mr-8 bg-slate-100 text-slate-700'}`}>
                                    <div className="whitespace-pre-wrap">{item.content}</div>
                                    {item.url && <Link href={item.url} className="mt-2 inline-block font-semibold text-rose-600">Open task</Link>}
                                </div>
                            ))}
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
                        </div>

                        <form onSubmit={send} className="border-t border-slate-200 p-3">
                            {voiceNotice && <div className="mb-2 text-xs font-medium text-slate-500">{voiceNotice}</div>}
                            <div className="flex gap-2">
                                <button type="button" onClick={startVoice} className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 hover:bg-slate-50" aria-label="Voice dictation" title="Voice dictation">
                                    <span className="h-4 w-2 rounded-full border-2 border-current" />
                                </button>
                                <input
                                    value={message}
                                    onChange={(event) => setMessage(event.target.value)}
                                    placeholder="Ask Miriam..."
                                    className="min-w-0 flex-1 rounded-md border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500/30"
                                />
                                <button type="submit" disabled={loading} className={primaryButton}>Send</button>
                            </div>
                        </form>
                    </aside>
                </div>
            )}
        </>
    );
}
