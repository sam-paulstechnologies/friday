import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Icon, OverflowMenu, cx, fieldClass } from '@/Components/Kit';

export default function TopBar({ onOpenMobileNav, onOpenQuickAdd }) {
    const page = usePage();
    const user = page.props.auth?.user;
    const notifications = page.props.notifications?.unread_count ?? 0;
    const [search, setSearch] = useState('');

    const submitSearch = (event) => {
        event.preventDefault();

        if (search.trim()) {
            // Search is scoped to tasks because that is what the backend
            // actually indexes. It does not pretend to be global.
            router.get(route('tasks.index'), { search: search.trim(), view: 'all' });
        }
    };

    return (
        <header className="sticky top-0 z-topbar h-topbar border-b border-line bg-surface/95 backdrop-blur">
            <div className="flex h-full items-center gap-2 px-3 sm:px-4">
                <button
                    type="button"
                    onClick={onOpenMobileNav}
                    aria-label="Open navigation"
                    className="inline-flex h-9 w-9 items-center justify-center rounded-control text-ink-muted hover:bg-surface-sunken hover:text-ink lg:hidden"
                >
                    <Icon name="menu" className="h-5 w-5" />
                </button>

                <form role="search" onSubmit={submitSearch} className="relative min-w-0 flex-1 sm:max-w-md">
                    <label htmlFor="topbar-search" className="sr-only">
                        Search tasks
                    </label>
                    <Icon name="search" className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-subtle" />
                    <input
                        id="topbar-search"
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search tasks"
                        className={cx(fieldClass, 'h-9 pl-8')}
                    />
                </form>

                <div className="ml-auto flex items-center gap-1.5">
                    <Button variant="primary" size="md" onClick={onOpenQuickAdd} className="gap-1.5">
                        <Icon name="plus" className="h-4 w-4" />
                        <span className="hidden sm:inline">Quick Add</span>
                        <span className="sr-only sm:hidden">Quick Add</span>
                    </Button>

                    <Link
                        href={route('notifications.index')}
                        aria-label={notifications > 0 ? `Notifications, ${notifications} unread` : 'Notifications'}
                        className="relative inline-flex h-9 w-9 items-center justify-center rounded-control text-ink-muted hover:bg-surface-sunken hover:text-ink"
                    >
                        <Icon name="bell" className="h-4.5 w-4.5" />
                        {notifications > 0 && (
                            <span className="absolute right-1 top-1 min-w-4 rounded-full bg-urgent px-1 text-center text-micro font-bold leading-4 text-ink-inverse">
                                {notifications > 99 ? '99+' : notifications}
                            </span>
                        )}
                    </Link>

                    <OverflowMenu
                        label="Account menu"
                        trigger={
                            <button
                                type="button"
                                aria-label="Account menu"
                                className="inline-flex h-9 items-center gap-2 rounded-control px-1.5 text-sm font-semibold text-ink-muted hover:bg-surface-sunken hover:text-ink"
                            >
                                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-ink text-xs font-bold text-ink-inverse">
                                    {(user?.name ?? '?').charAt(0).toUpperCase()}
                                </span>
                                <span className="hidden max-w-28 truncate md:block">{user?.name}</span>
                            </button>
                        }
                        items={[
                            { label: 'Profile', icon: 'user', href: route('profile.edit') },
                            { label: 'Workspace settings', icon: 'settings', href: route('settings.workspace.edit') },
                            { separator: true },
                            { label: 'Log out', icon: 'external', onClick: () => router.post(route('logout')) },
                        ]}
                    />
                </div>
            </div>
        </header>
    );
}
