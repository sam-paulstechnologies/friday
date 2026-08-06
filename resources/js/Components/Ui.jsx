/**
 * Legacy shared primitives.
 *
 * Retuned onto the Miriam design tokens so every page that still imports from
 * here inherits the same surfaces, ink, focus ring and semantic colours as the
 * redesigned core. New work should import from '@/Components/Kit'.
 */
export const statusTone = {
    active: 'bg-good-soft text-good-ink ring-good/20',
    on_hold: 'bg-warn-soft text-warn-ink ring-warn/20',
    completed: 'bg-good-soft text-good-ink ring-good/20',
    archived: 'bg-surface-sunken text-ink-muted ring-line',
    todo: 'bg-surface-sunken text-ink-muted ring-line',
    in_progress: 'bg-info-soft text-info-ink ring-info/20',
    blocked: 'bg-urgent-soft text-urgent-ink ring-urgent/20',
    review: 'bg-brand-50 text-brand-700 ring-brand-200',
};

export const priorityTone = {
    low: 'bg-surface-sunken text-ink-muted ring-line',
    medium: 'bg-surface-sunken text-ink-muted ring-line',
    high: 'bg-warn-soft text-warn-ink ring-warn/20',
    urgent: 'bg-urgent-soft text-urgent-ink ring-urgent/20',
};

export const visibilityTone = {
    workspace: 'bg-info-soft text-info-ink ring-info/20',
    team: 'bg-brand-50 text-brand-700 ring-brand-200',
    private: 'bg-surface-sunken text-ink-muted ring-line',
};

export function Badge({ children, tone = 'bg-surface-sunken text-ink-muted ring-line', className = '' }) {
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset ${tone} ${className}`}>
            {children}
        </span>
    );
}

export const focusRing = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 focus-visible:ring-offset-2 focus-visible:ring-offset-surface';
export const primaryButton = `inline-flex items-center justify-center gap-2 rounded-md bg-brand-600 px-3 py-2 text-sm font-semibold text-ink-inverse transition duration-150 hover:bg-brand-700 active:bg-brand-700 disabled:pointer-events-none disabled:opacity-50 ${focusRing}`;
export const secondaryButton = `inline-flex items-center justify-center gap-2 rounded-md border border-line-strong bg-surface px-3 py-2 text-sm font-semibold text-ink transition duration-150 hover:bg-surface-sunken active:bg-surface-sunken disabled:pointer-events-none disabled:opacity-50 ${focusRing}`;
export const dangerButton = `inline-flex items-center justify-center gap-2 rounded-md bg-urgent px-3 py-2 text-sm font-semibold text-ink-inverse transition duration-150 hover:brightness-95 disabled:pointer-events-none disabled:opacity-50 ${focusRing}`;
export const inputClass = `rounded-control border-line-strong bg-surface text-sm text-ink transition placeholder:text-ink-subtle hover:border-line-strong focus:border-brand-500 focus:ring-brand-500/25 disabled:bg-surface-sunken disabled:text-ink-subtle`;

export function StatusPill({ status, labels = {}, className = '' }) {
    return <Badge tone={statusTone[status] ?? statusTone.todo} className={className}>{labels[status] ?? status}</Badge>;
}

export function Panel({ children, className = '' }) {
    return (
        <section className={`rounded-panel border border-line bg-surface shadow-panel ${className}`}>
            {children}
        </section>
    );
}

export function PageSection({ title, description, action, children, className = '' }) {
    return (
        <Panel className={className}>
            <div className="flex flex-col gap-3 border-b border-line px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 className="text-sm font-semibold text-ink">{title}</h3>
                    {description && <p className="mt-0.5 text-xs text-ink-subtle">{description}</p>}
                </div>
                {action}
            </div>
            {children}
        </Panel>
    );
}

export function Toolbar({ children, className = '' }) {
    return (
        <div className={`sticky top-14 z-10 rounded-lg border border-line bg-surface/95 p-3 shadow-sm shadow-slate-200/50 backdrop-blur ${className}`}>
            {children}
        </div>
    );
}

export function EmptyState({ title, description, action }) {
    return (
        <div className="p-6 text-center sm:p-8">
            <div className="mx-auto flex h-10 w-10 items-center justify-center rounded-lg bg-surface-sunken text-sm font-black text-ink-subtle ring-1 ring-slate-200">
                M
            </div>
            <div className="mt-3 text-sm font-semibold text-ink">{title}</div>
            {description && <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-ink-subtle">{description}</p>}
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
        <div className="rounded-md border border-line bg-surface-sunken/80 p-3">
            <div className="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{label}</div>
            <div className="mt-1 text-sm font-semibold text-ink">{children ?? value}</div>
        </div>
    );
}

export function ViewSwitcher({ items }) {
    return (
        <div className="flex flex-wrap gap-1 rounded-md border border-line bg-slate-100 p-1">
            {items.map((item) => {
                const Component = item.component ?? 'a';
                return (
                    <Component
                        key={item.label}
                        href={item.href}
                        className={`rounded-lg px-3 py-1.5 text-sm font-semibold transition ${
                            item.active
                                ? 'bg-surface text-ink shadow-sm'
                                : 'text-ink-muted hover:bg-surface/70 hover:text-ink'
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
        <span className={`inline-flex items-center justify-end rounded-md px-2 py-0.5 text-xs font-medium ${overdue ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-100' : 'bg-surface-sunken text-ink-muted ring-1 ring-slate-200'}`}>
            {date ?? 'No due date'}
        </span>
    );
}

