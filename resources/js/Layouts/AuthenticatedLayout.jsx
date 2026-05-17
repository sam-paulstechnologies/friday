import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { primaryButton } from '@/Components/Ui';

const groups = [
    {
        label: 'Main',
        items: [
            { name: 'Dashboard', href: route('dashboard'), active: route().current('dashboard'), icon: 'grid' },
            { name: 'Today', href: route('today.index'), active: route().current('today.*'), icon: 'target' },
            { name: 'Areas', href: route('areas.index'), active: route().current('areas.*'), icon: 'layers' },
            { name: 'Portfolios', href: route('portfolios.index'), active: route().current('portfolios.*'), icon: 'briefcase' },
            { name: 'My Tasks', href: route('tasks.index'), active: route().current('tasks.*'), icon: 'check' },
            { name: 'Projects', href: route('projects.index'), active: route().current('projects.*'), icon: 'folder' },
            { name: 'Calendar', href: route('calendar.index'), active: route().current('calendar.*'), icon: 'calendar' },
        ],
    },
    {
        label: 'Planning',
        items: [
            { name: 'Templates', href: route('templates.index'), active: route().current('templates.*'), icon: 'layers' },
            { name: 'Timeline', href: route('projects.index'), active: route().current('projects.timeline'), icon: 'timeline' },
        ],
    },
    {
        label: 'Management',
        items: [
            { name: 'Waiting For', href: route('waiting.index'), active: route().current('waiting.*'), icon: 'timeline' },
            { name: 'Decisions', href: route('decisions.index'), active: route().current('decisions.*'), icon: 'target' },
            { name: 'Blockers', href: route('blockers.index'), active: route().current('blockers.*'), icon: 'chart' },
            { name: 'Risks', href: route('risks.index'), active: route().current('risks.*'), icon: 'chart' },
            { name: 'Approvals', href: route('approvals.index'), active: route().current('approvals.*'), icon: 'check' },
            { name: 'Reports', href: '#', icon: 'chart' },
            { name: 'Goals', href: '#', icon: 'target' },
        ],
    },
    {
        label: 'Admin',
        items: [
            { name: 'Custom Fields', href: route('admin.custom-fields.index'), active: route().current('admin.*'), icon: 'settings' },
        ],
    },
];

function NavIcon({ type, active }) {
    const base = active ? 'border-white/20 bg-white/15' : 'border-slate-200 bg-white group-hover:border-slate-300';

    return (
        <span className={`relative flex h-8 w-8 shrink-0 items-center justify-center rounded-xl border ${base}`}>
            {type === 'grid' && <span className="grid grid-cols-2 gap-0.5">{[1, 2, 3, 4].map((i) => <span key={i} className={`h-1.5 w-1.5 rounded-sm ${active ? 'bg-white' : 'bg-slate-500'}`} />)}</span>}
            {type === 'check' && <span className={`h-3 w-5 rotate-[-35deg] border-b-2 border-l-2 ${active ? 'border-white' : 'border-slate-500'}`} />}
            {type === 'folder' && <span className={`h-4 w-5 rounded-sm border ${active ? 'border-white bg-white/10' : 'border-slate-500 bg-slate-100'}`} />}
            {type === 'calendar' && <span className={`h-5 w-5 rounded border ${active ? 'border-white' : 'border-slate-500'} before:block before:h-1 before:border-b before:border-current`} />}
            {type === 'layers' && <span className={`h-4 w-5 rounded border ${active ? 'border-white' : 'border-slate-500'} shadow-[3px_3px_0_currentColor]`} />}
            {type === 'timeline' && <span className={`h-0.5 w-5 ${active ? 'bg-white' : 'bg-slate-500'} before:absolute before:left-2 before:top-2 before:h-2 before:w-2 before:rounded-full before:bg-current after:absolute after:right-2 after:top-4 after:h-2 after:w-2 after:rounded-full after:bg-current`} />}
            {type === 'chart' && <span className="flex items-end gap-0.5">{[2, 4, 6].map((h) => <span key={h} className={`${active ? 'bg-white' : 'bg-slate-500'} w-1 rounded-sm`} style={{ height: `${h}px` }} />)}</span>}
            {type === 'target' && <span className={`h-5 w-5 rounded-full border-2 ${active ? 'border-white' : 'border-slate-500'} after:m-1 after:block after:h-1.5 after:w-1.5 after:rounded-full after:bg-current`} />}
            {type === 'briefcase' && <span className={`h-4 w-5 rounded border ${active ? 'border-white' : 'border-slate-500'} before:absolute before:top-2 before:h-1 before:w-2 before:rounded before:bg-current`} />}
            {type === 'settings' && <span className={`h-5 w-5 rounded-full border-2 ${active ? 'border-white' : 'border-slate-500'} after:m-1 after:block after:h-1.5 after:w-1.5 after:rounded-full after:bg-current`} />}
        </span>
    );
}

