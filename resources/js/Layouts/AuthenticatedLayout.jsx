import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { primaryButton, secondaryButton } from '@/Components/Ui';

const groups = [
    {
        label: 'Primary',
        items: [
            { name: 'Home', href: route('dashboard'), activeRoutes: ['dashboard'], icon: 'home' },
            { name: 'Inbox', href: route('notifications.index'), activeRoutes: ['notifications.*'], icon: 'inbox' },
            { name: 'My Tasks', href: route('tasks.index'), activeRoutes: ['tasks.*'], icon: 'check' },
            { name: 'Projects', href: route('projects.index'), activeRoutes: ['projects.*'], icon: 'folder' },
            { name: 'Portfolios', href: route('portfolios.index'), activeRoutes: ['portfolios.*', 'areas.*'], icon: 'briefcase' },
            { name: 'Planner', href: route('planner.index'), activeRoutes: ['planner.*', 'calendar.*', 'workload.*'], icon: 'calendar' },
            { name: 'Reports', href: route('reports.index'), activeRoutes: ['reports.*'], icon: 'chart' },
        ],
    },
    {
        label: 'Planning / Review',
        items: [
            { name: 'My Day', href: route('today.index'), activeRoutes: ['today.*'], icon: 'target' },
            { name: 'Task Review', href: route('task-review.index'), activeRoutes: ['task-review.*'], icon: 'list' },
            { name: 'Prioritization', href: route('prioritization-review.index'), activeRoutes: ['prioritization-review.*'], icon: 'sort' },
            { name: 'Waiting For', href: route('waiting.index'), activeRoutes: ['waiting.*'], icon: 'clock' },
            { name: 'Decisions', href: route('decisions.index'), activeRoutes: ['decisions.*'], icon: 'diamond' },
            { name: 'Risks', href: route('risks.index'), activeRoutes: ['risks.*'], icon: 'alert' },
            { name: 'Approvals', href: route('approvals.index'), activeRoutes: ['approvals.*'], icon: 'thumb' },
        ],
    },
    {
        label: 'Personal Foundation',
        items: [
            { name: 'Spiritual', href: route('spiritual.index'), activeRoutes: ['spiritual.*'], icon: 'spark' },
            { name: 'Notes', href: route('notes.index'), activeRoutes: ['notes.*'], icon: 'note' },
        ],
    },
    {
        label: 'Admin / Settings',
        items: [
            { name: 'Workspace Settings', href: route('settings.workspace.edit'), activeRoutes: ['settings.workspace.*'], icon: 'people' },
            { name: 'Automations', href: route('settings.automations.index'), activeRoutes: ['settings.automations.*'], icon: 'spark' },
            { name: 'AI Brain Settings', href: route('settings.ai.edit'), activeRoutes: ['settings.ai.*'], icon: 'settings' },
            { name: 'Custom Fields', href: route('admin.custom-fields.index'), activeRoutes: ['admin.custom-fields.*'], icon: 'sliders' },
            { name: 'Templates', href: route('templates.index'), activeRoutes: ['templates.*'], icon: 'layers' },
        ],
    },
];

const shortcuts = [
    ['Stellantis GCC', route('projects.index', { search: 'Stellantis GCC' }), '#f97316'],
    ['Stellantis South Africa', route('projects.index', { search: 'Stellantis South Africa' }), '#2563eb'],
    ['SayaraForce', route('projects.index', { search: 'SayaraForce' }), '#0ea5e9'],
    ['ChurchForce', route('projects.index', { search: 'ChurchForce' }), '#7c3aed'],
    ["Paul's Photography", route('projects.index', { search: "Paul's Photography" }), '#ec4899'],
    ['UAE Realtor Agents App', route('projects.index', { search: 'UAE Realtor Agents App' }), '#10b981'],
    ['Career Growth', route('portfolios.index'), '#64748b'],
    ['Spirituality', route('spiritual.index'), '#f59e0b'],
];

