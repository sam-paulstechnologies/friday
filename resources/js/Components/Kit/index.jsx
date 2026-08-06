import { Dialog, Menu, Transition } from '@headlessui/react';
import { Link } from '@inertiajs/react';
import { Fragment, forwardRef } from 'react';
import { badgeTones, buttonClass, cx, fieldClass, panelClass } from './primitives';

export { cx, buttonClass, fieldClass, panelClass, badgeTones } from './primitives';

/* ------------------------------------------------------------------ icons */

/**
 * A single stroked icon set so weight and sizing stay consistent.
 * Purely decorative: every icon-only control carries its own accessible name.
 */
const paths = {
    today: 'M8 2v3M16 2v3M3.5 9h17M4 5.5h16a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6.5a1 1 0 0 1 1-1Z',
    inbox: 'M3 13h4l1.5 3h7L17 13h4M4 5h16l1 8v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-6l1-8Z',
    check: 'M4 12.5 9 17.5 20 6.5',
    list: 'M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01',
    folder: 'M3 7a1 1 0 0 1 1-1h5l2 2.5h8a1 1 0 0 1 1 1V18a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7Z',
    grid: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
    clock: 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    send: 'M4 12h9M4 12 3 5l17 7-17 7 1-7Z',
    bell: 'M18 9a6 6 0 1 0-12 0c0 5-2 6-2 6h16s-2-1-2-6M10.5 20a2 2 0 0 0 3 0',
    calendar: 'M8 2v3M16 2v3M3.5 9h17M4 5.5h16a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6.5a1 1 0 0 1 1-1Z',
    heart: 'M12 20s-7-4.5-7-9.5A4 4 0 0 1 12 8a4 4 0 0 1 7 2.5C19 15.5 12 20 12 20Z',
    book: 'M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2V5ZM19 19H6',
    spark: 'M12 3l2 6 6 2-6 2-2 6-2-6-6-2 6-2 2-6Z',
    layers: 'M12 3 3 8l9 5 9-5-9-5ZM3 13l9 5 9-5M3 17.5l9 5 9-5',
    settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1Z',
    plus: 'M12 5v14M5 12h14',
    search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM21 21l-4.3-4.3',
    close: 'M6 6l12 12M18 6 6 18',
    chevronLeft: 'M15 6l-6 6 6 6',
    chevronRight: 'M9 6l6 6-6 6',
    chevronDown: 'M6 9l6 6 6-6',
    menu: 'M4 7h16M4 12h16M4 17h16',
    dots: 'M12 6.01V6M12 12.01V12M12 18.01V18',
    alert: 'M12 9v4M12 17h.01M10.3 4.3 2.6 17.6A2 2 0 0 0 4.3 20.6h15.4a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0Z',
    arrowRight: 'M5 12h14M13 6l6 6-6 6',
    external: 'M14 5h5v5M19 5l-8 8M18 14v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h5',
    user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4.5 20a7.5 7.5 0 0 1 15 0',
    flag: 'M5 21V4M5 4h11l-2 4 2 4H5',
    refresh: 'M20 11a8 8 0 1 0-2.3 6M20 5v6h-6',
};

export function Icon({ name, className = 'h-4 w-4', strokeWidth = 1.75 }) {
    const d = paths[name];

    if (!d) {
        return null;
    }

    return (
        <svg
            aria-hidden="true"
            focusable="false"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={strokeWidth}
            strokeLinecap="round"
            strokeLinejoin="round"
            className={cx('shrink-0', className)}
        >
            <path d={d} />
        </svg>
    );
}

/* ---------------------------------------------------------------- buttons */

export const Button = forwardRef(function Button(
    { as: Component = 'button', variant = 'secondary', size = 'md', className = '', type, ...props },
    ref,
) {
    return (
        <Component
            ref={ref}
            type={Component === 'button' ? (type ?? 'button') : type}
            className={buttonClass({ variant, size, className })}
            {...props}
        />
    );
});

export function LinkButton({ href, variant = 'secondary', size = 'md', className = '', ...props }) {
    return <Link href={href} className={buttonClass({ variant, size, className })} {...props} />;
}

/** Icon-only control. `label` is mandatory and becomes the accessible name. */
export function IconButton({ icon, label, variant = 'ghost', size = 'icon', className = '', ...props }) {
    return (
        <button type="button" aria-label={label} title={label} className={buttonClass({ variant, size, className })} {...props}>
            <Icon name={icon} className="h-4 w-4" />
        </button>
    );
}

/* ----------------------------------------------------------------- badges */

