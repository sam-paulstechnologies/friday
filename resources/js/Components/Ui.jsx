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
        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${tone} ${className}`}>
            {children}
        </span>
    );
}

export const focusRing = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500/50 focus-visible:ring-offset-2 focus-visible:ring-offset-white';
export const primaryButton = `inline-flex items-center justify-center gap-2 rounded-md bg-rose-500 px-3 py-2 text-sm font-semibold text-white transition duration-150 hover:bg-rose-600 active:bg-rose-700 disabled:pointer-events-none disabled:opacity-50 ${focusRing}`;
export const secondaryButton = `inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition duration-150 hover:bg-slate-50 hover:text-slate-950 active:bg-slate-100 disabled:pointer-events-none disabled:opacity-50 ${focusRing}`;
export const dangerButton = `inline-flex items-center justify-center gap-2 rounded-md bg-rose-600 px-3 py-2 text-sm font-semibold text-white transition duration-150 hover:bg-rose-700 active:bg-rose-800 disabled:pointer-events-none disabled:opacity-50 ${focusRing}`;
export const inputClass = `rounded-md border-slate-300 bg-white text-sm text-slate-800 transition placeholder:text-slate-400 hover:border-slate-400 focus:border-amber-500 focus:ring-amber-500/30 disabled:bg-slate-50 disabled:text-slate-400`;

export function StatusPill({ status, labels = {}, className = '' }) {
    return <Badge tone={statusTone[status] ?? statusTone.todo} className={className}>{labels[status] ?? status}</Badge>;
}

export function Panel({ children, className = '' }) {
    return (
        <section className={`rounded-lg border border-slate-200 bg-white ${className}`}>
            {children}
        </section>
    );
}

export function PageSection({ title, description, action, children, className = '' }) {
    return (
        <Panel className={className}>
            <div className="flex flex-col gap-3 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
                    {description && <p className="mt-0.5 text-xs text-slate-500">{description}</p>}
                </div>
                {action}
            </div>
            {children}
        </Panel>
    );
}

export function Toolbar({ children, className = '' }) {
    return (
        <div className={`sticky top-14 z-10 rounded-lg border border-slate-200 bg-white p-3 ${className}`}>
            {children}
        </div>
    );
}

export function EmptyState({ title, description, action }) {
    return (
        <div className="p-6 text-center sm:p-8">
            <div className="mx-auto flex h-10 w-10 items-center justify-center rounded-lg bg-rose-50 text-sm font-black text-rose-600 ring-1 ring-rose-100">
                M
            </div>
            <div className="mt-3 text-sm font-semibold text-slate-900">{title}</div>
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
        <span className={`inline-flex shrink-0 items-center justify-center rounded-full bg-slate-200 font-bold text-slate-700 ring-1 ring-slate-300 ${sizes[size]}`}>
            {name.charAt(0).toUpperCase()}
        </span>
    );
}

export function MetadataItem({ label, value, children }) {
    return (
        <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-sm font-semibold text-slate-900">{children ?? value}</div>
        </div>
    );
}

export function ViewSwitcher({ items }) {
    return (
        <div className="flex flex-wrap gap-1 rounded-md border border-slate-200 bg-slate-100 p-1">
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
        <div className={`h-1.5 overflow-hidden rounded-full bg-slate-100 ${className}`}>
            <div className="h-full rounded-full bg-rose-500 transition-all duration-500" style={{ width: `${bounded}%` }} />
        </div>
    );
}

export function DueDate({ date, status }) {
    const today = new Date().toISOString().slice(0, 10);
    const overdue = date && date < today && status !== 'completed';

    return (
        <span className={`inline-flex items-center justify-end rounded-md px-2 py-0.5 text-xs font-medium ${overdue ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-100' : 'bg-slate-50 text-slate-600 ring-1 ring-slate-200'}`}>
            {date ?? 'No due date'}
        </span>
    );
}

export function DotIcon({ className = '' }) {
    return <span className={`inline-block h-1.5 w-1.5 rounded-full bg-current ${className}`} />;
}

export function AppCard({ children, className = '' }) {
    return <section className={`rounded-lg border border-slate-200 bg-white ${className}`}>{children}</section>;
}

