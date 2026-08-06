import { Dialog, Transition } from '@headlessui/react';
import { Link, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useMemo, useState } from 'react';
import { Alert, Icon, Modal, cx } from '@/Components/Kit';
import QuickCapture from '@/Components/Shell/QuickCapture';
import Sidebar from '@/Components/Shell/Sidebar';
import TopBar from '@/Components/Shell/TopBar';
import { mobilePrimary, navigationGroups } from '@/Components/Shell/navigation';

const COLLAPSE_KEY = 'miriam.sidebar.collapsed';

function hasRoute(name) {
    try {
        return route().has(name);
    } catch {
        return false;
    }
}

/** Phone bottom bar: the four daily-loop destinations plus capture. */
function MobileTabBar({ onOpenQuickAdd }) {
    const items = useMemo(
        () =>
            navigationGroups
                .flatMap((group) => group.items)
                .filter((item) => mobilePrimary.includes(item.key) && hasRoute(item.route)),
        [],
    );

    const isCurrent = (patterns = []) => {
        try {
            return patterns.some((pattern) => route().current(pattern));
        } catch {
            return false;
        }
    };

    return (
        <nav
            aria-label="Primary"
            className="fixed inset-x-0 bottom-0 z-topbar border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] lg:hidden"
        >
            <ul className="flex items-stretch">
                {items.slice(0, 2).map((item) => (
                    <MobileTab key={item.key} item={item} active={isCurrent(item.match)} />
                ))}
                <li className="flex flex-1 items-center justify-center">
                    <button
                        type="button"
                        onClick={onOpenQuickAdd}
                        aria-label="Quick Add"
                        className="-mt-4 flex h-12 w-12 items-center justify-center rounded-full bg-brand-600 text-ink-inverse shadow-raised"
                    >
                        <Icon name="plus" className="h-5 w-5" />
                    </button>
                </li>
                {items.slice(2, 4).map((item) => (
                    <MobileTab key={item.key} item={item} active={isCurrent(item.match)} />
                ))}
            </ul>
        </nav>
    );
}

function MobileTab({ item, active }) {
    const page = usePage();
    const count = item.badge === 'inbox' ? (page.props.inbox?.open_count ?? 0) : 0;

    return (
        <li className="flex-1">
            <Link
                href={route(item.route)}
                aria-current={active ? 'page' : undefined}
                className={cx(
                    'relative flex min-h-[3.25rem] flex-col items-center justify-center gap-0.5 px-1 py-1.5 text-micro font-semibold',
                    active ? 'text-brand-700' : 'text-ink-subtle',
                )}
            >
                <Icon name={item.icon} className="h-5 w-5" />
                <span className="truncate">{item.name}</span>
                {count > 0 && (
                    <span className="absolute right-1/4 top-1 min-w-4 rounded-full bg-brand-600 px-1 text-center text-micro font-bold leading-4 text-ink-inverse">
                        {count > 9 ? '9+' : count}
                    </span>
                )}
            </Link>
        </li>
    );
}

/**
 * The authenticated application shell.
 *
 * Persistent collapsible sidebar on desktop, a navigation drawer plus bottom
 * tab bar on phones, one compact top bar, and one globally available Quick Add.
 */