export function Badge({ tone = 'neutral', icon, children, className = '' }) {
    return (
        <span
            className={cx(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset',
                badgeTones[tone] ?? badgeTones.neutral,
                className,
            )}
        >
            {icon && <Icon name={icon} className="h-3 w-3" />}
            {children}
        </span>
    );
}

/** Truthful availability marker for partial modules. */
export function PreviewBadge({ state = 'preview' }) {
    const map = {
        preview: ['neutral', 'Preview'],
        planned: ['neutral', 'Planned'],
        not_connected: ['warn', 'Not connected'],
        configuration_required: ['warn', 'Setup needed'],
        unavailable: ['urgent', 'Unavailable'],
        manual: ['info', 'Manual'],
        connected: ['good', 'Connected'],
        active: ['good', 'Active'],
    };
    const [tone, label] = map[state] ?? map.preview;

    return <Badge tone={tone}>{label}</Badge>;
}

export function ConnectionStatus({ connected, connectedLabel = 'Connected', disconnectedLabel = 'Not connected', detail }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-ink-muted">
            <span
                aria-hidden="true"
                className={cx('h-1.5 w-1.5 rounded-full', connected ? 'bg-good' : 'bg-line-strong')}
            />
            {connected ? connectedLabel : disconnectedLabel}
            {detail && <span className="font-normal text-ink-subtle">· {detail}</span>}
        </span>
    );
}

/* --------------------------------------------------------------- surfaces */

export function Panel({ children, className = '', as: Component = 'section', ...props }) {
    return (
        <Component className={cx(panelClass, className)} {...props}>
            {children}
        </Component>
    );
}

export function PanelHeader({ title, description, action, count, className = '' }) {
    return (
        <div className={cx('flex flex-wrap items-start justify-between gap-3 border-b border-line px-4 py-3 sm:px-5', className)}>
            <div className="min-w-0">
                <div className="flex items-center gap-2">
                    <h2 className="text-sm font-bold tracking-tight text-ink">{title}</h2>
                    {count != null && (
                        <span className="rounded-full bg-surface-sunken px-1.5 text-xs font-semibold text-ink-muted">{count}</span>
                    )}
                </div>
                {description && <p className="mt-0.5 text-sm text-ink-subtle">{description}</p>}
            </div>
            {action && <div className="flex shrink-0 items-center gap-2">{action}</div>}
        </div>
    );
}

/* ------------------------------------------------------------ page header */

