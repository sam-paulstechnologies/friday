export const statusTone = {
    active: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    on_hold: 'bg-amber-50 text-amber-700 ring-amber-100',
    completed: 'bg-blue-50 text-blue-700 ring-blue-100',
    archived: 'bg-slate-100 text-slate-600 ring-slate-200',
    todo: 'bg-slate-100 text-slate-700 ring-slate-200',
    in_progress: 'bg-blue-50 text-blue-700 ring-blue-100',
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
        <span className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold ring-1 ${tone} ${className}`}>
            {children}
        </span>
    );
}

export const primaryButton = 'inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-slate-300/50 transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:opacity-50';
export const secondaryButton = 'inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm shadow-slate-200/60 transition hover:-translate-y-0.5 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-950';
export const dangerButton = 'inline-flex items-center justify-center rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-700 disabled:opacity-50';
export const inputClass = 'rounded-xl border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500';

export function Panel({ children, className = '' }) {
    return (
        <section className={`rounded-2xl border border-slate-200 bg-white shadow-sm shadow-slate-200/70 ${className}`}>
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
        <div className={`rounded-2xl border border-slate-200 bg-white p-3 shadow-sm shadow-slate-200/70 ${className}`}>
            {children}
        </div>
    );
}

export function EmptyState({ title, description, action }) {
    return (
        <div className="p-8 text-center">
            <div className="mx-auto flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-sm font-bold text-slate-500">
                TF
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
        <span className={`inline-flex shrink-0 items-center justify-center rounded-xl bg-emerald-100 font-bold text-emerald-800 ring-1 ring-emerald-200 ${sizes[size]}`}>
            {name.charAt(0).toUpperCase()}
        </span>
    );
}

export function MetadataItem({ label, value, children }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
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