export function DotIcon({ className = '' }) {
    return <span className={`inline-block h-1.5 w-1.5 rounded-full bg-current ${className}`} />;
}

export function AppCard({ children, className = '' }) {
    return <section className={`rounded-panel border border-line bg-surface shadow-panel ${className}`}>{children}</section>;
}

export function PageHeader({ title, subtitle, actions, meta, className = '' }) {
    return (
        <div className={`mb-4 flex flex-col gap-3 border-b border-line pb-4 lg:flex-row lg:items-start lg:justify-between ${className}`}>
            <div className="min-w-0">
                {meta && <div className="mb-1 text-xs font-semibold text-ink-subtle">{meta}</div>}
                <h2 className="truncate text-2xl font-semibold tracking-tight text-ink">{title}</h2>
                {subtitle && <p className="mt-1 max-w-3xl text-sm leading-6 text-ink-subtle">{subtitle}</p>}
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
        <button type="button" aria-label={label} title={label} className={`inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-300 bg-surface text-ink-muted hover:bg-surface-sunken hover:text-ink ${focusRing} ${className}`} {...props}>
            {children}
        </button>
    );
}

export function CreateButton({ children = 'Create', ...props }) {
    return <button type="button" className={primaryButton} {...props}>{children}</button>;
}

export function SectionTabs({ items, value, onChange }) {
    return (
        <div className="flex flex-wrap gap-1 border-b border-line">
            {items.map((item) => {
                const active = item.value === value;
                return (
                    <button key={item.value} type="button" onClick={() => onChange(item.value)} className={`border-b-2 px-3 py-2 text-sm font-medium transition ${active ? 'border-rose-500 text-ink' : 'border-transparent text-ink-subtle hover:text-ink'}`}>
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
                        {columns.map((column) => <th key={column} className="border-b border-line bg-surface-sunken px-3 py-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{column}</th>)}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.key} className="hover:bg-surface-sunken">
                            {row.cells.map((cell, index) => <td key={index} className="border-b border-line px-3 py-2 align-middle text-slate-700">{cell}</td>)}
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
        <a href={href} className={`grid gap-3 border-b border-line px-4 py-2.5 text-sm transition hover:bg-surface-sunken lg:grid-cols-[minmax(0,1fr)_130px_120px_150px_120px] lg:items-center ${completed ? 'bg-surface-sunken/70' : ''}`}>
            <div className="flex min-w-0 items-center gap-3">
                <span className={`h-4 w-4 rounded-full border ${completed ? 'border-emerald-500 bg-emerald-500' : 'border-slate-300 bg-surface'}`} />
                <div className="min-w-0">
                    <div className={`truncate font-medium text-ink ${completed ? 'text-ink-subtle line-through decoration-slate-300' : ''}`}>{task.title}</div>
                    <div className="mt-0.5 truncate text-xs text-ink-subtle">{task.project?.name ?? task.section ?? 'No project'}{task.portfolio?.name ? ` / ${task.portfolio.name}` : ''}</div>
                </div>
            </div>
            <Badge tone={statusTone[task.status]}>{statusLabels[task.status] ?? task.status}</Badge>
            <span className="inline-flex items-center gap-2 text-xs font-medium text-ink-muted"><PriorityDot priority={task.priority} />{priorityLabels[task.priority] ?? task.priority}</span>
            <span className="truncate text-xs font-medium text-ink-muted">{task.assignee?.name ?? 'Unassigned'}</span>
            <DueDate date={task.due_date} status={task.status} />
        </a>
    );
}

export function ProjectTile({ project, href, children }) {
    return (
        <a href={href} className="block rounded-lg border border-line bg-surface p-4 shadow-sm shadow-slate-200/40 transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md">
            <div className="flex items-start gap-3">
                <span className="mt-1 h-3 w-3 rounded-sm" style={{ backgroundColor: project.color ?? '#64748b' }} />
                <div className="min-w-0 flex-1">
                    <div className="truncate font-semibold text-ink">{project.name}</div>
                    <div className="mt-1 truncate text-xs text-ink-subtle">{project.portfolio?.name ?? project.workspace?.name ?? 'No portfolio'}</div>
                </div>
            </div>
            {children}
        </a>
    );
}

export function PortfolioTile({ portfolio, href, children }) {
    return (
        <a href={href} className="block rounded-lg border border-line bg-surface p-4 shadow-sm shadow-slate-200/40 transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="truncate font-semibold text-ink">{portfolio.name}</div>
                    <div className="mt-1 text-xs text-ink-subtle">{portfolio.description}</div>
                </div>
                <Badge>{portfolio.status}</Badge>
            </div>
            {children}
        </a>
    );
}