export function PageHeader({ title, subtitle, meta, actions, tabs, breadcrumbs }) {
    return (
        <header className="border-b border-line bg-surface">
            <div className="mx-auto w-full max-w-[1400px] px-4 pt-4 sm:px-6">
                {breadcrumbs && <Breadcrumbs items={breadcrumbs} />}
                <div className="flex flex-wrap items-start justify-between gap-3 pb-3">
                    <div className="min-w-0">
                        <h1 className="truncate text-xl font-bold tracking-tight text-ink sm:text-2xl">{title}</h1>
                        {subtitle && <p className="mt-1 text-sm text-ink-muted">{subtitle}</p>}
                        {meta && <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">{meta}</div>}
                    </div>
                    {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
                </div>
                {tabs}
            </div>
        </header>
    );
}

export function Breadcrumbs({ items = [] }) {
    if (!items.length) return null;

    return (
        <nav aria-label="Breadcrumb" className="pb-1">
            <ol className="flex flex-wrap items-center gap-1 text-xs text-ink-subtle">
                {items.map((item, index) => (
                    <li key={item.label} className="flex items-center gap-1">
                        {index > 0 && <Icon name="chevronRight" className="h-3 w-3" />}
                        {item.href ? (
                            <Link href={item.href} className="rounded hover:text-ink hover:underline">
                                {item.label}
                            </Link>
                        ) : (
                            <span className="text-ink-muted">{item.label}</span>
                        )}
                    </li>
                ))}
            </ol>
        </nav>
    );
}

/** Underlined view switcher. Renders as real links or real buttons. */
export function ViewTabs({ items = [], current, onChange, className = '' }) {
    return (
        <div role="tablist" aria-label="Views" className={cx('-mb-px flex gap-1 overflow-x-auto', className)}>
            {items.map((item) => {
                const active = item.key === current;
                const shared = cx(
                    'relative flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm font-semibold transition-colors',
                    active
                        ? 'border-brand-600 text-brand-700'
                        : 'border-transparent text-ink-muted hover:border-line-strong hover:text-ink',
                    item.disabled && 'pointer-events-none opacity-50',
                );

                const content = (
                    <>
                        {item.label}
                        {item.count != null && (
                            <span className="rounded-full bg-surface-sunken px-1.5 text-xs font-semibold text-ink-muted">{item.count}</span>
                        )}
                        {item.badge}
                    </>
                );

                return item.href ? (
                    <Link key={item.key} href={item.href} role="tab" aria-selected={active} className={shared} preserveScroll>
                        {content}
                    </Link>
                ) : (
                    <button key={item.key} type="button" role="tab" aria-selected={active} onClick={() => onChange?.(item.key)} className={shared}>
                        {content}
                    </button>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------ empty/error */

export function EmptyState({ icon = 'inbox', title, description, action, className = '' }) {
    return (
        <div className={cx('flex flex-col items-center px-6 py-10 text-center', className)}>
            <span className="flex h-10 w-10 items-center justify-center rounded-full bg-surface-sunken text-ink-subtle ring-1 ring-line">
                <Icon name={icon} className="h-5 w-5" />
            </span>
            <p className="mt-3 text-sm font-semibold text-ink">{title}</p>
            {description && <p className="mx-auto mt-1.5 max-w-md text-sm leading-6 text-ink-subtle">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

export function ErrorState({ title = 'Something went wrong', description, action }) {
    return (
        <div className="flex flex-col items-center px-6 py-10 text-center">
            <span className="flex h-10 w-10 items-center justify-center rounded-full bg-urgent-soft text-urgent-ink">
                <Icon name="alert" className="h-5 w-5" />
            </span>
            <p className="mt-3 text-sm font-semibold text-ink">{title}</p>
            {description && <p className="mx-auto mt-1.5 max-w-md text-sm leading-6 text-ink-subtle">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

export function Skeleton({ className = '' }) {
    return <div aria-hidden="true" className={cx('animate-pulse rounded bg-surface-sunken', className)} />;
}

export function LoadingState({ label = 'Loading', rows = 3 }) {
    return (
        <div className="space-y-2 p-4" role="status" aria-live="polite">
            <span className="sr-only">{label}</span>
            {Array.from({ length: rows }).map((_, index) => (
                <div key={index} className="flex items-center gap-3">
                    <Skeleton className="h-4 w-4 rounded-full" />
                    <Skeleton className="h-3.5 flex-1" />
                    <Skeleton className="h-3.5 w-16" />
                </div>
            ))}
        </div>
    );
}

/* --------------------------------------------------------------- overlays */

export function Drawer({ open, onClose, title, description, children, footer, width = 'max-w-xl' }) {
    return (
        <Transition show={!!open} as={Fragment}>
            <Dialog onClose={onClose} className="relative z-drawer">
                <Transition.Child
                    as={Fragment}
                    enter="ease-out duration-200"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-150"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-ink/25" aria-hidden="true" />
                </Transition.Child>

                <div className="fixed inset-0 flex justify-end">
                    <Transition.Child
                        as={Fragment}
                        enter="transform transition ease-out duration-200"
                        enterFrom="translate-y-full sm:translate-y-0 sm:translate-x-full"
                        enterTo="translate-y-0 sm:translate-x-0"
                        leave="transform transition ease-in duration-150"
                        leaveFrom="translate-y-0 sm:translate-x-0"
                        leaveTo="translate-y-full sm:translate-y-0 sm:translate-x-full"
                    >
                        {/* Full-height sheet on mobile, side drawer from sm upwards. */}
                        <Dialog.Panel
                            className={cx(
                                'flex w-full flex-col bg-surface shadow-overlay',
                                'mt-16 rounded-t-panel sm:mt-0 sm:rounded-none',
                                'sm:h-full',
                                width,
                            )}
                        >
                            <div className="flex items-start justify-between gap-3 border-b border-line px-4 py-3 sm:px-5">
                                <div className="min-w-0">
                                    <Dialog.Title className="truncate text-base font-bold text-ink">{title}</Dialog.Title>
                                    {description && <Dialog.Description className="mt-0.5 text-sm text-ink-subtle">{description}</Dialog.Description>}
                                </div>
                                <IconButton icon="close" label="Close panel" onClick={onClose} />
                            </div>
                            <div className="premium-scrollbar min-h-0 flex-1 overflow-y-auto">{children}</div>
                            {footer && <div className="border-t border-line bg-surface-sunken px-4 py-3 sm:px-5">{footer}</div>}
                        </Dialog.Panel>
                    </Transition.Child>
                </div>
            </Dialog>
        </Transition>
    );
}

export function Modal({ open, onClose, title, description, children, footer, width = 'max-w-lg', initialFocus }) {
    return (
        <Transition show={!!open} as={Fragment}>
            <Dialog onClose={onClose} initialFocus={initialFocus} className="relative z-dialog">
                <Transition.Child
                    as={Fragment}
                    enter="ease-out duration-150"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-100"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-ink/30" aria-hidden="true" />
                </Transition.Child>

                <div className="fixed inset-0 overflow-y-auto p-4 sm:p-6">
                    <div className="flex min-h-full items-start justify-center sm:items-center">
                        <Transition.Child
                            as={Fragment}
                            enter="ease-out duration-150"
                            enterFrom="opacity-0 translate-y-2 sm:scale-95"
                            enterTo="opacity-100 translate-y-0 sm:scale-100"
                            leave="ease-in duration-100"
                            leaveFrom="opacity-100 translate-y-0 sm:scale-100"
                            leaveTo="opacity-0 translate-y-2 sm:scale-95"
                        >
                            <Dialog.Panel className={cx('w-full rounded-panel bg-surface shadow-overlay', width)}>
                                <div className="flex items-start justify-between gap-3 border-b border-line px-5 py-3.5">
                                    <div className="min-w-0">
                                        <Dialog.Title className="text-base font-bold text-ink">{title}</Dialog.Title>
                                        {description && <Dialog.Description className="mt-0.5 text-sm text-ink-subtle">{description}</Dialog.Description>}
                                    </div>
                                    <IconButton icon="close" label="Close dialog" onClick={onClose} />
                                </div>
                                <div className="px-5 py-4">{children}</div>
                                {footer && <div className="flex flex-wrap justify-end gap-2 border-t border-line bg-surface-sunken px-5 py-3">{footer}</div>}
                            </Dialog.Panel>
                        </Transition.Child>
                    </div>
                </div>
            </Dialog>
        </Transition>
    );
}

export function ConfirmationDialog({ open, onClose, onConfirm, title, description, confirmLabel = 'Confirm', destructive = false, processing = false }) {
    return (
        <Modal
            open={open}
            onClose={onClose}
            title={title}
            description={description}
            width="max-w-md"
            footer={
                <>
                    <Button onClick={onClose} disabled={processing}>
                        Cancel
                    </Button>
                    <Button variant={destructive ? 'danger' : 'primary'} onClick={onConfirm} disabled={processing}>
                        {processing ? 'Working…' : confirmLabel}
                    </Button>
                </>
            }
        >
            <p className="text-sm leading-6 text-ink-muted">{description}</p>
        </Modal>
    );
}

/** Keyboard-accessible overflow menu. Items are real buttons or links. */
export function OverflowMenu({ items = [], label = 'More actions', align = 'right', trigger }) {
    const usable = items.filter(Boolean);

    if (!usable.length) return null;

    return (
        <Menu as="div" className="relative inline-block text-left">
            <Menu.Button as={Fragment}>
                {trigger ?? (
                    <button type="button" aria-label={label} title={label} className={buttonClass({ variant: 'ghost', size: 'icon' })}>
                        <Icon name="dots" className="h-4 w-4" />
                    </button>
                )}
            </Menu.Button>
            <Transition
                as={Fragment}
                enter="transition ease-out duration-100"
                enterFrom="opacity-0 scale-95"
                enterTo="opacity-100 scale-100"
                leave="transition ease-in duration-75"
                leaveFrom="opacity-100 scale-100"
                leaveTo="opacity-0 scale-95"
            >
                <Menu.Items
                    className={cx(
                        'absolute z-dialog mt-1 w-56 origin-top overflow-hidden rounded-panel border border-line bg-surface py-1 shadow-overlay focus:outline-none',
                        align === 'right' ? 'right-0' : 'left-0',
                    )}
                >
                    {usable.map((item, index) =>
                        item.separator ? (
                            <div key={`sep-${index}`} className="my-1 border-t border-line" role="separator" />
                        ) : (
                            <Menu.Item key={item.label} disabled={item.disabled}>
                                {({ active }) => {
                                    const cls = cx(
                                        'flex w-full items-center gap-2 px-3 py-2 text-left text-sm',
                                        item.destructive ? 'text-urgent-ink' : 'text-ink-muted',
                                        active && !item.disabled && (item.destructive ? 'bg-urgent-soft' : 'bg-surface-sunken text-ink'),
                                        item.disabled && 'cursor-not-allowed opacity-50',
                                    );

                                    return item.href ? (
                                        <Link href={item.href} className={cls}>
                                            {item.icon && <Icon name={item.icon} className="h-4 w-4" />}
                                            {item.label}
                                        </Link>
                                    ) : (
                                        <button type="button" onClick={item.onClick} disabled={item.disabled} className={cls}>
                                            {item.icon && <Icon name={item.icon} className="h-4 w-4" />}
                                            {item.label}
                                        </button>
                                    );
                                }}
                            </Menu.Item>
                        ),
                    )}
                </Menu.Items>
            </Transition>
        </Menu>
    );
}

/* ----------------------------------------------------------------- inputs */

export function Field({ label, htmlFor, error, hint, required, children, className = '' }) {
    return (
        <div className={cx('min-w-0', className)}>
            {label && (
                <label htmlFor={htmlFor} className="mb-1 block text-sm font-semibold text-ink">
                    {label}
                    {required && <span className="ml-0.5 text-urgent-ink" aria-hidden="true">*</span>}
                </label>
            )}
            {children}
            {hint && !error && (
                <p id={`${htmlFor}-hint`} className="mt-1 text-xs text-ink-subtle">
                    {hint}
                </p>
            )}
            {error && (
                <p id={`${htmlFor}-error`} role="alert" className="mt-1 flex items-center gap-1 text-xs font-semibold text-urgent-ink">
                    <Icon name="alert" className="h-3 w-3" />
                    {error}
                </p>
            )}
        </div>
    );
}

export const TextField = forwardRef(function TextField({ className = '', error, id, ...props }, ref) {
    return (
        <input
            ref={ref}
            id={id}
            aria-invalid={error ? 'true' : undefined}
            aria-describedby={error ? `${id}-error` : undefined}
            className={cx(fieldClass, 'h-9', className)}
            {...props}
        />
    );
});

export const TextArea = forwardRef(function TextArea({ className = '', error, id, ...props }, ref) {
    return (
        <textarea
            ref={ref}
            id={id}
            aria-invalid={error ? 'true' : undefined}
            aria-describedby={error ? `${id}-error` : undefined}
            className={cx(fieldClass, 'py-2', className)}
            {...props}
        />
    );
});

export const SelectField = forwardRef(function SelectField({ className = '', error, id, children, ...props }, ref) {
    return (
        <select
            ref={ref}
            id={id}
            aria-invalid={error ? 'true' : undefined}
            aria-describedby={error ? `${id}-error` : undefined}
            className={cx(fieldClass, 'h-9 pr-8', className)}
            {...props}
        >
            {children}
        </select>
    );
});

export function SearchInput({ value, onChange, placeholder = 'Search', label = 'Search', className = '', onSubmit, id = 'search' }) {
    return (
        <form
            role="search"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit?.(value);
            }}
            className={cx('relative min-w-0', className)}
        >
            <label htmlFor={id} className="sr-only">
                {label}
            </label>
            <Icon name="search" className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-subtle" />
            <input
                id={id}
                type="search"
                value={value}
                onChange={(event) => onChange?.(event.target.value)}
                placeholder={placeholder}
                className={cx(fieldClass, 'h-9 pl-8')}
            />
        </form>
    );
}

export function FilterBar({ children, className = '' }) {
    return (
        <div className={cx('flex flex-wrap items-center gap-2 border-b border-line bg-surface px-4 py-2.5 sm:px-5', className)}>
            {children}
        </div>
    );
}

/* --------------------------------------------------------------- feedback */

export function Alert({ tone = 'info', title, children, action, onDismiss }) {
    const tones = {
        info: 'border-info/25 bg-info-soft text-info-ink',
        good: 'border-good/25 bg-good-soft text-good-ink',
        warn: 'border-warn/25 bg-warn-soft text-warn-ink',
        urgent: 'border-urgent/25 bg-urgent-soft text-urgent-ink',
    };
    const icons = { info: 'spark', good: 'check', warn: 'alert', urgent: 'alert' };

    return (
        <div role={tone === 'urgent' ? 'alert' : 'status'} className={cx('flex items-start gap-2.5 rounded-panel border px-3.5 py-2.5', tones[tone] ?? tones.info)}>
            <Icon name={icons[tone] ?? 'spark'} className="mt-0.5 h-4 w-4" />
            <div className="min-w-0 flex-1 text-sm">
                {title && <p className="font-semibold">{title}</p>}
                {children && <div className={cx(title && 'mt-0.5', 'leading-6')}>{children}</div>}
            </div>
            {action}
            {onDismiss && <IconButton icon="close" label="Dismiss" size="xs" variant="ghost" onClick={onDismiss} className="-mr-1 -mt-1" />}
        </div>
    );
}
