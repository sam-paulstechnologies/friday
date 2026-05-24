export const statusTone = {
    active: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    on_hold: 'bg-amber-50 text-amber-700 ring-amber-100',
    completed: 'bg-sky-50 text-sky-700 ring-sky-100',
    archived: 'bg-slate-100 text-slate-600 ring-slate-200',
    todo: 'bg-slate-100 text-slate-700 ring-slate-200',
    in_progress: 'bg-cyan-50 text-cyan-700 ring-cyan-100',
    blocked: 'bg-rose-50 text-rose-700 ring-rose-100',
    review: 'bg-violet-50 text-violet-700 ring-violet-100',
};

export const priorityTone = {
    low: 'bg-slate-100 text-slate-600 ring-slate-200',
    medium: 'bg-blue-50 text-blue-700 ring-blue-100',
    high: 'bg-amber-50 text-amber-700 ring-amber-100',
    urgent: 'bg-rose-50 text-rose-700 ring-rose-100',
};

export const visibilityTone = {
    workspace: 'bg-cyan-50 text-cyan-700 ring-cyan-100',
    team: 'bg-indigo-50 text-indigo-700 ring-indigo-100',
    private: 'bg-slate-100 text-slate-700 ring-slate-200',
};

export function Badge({ children, tone = 'bg-slate-100 text-slate-700 ring-slate-200', className = '' }) {
    return (
        <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${tone} ${className}`}>
            {children}
        </span>
    );
}

export const focusRing = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/60 focus-visible:ring-offset-2 focus-visible:ring-offset-white';
export const primaryButton = `inline-flex items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-slate-300/50 transition duration-200 hover:-translate-y-0.5 hover:bg-slate-800 active:translate-y-0 disabled:pointer-events-none disabled:opacity-50 ${focusRing}`;
export const secondaryButton = `inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm shadow-slate-200/60 transition duration-200 hover:-translate-y-0.5 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-950 active:translate-y-0 disabled:pointer-events-none disabled:opacity-50 ${focusRing}`;
export const dangerButton = `inline-flex items-center justify-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-rose-200 transition duration-200 hover:-translate-y-0.5 hover:bg-rose-700 active:translate-y-0 disabled:pointer-events-none disabled:opacity-50 ${focusRing}`;
export const inputClass = `rounded-xl border-slate-200 bg-white text-sm text-slate-800 shadow-sm shadow-slate-200/50 transition placeholder:text-slate-400 hover:border-slate-300 focus:border-emerald-500 focus:ring-emerald-500/30 disabled:bg-slate-50 disabled:text-slate-400`;

export function StatusPill({ status, labels = {}, className = '' }) {
    return <Badge tone={statusTone[status] ?? statusTone.todo} className={className}>{labels[status] ?? status}</Badge>;
}

export function Panel({ children, className = '' }) {
    return (
        <section className={`rounded-3xl border border-slate-200/80 bg-white shadow-sm shadow-slate-200/70 ${className}`}>
            {children}
        </section>
    );
}

export function PageSection({ title, description, action, children, className = '' }) {
    return (
        <Panel className={className}>
            <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 className="text-sm font-semibold text-slate-950">{title}</h3>
                    {description && <p className="mt-1 text-sm text-slate-500">{description}</p>}
                </div>
                {action}
            </div>
            {children}
        </Panel>
    );
}

export function Toolbar({ children, className = '' }) {
    return (
        <div className={`rounded-3xl border border-slate-200/80 bg-white p-3 shadow-sm shadow-slate-200/70 ${className}`}>
            {children}
        </div>
    );
}

export function EmptyState({ title, description, action }) {
    return (
        <div className="p-8 text-center sm:p-10">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-50 to-cyan-50 text-sm font-black text-emerald-700 ring-1 ring-emerald-100">
                M
            </div>
            <div className="mt-4 text-base font-semibold text-slate-950">{title}</div>
            {description && <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

export function Avatar({ name = 'User', size = 'md' }) {
    const sizes = {
        sm: 'h-7 w-7 text-xs',
        md: 'h-9 w-9 text-sm',
        lg: 'h-11 w-11 text-base',
    };

    return (
        <span className={`inline-flex shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-100 to-cyan-100 font-bold text-emerald-800 ring-1 ring-emerald-200 ${sizes[size]}`}>
            {name.charAt(0).toUpperCase()}
        </span>
    );
}

export function MetadataItem({ label, value, children }) {
    return (
        <div className="rounded-2xl border border-slate-200/80 bg-slate-50/70 p-3">
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-sm font-semibold text-slate-900">{children ?? value}</div>
        </div>
    );
}

export function ViewSwitcher({ items }) {
    return (
        <div className="flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-slate-100 p-1">
            {items.map((item) => {
                const Component = item.component ?? 'a';
                return (
                    <Component
                        key={item.label}
                        href={item.href}
                        className={`rounded-lg px-3 py-1.5 text-sm font-semibold transition ${
                            item.active
                                ? 'bg-white text-slate-950 shadow-sm'
                                : 'text-slate-600 hover:bg-white/70 hover:text-slate-950'
                        }`}
                    >
                        {item.label}
                    </Component>
                );
            })}
        </div>
    );
}

export function ProgressBar({ value = 0, className = '' }) {
    const bounded = Math.max(0, Math.min(100, Number(value) || 0));

    return (
        <div className={`h-2 overflow-hidden rounded-full bg-slate-100 ${className}`}>
            <div className="h-full rounded-full bg-gradient-to-r from-emerald-500 to-cyan-500 transition-all duration-500" style={{ width: `${bounded}%` }} />
        </div>
    );
}

export function DueDate({ date, status }) {
    const today = new Date().toISOString().slice(0, 10);
    const overdue = date && date < today && status !== 'completed';

    return (
        <span className={`inline-flex items-center justify-end rounded-full px-2.5 py-1 text-xs font-semibold ${overdue ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-100' : 'bg-slate-50 text-slate-600 ring-1 ring-slate-200'}`}>
            {date ?? 'No due date'}
        </span>
    );
}

export function DotIcon({ className = '' }) {
    return <span className={`inline-block h-1.5 w-1.5 rounded-full bg-current ${className}`} />;
}