function NavIcon({ type }) {
    const common = 'block h-4 w-4';

    return (
        <span className="relative flex h-5 w-5 shrink-0 items-center justify-center text-current">
            {['home', 'inbox', 'folder', 'briefcase', 'calendar', 'note'].includes(type) && <span className={`${common} rounded-sm border border-current`} />}
            {type === 'check' && <span className="h-3 w-4 rotate-[-35deg] border-b-2 border-l-2 border-current" />}
            {type === 'chart' && <span className="flex items-end gap-0.5">{[6, 10, 14].map((h) => <span key={h} className="w-1 rounded-sm bg-current" style={{ height: `${h}px` }} />)}</span>}
            {type === 'people' && <span className={`${common} rounded-full border border-current before:absolute before:left-1 before:top-1 before:h-2 before:w-2 before:rounded-full before:bg-current after:absolute after:right-1 after:bottom-1 after:h-1.5 after:w-1.5 after:rounded-full after:bg-current`} />}
            {type === 'target' && <span className={`${common} rounded-full border-2 border-current after:m-1 after:block after:h-1 after:w-1 after:rounded-full after:bg-current`} />}
            {type === 'list' && <span className="space-y-1">{[1, 2, 3].map((i) => <span key={i} className="block h-0.5 w-4 bg-current" />)}</span>}
            {type === 'sort' && <span className={`${common} border-l border-current before:absolute before:left-1 before:top-1 before:h-2 before:w-2 before:border-t before:border-r before:border-current after:absolute after:right-1 after:bottom-1 after:h-2 after:w-2 after:border-b after:border-l after:border-current`} />}
            {type === 'clock' && <span className={`${common} rounded-full border border-current before:absolute before:left-1/2 before:top-1/2 before:h-1.5 before:w-px before:-translate-y-1.5 before:bg-current after:absolute after:left-1/2 after:top-1/2 after:h-px after:w-1.5 after:bg-current`} />}
            {type === 'diamond' && <span className="h-3.5 w-3.5 rotate-45 border border-current" />}
            {type === 'alert' && <span className={`${common} rounded-full border border-current after:absolute after:bottom-1 after:left-1/2 after:h-0.5 after:w-0.5 after:-translate-x-1/2 after:rounded-full after:bg-current before:absolute before:left-1/2 before:top-1 before:h-2 before:w-px before:-translate-x-1/2 before:bg-current`} />}
            {type === 'thumb' && <span className="h-4 w-4 rounded-full border border-current" />}
            {type === 'spark' && <span className="h-4 w-4 rotate-45 border border-current" />}
            {type === 'settings' && <span className={`${common} rounded-full border-2 border-current after:m-1 after:block after:h-1 after:w-1 after:rounded-full after:bg-current`} />}
            {type === 'sliders' && <span className="space-y-1">{[1, 2, 3].map((i) => <span key={i} className="block h-px w-4 bg-current" />)}</span>}
            {type === 'layers' && <span className={`${common} rounded-sm border border-current shadow-[3px_3px_0_currentColor]`} />}
        </span>
    );
}

function SidebarLink({ item, active, onClick }) {
    return (
        <Link
            href={item.href}
            onClick={onClick}
            className={`flex items-center gap-2 rounded-md px-2.5 py-1.5 text-sm font-medium transition ${
                active
                    ? 'bg-rose-50 text-rose-700'
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950'
            }`}
        >
            <NavIcon type={item.icon} />
            <span className="truncate">{item.name}</span>
        </Link>
    );
}