export function PageHeader({ title, subtitle, actions, meta, className = '' }) {
    return (
        <div className={`mb-4 flex flex-col gap-3 border-b border-slate-200 pb-4 lg:flex-row lg:items-start lg:justify-between ${className}`}>
            <div className="min-w-0">
                {meta && <div className="mb-1 text-xs font-semibold text-slate-500">{meta}</div>}
                <h2 className="truncate text-2xl font-semibold tracking-tight text-slate-950">{title}</h2>
                {subtitle && <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-500">{subtitle}</p>}
            </div>
            {actions && <div className="flex shrink-0 flex-wrap gap-2">{actions}</div>}
        </div>
    );
}

export function PriorityDot({ priority, className = '' }) {
    const tones = {
        urgent: 'bg-rose-500',
        high: 'bg-amber-500',
        medium: 'bg-blue-500',
        low: 'bg-slate-300',
    };

    return <span className={`inline-block h-2.5 w-2.5 rounded-full ${tones[priority] ?? tones.low} ${className}`} />;
}

export function IconButton({ children, label, className = '', ...props }) {
    return (
        <button type="button" aria-label={label} title={label} className={`inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-950 ${focusRing} ${className}`} {...props}>
            {children}
        </button>
    );
}

export function CreateButton({ children = 'Create', ...props }) {
    return <button type="button" className={primaryButton} {...props}>{children}</button>;
}

export function SectionTabs({ items, value, onChange }) {
    return (
        <div className="flex flex-wrap gap-1 border-b border-slate-200">
            {items.map((item) => {
                const active = item.value === value;
                return (
                    <button key={item.value} type="button" onClick={() => onChange(item.value)} className={`border-b-2 px-3 py-2 text-sm font-medium transition ${active ? 'border-rose-500 text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-900'}`}>
                        {item.label}
                    </button>
                );
            })}
        </div>
    );
}

export const ViewTabs = ViewSwitcher;

export function FilterBar({ children, className = '' }) {
    return <Toolbar className={className}>{children}</Toolbar>;
}

export function CompactTable({ columns, rows, emptyTitle = 'No data' }) {
    if (rows.length === 0) {
        return <EmptyState title={emptyTitle} />;
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-left text-sm">
                <thead>
                    <tr>
                        {columns.map((column) => <th key={column} className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{column}</th>)}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.key} className="hover:bg-slate-50">
                            {row.cells.map((cell, index) => <td key={index} className="border-b border-slate-100 px-3 py-2 align-middle text-slate-700">{cell}</td>)}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function TaskRow({ task, href, statusLabels = {}, priorityLabels = {} }) {
    const completed = task.status === 'completed';

    return (
        <a href={href} className={`grid gap-3 border-b border-slate-100 px-4 py-2.5 text-sm transition hover:bg-slate-50 lg:grid-cols-[minmax(0,1fr)_130px_120px_150px_120px] lg:items-center ${completed ? 'bg-slate-50/70' : ''}`}>
            <div className="flex min-w-0 items-center gap-3">
                <span className={`h-4 w-4 rounded-full border ${completed ? 'border-emerald-500 bg-emerald-500' : 'border-slate-300 bg-white'}`} />
                <div className="min-w-0">
                    <div className={`truncate font-medium text-slate-900 ${completed ? 'text-slate-500 line-through decoration-slate-300' : ''}`}>{task.title}</div>
                    <div className="mt-0.5 truncate text-xs text-slate-500">{task.project?.name ?? task.section ?? 'No project'}{task.portfolio?.name ? ` / ${task.portfolio.name}` : ''}</div>
                </div>
            </div>
            <Badge tone={statusTone[task.status]}>{statusLabels[task.status] ?? task.status}</Badge>
            <span className="inline-flex items-center gap-2 text-xs font-medium text-slate-600"><PriorityDot priority={task.priority} />{priorityLabels[task.priority] ?? task.priority}</span>
            <span className="truncate text-xs font-medium text-slate-600">{task.assignee?.name ?? 'Unassigned'}</span>
            <DueDate date={task.due_date} status={task.status} />
        </a>
    );
}

export function ProjectTile({ project, href, children }) {
    return (
        <a href={href} className="block rounded-lg border border-slate-200 bg-white p-4 transition hover:border-slate-300 hover:shadow-sm">
            <div className="flex items-start gap-3">
                <span className="mt-1 h-3 w-3 rounded-sm" style={{ backgroundColor: project.color ?? '#64748b' }} />
                <div className="min-w-0 flex-1">
                    <div className="truncate font-semibold text-slate-950">{project.name}</div>
                    <div className="mt-1 truncate text-xs text-slate-500">{project.portfolio?.name ?? project.workspace?.name ?? 'No portfolio'}</div>
                </div>
            </div>
            {children}
        </a>
    );
}

export function PortfolioTile({ portfolio, href, children }) {
    return (
        <a href={href} className="block rounded-lg border border-slate-200 bg-white p-4 transition hover:border-slate-300 hover:shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="truncate font-semibold text-slate-950">{portfolio.name}</div>
                    <div className="mt-1 text-xs text-slate-500">{portfolio.description}</div>
                </div>
                <Badge>{portfolio.status}</Badge>
            </div>
            {children}
        </a>
    );
}
