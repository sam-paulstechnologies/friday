import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge, dangerButton, primaryButton, secondaryButton } from '@/Components/Ui';
import { Head, Link, router } from '@inertiajs/react';
import { DrawingCanvas, NoteForm } from './Create';
import { useState } from 'react';

export default function Show(props) {
    const { note } = props;
    const [editing, setEditing] = useState(false);

    const destroy = () => {
        if (confirm('Delete this note?')) {
            router.delete(route('notes.destroy', note.id));
        }
    };

    return (
        <AuthenticatedLayout title={note.title} subtitle="Note detail and scribble preview.">
            <Head title={note.title} />
            {editing ? (
                <NoteForm {...props} submitLabel="Update Note" />
            ) : (
                <div className="space-y-5">
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={() => setEditing(true)} className={primaryButton}>Edit</button>
                        <button type="button" onClick={destroy} className={dangerButton}>Delete</button>
                        <Link href={route('notes.index')} className={secondaryButton}>Back</Link>
                    </div>
                    <section className="grid gap-5 xl:grid-cols-[420px_1fr]">
                        <div className="rounded-lg border border-slate-200 bg-white p-4">
                            <div className="flex flex-wrap gap-2">
                                <Badge>{note.note_type}</Badge>
                                {note.pinned && <Badge tone="bg-amber-50 text-amber-700 ring-amber-100">Pinned</Badge>}
                                {note.project && <Badge>{note.project.name}</Badge>}
                                {note.task && <Badge>{note.task.title}</Badge>}
                            </div>
                            <p className="mt-5 whitespace-pre-line text-sm leading-7 text-slate-600">{note.content || 'No typed content.'}</p>
                            {note.tags.length > 0 && (
                                <div className="mt-5 flex flex-wrap gap-2">
                                    {note.tags.map((tag) => <Badge key={tag}>{tag}</Badge>)}
                                </div>
                            )}
                        </div>
                        <div className="rounded-lg border border-slate-200 bg-white p-4">
                            <DrawingCanvas value={note.canvas_data} onChange={() => {}} readOnly />
                        </div>
                    </section>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