export default function AuthenticatedLayout({ title = 'Home', subtitle, actions, children }) {
    const page = usePage();
    const user = page.props.auth.user;
    const unreadCount = page.props.notifications?.unread_count ?? 0;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [shortcutOpen, setShortcutOpen] = useState(true);
    const [search, setSearch] = useState('');

    const isActive = (patterns = []) => patterns.some((pattern) => route().current(pattern));

    const submitSearch = (event) => {
        event.preventDefault();
        if (search.trim()) {
            router.get(route('tasks.index'), { search: search.trim() });
        }
    };

    useEffect(() => setSidebarOpen(false), [page.url]);

    return (
        <div className="min-h-screen bg-slate-50 text-slate-950">
            {sidebarOpen && <button type="button" aria-label="Close navigation" className="fixed inset-0 z-30 bg-slate-950/30 lg:hidden" onClick={() => setSidebarOpen(false)} />}

            <aside className={`fixed inset-y-0 left-0 z-40 flex w-72 max-w-[86vw] flex-col border-r border-slate-200 bg-white transition-transform duration-200 lg:translate-x-0 ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                <div className="border-b border-slate-200 px-3 py-3">
                    <div className="flex items-center gap-2">
                        <ApplicationLogo />
                        <div className="min-w-0">
                            <div className="truncate text-sm font-semibold text-slate-950">Miriam</div>
                            <div className="truncate text-xs text-slate-500">Friday workspace</div>
                        </div>
                    </div>
                    <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                        <div className="text-xs font-semibold text-slate-500">Workspace</div>
                        <div className="mt-0.5 truncate text-sm font-semibold text-slate-900">Miriam Workspace</div>
                    </div>
                </div>

                <nav className="premium-scrollbar flex-1 overflow-y-auto px-3 py-3">
                    {groups.map((group) => (
                        <div key={group.label} className="mb-4">
                            <div className="px-2 pb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{group.label}</div>
                            <div className="space-y-0.5">
                                {group.items.map((item) => <SidebarLink key={item.name} item={item} active={isActive(item.activeRoutes)} onClick={() => setSidebarOpen(false)} />)}
                            </div>
                        </div>
                    ))}

                    <div className="mb-4">
                        <button type="button" onClick={() => setShortcutOpen(!shortcutOpen)} className="flex w-full items-center justify-between px-2 pb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                            Workstreams
                            <span>{shortcutOpen ? '-' : '+'}</span>
                        </button>
                        {shortcutOpen && (
                            <div className="space-y-0.5">
                                {shortcuts.map(([name, href, color]) => (
                                    <Link key={name} href={href} className="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-950">
                                        <span className="h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: color }} />
                                        <span className="truncate">{name}</span>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>
                </nav>
            </aside>

            <div className="lg:pl-72">
                <header className="sticky top-0 z-30 border-b border-slate-800 bg-slate-950 text-white">
                    <div className="flex h-14 items-center gap-3 px-3 sm:px-4">
                        <button type="button" className="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-300 hover:bg-slate-800 hover:text-white lg:hidden" onClick={() => setSidebarOpen(true)}>
                            <span className="sr-only">Open navigation</span>
                            <span className="h-4 w-5 border-y-2 border-current" />
                        </button>
                        <Link href={route('dashboard')} className="hidden text-sm font-semibold text-white sm:block">Miriam / Friday</Link>

                        <form onSubmit={submitSearch} className="min-w-0 flex-1">
                            <input
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Search tasks"
                                className="h-9 w-full max-w-2xl rounded-md border border-slate-700 bg-slate-900 px-3 text-sm text-white placeholder:text-slate-400 focus:border-slate-500 focus:ring-slate-500"
                            />
                        </form>

                        {actions}
                        <Link href={route('tasks.create')} className={`${primaryButton} hidden sm:inline-flex`}>+ Create</Link>
                        <Link href={route('notifications.index')} className="relative inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-300 hover:bg-slate-800 hover:text-white">
                            <span className="h-4 w-4 rounded-sm border border-current" />
                            {unreadCount > 0 && <span className="absolute -right-1 -top-1 min-w-4 rounded-full bg-rose-500 px-1 text-center text-[10px] font-bold text-white">{unreadCount}</span>}
                        </Link>

                        <Dropdown>
                            <Dropdown.Trigger>
                                <button type="button" className="flex h-8 items-center gap-2 rounded-md px-1.5 text-sm font-semibold text-slate-200 hover:bg-slate-800 hover:text-white">
                                    <span className="flex h-7 w-7 items-center justify-center rounded-full bg-slate-700 text-xs font-bold text-white">{user.name.charAt(0).toUpperCase()}</span>
                                    <span className="hidden max-w-28 truncate md:block">{user.name}</span>
                                </button>
                            </Dropdown.Trigger>
                            <Dropdown.Content contentClasses="py-1 bg-white">
                                <Dropdown.Link href={route('profile.edit')}>Profile</Dropdown.Link>
                                <Dropdown.Link href={route('logout')} method="post" as="button">Log Out</Dropdown.Link>
                            </Dropdown.Content>
                        </Dropdown>
                    </div>
                </header>

                <main className="px-4 py-5 sm:px-6 lg:px-8">
                    <div className="mb-5">
                        <h1 className="text-2xl font-semibold tracking-tight text-slate-950">{title}</h1>
                        {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
                    </div>
                    {children}
                </main>
            </div>
        </div>
    );
}
