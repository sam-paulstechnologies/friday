import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { inputClass, primaryButton, secondaryButton } from '@/Components/Ui';
import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

export default function Create(props) {
    return (
        <AuthenticatedLayout title="New Note" subtitle="Typed notes and stylus scribbles in one place.">
            <Head title="New Note" />
            <NoteForm {...props} submitLabel="Save Note" />
        </AuthenticatedLayout>
    );
}

export function NoteForm({ note = null, workspaces = [], areas = [], portfolios = [], projects = [], tasks = [], spiritualDays = [], submitLabel = 'Save Note' }) {
    const form = useForm({
        workspace_id: note?.workspace_id ?? workspaces[0]?.id ?? '',
        area_id: note?.area_id ?? '',
        portfolio_id: note?.portfolio_id ?? '',
        project_id: note?.project_id ?? '',
        task_id: note?.task_id ?? '',
        spiritual_reading_day_id: note?.spiritual_reading_day_id ?? '',
        title: note?.title ?? '',
        content: note?.content ?? '',
        canvas_data: note?.canvas_data ?? '',
        canvas_preview_path: note?.canvas_preview_path ?? '',
        note_type: note?.note_type ?? 'mixed',
        tags: Array.isArray(note?.tags) ? note.tags.join(', ') : '',
        pinned: note?.pinned ?? false,
    });

    const submit = (event) => {
        event.preventDefault();
        if (note) {
            form.patch(route('notes.update', note.id));
        } else {
            form.post(route('notes.store'));
        }
    };

    return (
        <form onSubmit={submit} className="grid gap-5 xl:grid-cols-[420px_1fr]">
            <section className="space-y-4 rounded-lg border border-slate-200 bg-white p-4">
                <input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="Note title" className={`${inputClass} w-full text-base font-semibold`} />
                <textarea value={form.data.content} onChange={(e) => form.setData('content', e.target.value)} rows="10" placeholder="Typed content" className={`${inputClass} w-full`} />
                <input value={form.data.tags} onChange={(e) => form.setData('tags', e.target.value)} placeholder="Tags, comma separated" className={`${inputClass} w-full`} />
                <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                    <input type="checkbox" checked={form.data.pinned} onChange={(e) => form.setData('pinned', e.target.checked)} className="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                    Pin note
                </label>

                <div className="grid gap-3">
                    <Select label="Workspace" value={form.data.workspace_id} onChange={(value) => form.setData('workspace_id', value)} options={workspaces} />
                    <Select label="Area" value={form.data.area_id} onChange={(value) => form.setData('area_id', value)} options={areas} />
                    <Select label="Portfolio" value={form.data.portfolio_id} onChange={(value) => form.setData('portfolio_id', value)} options={portfolios} />
                    <Select label="Project" value={form.data.project_id} onChange={(value) => form.setData('project_id', value)} options={projects} />
                    <Select label="Task" value={form.data.task_id} onChange={(value) => form.setData('task_id', value)} options={tasks.map((task) => ({ id: task.id, name: task.title }))} />
                    <Select label="Spiritual day" value={form.data.spiritual_reading_day_id} onChange={(value) => form.setData('spiritual_reading_day_id', value)} options={spiritualDays.map((day) => ({ id: day.id, name: `Day ${day.day_number}${day.reading_date ? ` / ${day.reading_date}` : ''}` }))} />
                </div>

                <div className="flex flex-wrap gap-2">
                    <button type="submit" disabled={form.processing} className={primaryButton}>{submitLabel}</button>
                    <Link href={route('notes.index')} className={secondaryButton}>Back</Link>
                </div>
            </section>

            <section className="rounded-lg border border-slate-200 bg-white p-4">
                <DrawingCanvas value={form.data.canvas_data} onChange={(value) => form.setData('canvas_data', value)} />
            </section>
        </form>
    );
}

function Select({ label, value, onChange, options }) {
    return (
        <label className="block">
            <span className="text-xs font-bold uppercase tracking-wide text-slate-500">{label}</span>
            <select value={value ?? ''} onChange={(e) => onChange(e.target.value)} className={`${inputClass} mt-1 w-full`}>
                <option value="">None</option>
                {options.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}
            </select>
        </label>
    );
}