export default function AppShell({ title, subtitle, actions, meta, tabs, breadcrumbs, header, children, fullBleed = false }) {
    const page = usePage();
    const flash = page.props.flash ?? {};
    const [collapsed, setCollapsed] = useState(false);
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [quickAddOpen, setQuickAddOpen] = useState(false);
    const [dismissedFlash, setDismissedFlash] = useState(null);

    useEffect(() => {
        try {
            setCollapsed(window.localStorage.getItem(COLLAPSE_KEY) === '1');
        } catch {
            /* storage unavailable — stay expanded */
        }
    }, []);

    const toggleCollapse = () => {
        setCollapsed((value) => {
            const next = !value;
            try {
                window.localStorage.setItem(COLLAPSE_KEY, next ? '1' : '0');
            } catch {
                /* ignore */
            }
            return next;
        });
    };

    // Close transient UI on navigation.
    useEffect(() => {
        setDrawerOpen(false);
        setQuickAddOpen(false);
        setDismissedFlash(null);
    }, [page.url]);

    // Global keyboard shortcuts: `c` to capture, `/` to search.
    useEffect(() => {
        const handler = (event) => {
            const target = event.target;
            const typing = target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

            if (typing || event.metaKey || event.ctrlKey || event.altKey) return;

            if (event.key === 'c') {
                event.preventDefault();
                setQuickAddOpen(true);
            }

            if (event.key === '/') {
                event.preventDefault();
                document.getElementById('topbar-search')?.focus();
            }
        };

        window.addEventListener('keydown', handler);

        return () => window.removeEventListener('keydown', handler);
    }, []);

    const showFlash = (flash.success || flash.error) && dismissedFlash !== page.url;

    return (
        <div className="min-h-screen bg-canvas">
            <a href="#main-content" className="skip-link">
                Skip to main content
            </a>

            {/* Desktop sidebar */}
            <aside
                className={cx(
                    'fixed inset-y-0 left-0 z-sidebar hidden border-r border-line lg:block',
                    collapsed ? 'w-sidebar-rail' : 'w-sidebar',
                )}
            >
                <Sidebar collapsed={collapsed} onToggleCollapse={toggleCollapse} />
            </aside>

            {/* Mobile navigation drawer */}
            <Transition show={drawerOpen} as={Fragment}>
                <Dialog onClose={setDrawerOpen} className="relative z-drawer lg:hidden">
                    <Transition.Child
                        as={Fragment}
                        enter="ease-out duration-200"
                        enterFrom="opacity-0"
                        enterTo="opacity-100"
                        leave="ease-in duration-150"
                        leaveFrom="opacity-100"
                        leaveTo="opacity-0"
                    >
                        <div className="fixed inset-0 bg-ink/30" aria-hidden="true" />
                    </Transition.Child>
                    <div className="fixed inset-0 flex">
                        <Transition.Child
                            as={Fragment}
                            enter="transform transition ease-out duration-200"
                            enterFrom="-translate-x-full"
                            enterTo="translate-x-0"
                            leave="transform transition ease-in duration-150"
                            leaveFrom="translate-x-0"
                            leaveTo="-translate-x-full"
                        >
                            <Dialog.Panel className="flex w-[17rem] max-w-[85vw] flex-col shadow-overlay">
                                <Dialog.Title className="sr-only">Navigation</Dialog.Title>
                                <Sidebar inDrawer onNavigate={() => setDrawerOpen(false)} />
                            </Dialog.Panel>
                        </Transition.Child>
                    </div>
                </Dialog>
            </Transition>

            <div className={cx('flex min-h-screen flex-col', collapsed ? 'lg:pl-sidebar-rail' : 'lg:pl-sidebar')}>
                <TopBar onOpenMobileNav={() => setDrawerOpen(true)} onOpenQuickAdd={() => setQuickAddOpen(true)} />

                {header ?? (
                    <PageHeaderRegion
                        title={title}
                        subtitle={subtitle}
                        meta={meta}
                        actions={actions}
                        tabs={tabs}
                        breadcrumbs={breadcrumbs}
                    />
                )}

                <main
                    id="main-content"
                    tabIndex={-1}
                    className={cx('flex-1 pb-20 lg:pb-8', fullBleed ? '' : 'mx-auto w-full max-w-[1400px] px-4 py-5 sm:px-6')}
                >
                    {showFlash && (
                        <div className={cx('mb-4', fullBleed && 'mx-auto max-w-[1400px] px-4 pt-4 sm:px-6')}>
                            <Alert
                                tone={flash.error ? 'urgent' : 'good'}
                                onDismiss={() => setDismissedFlash(page.url)}
                            >
                                {flash.error || flash.success}
                            </Alert>
                        </div>
                    )}
                    {children}
                </main>
            </div>

            <MobileTabBar onOpenQuickAdd={() => setQuickAddOpen(true)} />

            <Modal
                open={quickAddOpen}
                onClose={() => setQuickAddOpen(false)}
                title="Quick Add"
                description="Get it out of your head. You can sort it out later."
                width="max-w-2xl"
            >
                <QuickCapture autoFocus onCaptured={() => undefined} />
            </Modal>
        </div>
    );
}

function PageHeaderRegion({ title, subtitle, meta, actions, tabs, breadcrumbs }) {
    if (!title) return null;

    return (
        <div className="border-b border-line bg-surface">
            <div className="mx-auto w-full max-w-[1400px] px-4 pt-4 sm:px-6">
                {breadcrumbs?.length > 0 && (
                    <nav aria-label="Breadcrumb" className="pb-1">
                        <ol className="flex flex-wrap items-center gap-1 text-xs text-ink-subtle">
                            {breadcrumbs.map((item, index) => (
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
                )}
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
        </div>
    );
}