function SidebarLink({ item, onClick }) {
    return (
        <Link
            href={item.href}
            onClick={onClick}
            className={`group flex items-center gap-3 rounded-2xl px-3 py-2.5 text-sm font-semibold transition ${
                item.active
                    ? 'bg-slate-950 text-white shadow-md shadow-slate-300/60'
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950'
            }`}
        >
            <NavIcon type={item.icon} active={item.active} />
            <span className="truncate">{item.name}</span>
        </Link>
    );
}

export default function AuthenticatedLayout({ title = 'Dashboard', subtitle, children }) {
    const page = usePage();
    const user = page.props.auth.user;
    const unreadCount = page.props.notifications?.unread_count ?? 0;
    const [sidebarOpen, setSidebarOpen] = useState(false);

    useEffect(() => {
        setSidebarOpen(false);
    }, [page.url]);

    useEffect(() => {
        const closeOnDesktop = () => {
            if (window.matchMedia('(min-width: 1024px)').matches) {
                setSidebarOpen(false);
            }
        };

        closeOnDesktop();
        window.addEventListener('resize', closeOnDesktop);

        return () => window.removeEventListener('resize', closeOnDesktop);
    }, []);

    return (
        <div className="min-h-screen bg-[#f7f8fb] text-slate-950">
            {sidebarOpen && (
                <button
                    type="button"
                    aria-label="Close navigation"
                    className="fixed inset-0 z-30 bg-slate-950/30 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            <aside className={`fixed inset-y-0 left-0 z-40 flex w-72 max-w-[82vw] flex-col border-r border-slate-200 bg-white transition-transform lg:w-80 lg:translate-x-0 ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                <div className="border-b border-slate-200 p-4">
                    <div className="flex items-center gap-3">
                        <ApplicationLogo />
                        <div className="min-w-0">
                            <div className="truncate text-sm font-bold text-slate-950">Friday</div>
                            <div className="truncate text-xs font-medium text-slate-500">Work operating system</div>
                        </div>
                    </div>
                    <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                        <div className="flex items-center justify-between gap-3">
                            <div className="min-w-0">
                                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Workspace</div>
                                <div className="mt-1 truncate text-sm font-bold text-slate-950">Friday Workspace</div>
                            </div>
                            <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-white text-xs font-bold text-slate-600 ring-1 ring-slate-200">F</span>
                        </div>
                    </div>
                </div>

                <nav className="flex-1 overflow-y-auto px-3 py-4">
                    {groups.map((group) => (
                        <div key={group.label} className="mb-5">
                            <div className="px-3 pb-2 text-[11px] font-bold uppercase tracking-[0.16em] text-slate-400">
                                {group.label}
                            </div>
                            <div className="space-y-1">
                                {group.items.map((item) => (
                                    <SidebarLink key={item.name} item={item} onClick={() => setSidebarOpen(false)} />
                                ))}
                            </div>
                        </div>
                    ))}
                </nav>

                <div className="border-t border-slate-200 p-4">
                    <Link href={route('tasks.create')} className={`${primaryButton} w-full`}>
                        New Task
                    </Link>
                </div>
            </aside>

            <div className="lg:pl-80">
                <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
                    <div className="flex min-h-20 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                        <div className="flex min-w-0 items-center gap-3">
                            <button
                                type="button"
                                className="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 shadow-sm lg:hidden"
                                onClick={() => setSidebarOpen(true)}
                            >
                                <span className="sr-only">Open navigation</span>
                                <span className="h-4 w-5 border-y-2 border-slate-600" />
                            </button>
                            <div className="min-w-0">
                                <h1 className="truncate text-xl font-bold tracking-tight text-slate-950 md:text-2xl">{title}</h1>
                                {subtitle && <p className="mt-1 truncate text-sm text-slate-500">{subtitle}</p>}
                            </div>
                        </div>

                        <div className="flex items-center gap-2 sm:gap-3">
                            <Link href={route('projects.create')} className="hidden rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 md:inline-flex">
                                New Project
                            </Link>
                            <Link href={route('notifications.index')} className="relative inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:bg-slate-50">
                                <span className="h-5 w-4 rounded-t-full border-2 border-slate-500 border-b-0 after:mx-auto after:mt-4 after:block after:h-1 after:w-1 after:rounded-full after:bg-slate-500" />
                                {unreadCount > 0 && (
                                    <span className="absolute -right-1 -top-1 min-w-5 rounded-full bg-rose-600 px-1.5 py-0.5 text-center text-[11px] font-bold text-white">{unreadCount}</span>
                                )}
                            </Link>

                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button type="button" className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-2 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                                        <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-100 text-xs font-bold text-emerald-800 ring-1 ring-emerald-200">
                                            {user.name.charAt(0).toUpperCase()}
                                        </span>
                                        <span className="hidden max-w-32 truncate sm:block">{user.name}</span>
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content contentClasses="py-1 bg-white">
                                    <Dropdown.Link href={route('profile.edit')}>Profile</Dropdown.Link>
                                    <Dropdown.Link href={route('logout')} method="post" as="button">Log Out</Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>
                    </div>
                </header>

                <main className="px-4 py-6 sm:px-6 lg:px-8">{children}</main>
            </div>
        </div>
    );
}