export function DrawingCanvas({ value, onChange, readOnly = false }) {
    const canvasRef = useRef(null);
    const [strokes, setStrokes] = useState(() => parseCanvas(value).strokes);
    const [penColor, setPenColor] = useState('#0f172a');
    const [penSize, setPenSize] = useState(4);
    const [eraser, setEraser] = useState(false);
    const activeStroke = useRef(null);

    const redraw = (nextStrokes = strokes) => {
        const canvas = canvasRef.current;
        if (!canvas) return;
        const context = canvas.getContext('2d');
        context.clearRect(0, 0, canvas.width, canvas.height);
        context.lineCap = 'round';
        context.lineJoin = 'round';
        nextStrokes.forEach((stroke) => drawStroke(context, stroke));
    };

    const persist = (nextStrokes) => {
        const canvas = canvasRef.current;
        const payload = JSON.stringify({ version: 1, strokes: nextStrokes, preview: canvas?.toDataURL('image/png') ?? null });
        onChange(payload);
    };

    const point = (event) => {
        const rect = canvasRef.current.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    };

    const pointerDown = (event) => {
        if (readOnly) return;
        event.currentTarget.setPointerCapture(event.pointerId);
        activeStroke.current = { color: eraser ? '#ffffff' : penColor, size: eraser ? penSize * 3 : penSize, points: [point(event)] };
    };

    const pointerMove = (event) => {
        if (!activeStroke.current || readOnly) return;
        activeStroke.current.points.push(point(event));
        redraw([...strokes, activeStroke.current]);
    };

    const pointerUp = () => {
        if (!activeStroke.current || readOnly) return;
        const nextStrokes = [...strokes, activeStroke.current];
        activeStroke.current = null;
        setStrokes(nextStrokes);
        redraw(nextStrokes);
        persist(nextStrokes);
    };

    const undo = () => {
        const nextStrokes = strokes.slice(0, -1);
        setStrokes(nextStrokes);
        redraw(nextStrokes);
        persist(nextStrokes);
    };

    const clear = () => {
        setStrokes([]);
        redraw([]);
        persist([]);
    };

    return (
        <div>
            {!readOnly && (
                <div className="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 p-2">
                    <input type="color" value={penColor} onChange={(e) => setPenColor(e.target.value)} className="h-9 w-11 rounded-md border border-slate-200 bg-white p-1" />
                    <input type="range" min="1" max="24" value={penSize} onChange={(e) => setPenSize(Number(e.target.value))} className="w-40" />
                    <button type="button" onClick={() => setEraser(!eraser)} className={eraser ? primaryButton : secondaryButton}>Eraser</button>
                    <button type="button" onClick={undo} className={secondaryButton}>Undo</button>
                    <button type="button" onClick={clear} className={secondaryButton}>Clear</button>
                </div>
            )}
            <canvas
                ref={(node) => {
                    canvasRef.current = node;
                    window.requestAnimationFrame(() => redraw());
                }}
                width="1200"
                height="720"
                onPointerDown={pointerDown}
                onPointerMove={pointerMove}
                onPointerUp={pointerUp}
                onPointerCancel={pointerUp}
                className={`block aspect-[5/3] w-full rounded-lg border border-slate-200 bg-white touch-none ${readOnly ? '' : 'cursor-crosshair'}`}
            />
        </div>
    );
}

function drawStroke(context, stroke) {
    if (!stroke.points.length) return;
    context.strokeStyle = stroke.color;
    context.lineWidth = stroke.size;
    context.beginPath();
    context.moveTo(stroke.points[0].x, stroke.points[0].y);
    stroke.points.slice(1).forEach((point) => context.lineTo(point.x, point.y));
    context.stroke();
}

function parseCanvas(value) {
    if (!value) return { strokes: [] };
    try {
        return JSON.parse(value);
    } catch {
        return { strokes: [] };
    }
}
