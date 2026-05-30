import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge, EmptyState, primaryButton } from '@/Components/Ui';
import { Head, Link } from '@inertiajs/react';

export default function Index({ notes }) {
    return (
        <AuthenticatedLayout title="Notes" subtitle="Typed notes, stylus scribbles, and linked context.">
            <Head title="Notes" />
            <div className="mb-4 flex justify-end">
                <Link href={route('notes.create')} className={primaryButton}>New Note</Link>
            </div>
            {notes.length === 0 ? (
                <EmptyState title="No notes yet" description="Create a note to capture typed thoughts or handwritten sketches." action={<Link href={route('notes.create')} className={primaryButton}>Create Note</Link>} />
            ) : (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {notes.map((note) => <NoteCard key={note.id} note={note} />)}
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function NoteCard({ note }) {
    const preview = previewFrom(note.canvas_data);

    return (
        <Link href={route('notes.show', note.id)} className="rounded-lg border border-slate-200 bg-white p-4 transition hover:border-slate-300 hover:shadow-sm">
            {preview && <img src={preview} alt="" className="mb-4 aspect-[5/3] w-full rounded-md border border-slate-100 object-cover" />}
            <div className="flex items-start justify-between gap-3">
                <h2 className="font-semibold text-slate-950">{note.title}</h2>
                {note.pinned && <Badge tone="bg-amber-50 text-amber-700 ring-amber-100">Pinned</Badge>}
            </div>
            <p className="mt-2 line-clamp-3 text-sm leading-6 text-slate-500">{note.content || 'Handwritten note'}</p>
            <div className="mt-4 flex flex-wrap gap-2">
                {note.project && <Badge>{note.project.name}</Badge>}
                {note.portfolio && <Badge>{note.portfolio.name}</Badge>}
                <Badge>{note.note_type}</Badge>
            </div>
        </Link>
    );
}

function previewFrom(value) {
    if (!value) return null;
    try {
        return JSON.parse(value).preview ?? null;
    } catch {
        return value.startsWith('data:image') ? value : null;
    }
}
