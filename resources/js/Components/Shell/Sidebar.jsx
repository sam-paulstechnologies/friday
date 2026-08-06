import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Icon, PreviewBadge, cx } from '@/Components/Kit';
import { resolveNavigation } from './navigation';

function hasRoute(name) {
    try {
        return route().has(name);
    } catch {
        return false;
    }
}

function isCurrent(patterns = []) {
    try {
        return patterns.some((pattern) => route().current(pattern));
    } catch {
        return false;
    }
}

function SidebarItem({ item, collapsed, badgeCount, onNavigate }) {
    const active = isCurrent(item.match);
    const href = route(item.route);

    return (
        <li>
            <Link
                href={href}
                onClick={onNavigate}
                aria-current={active ? 'page' : undefined}
                title={collapsed ? item.name : undefined}
                className={cx(
                    'group relative flex items-center gap-2.5 rounded-control px-2 py-1.5 text-sm font-medium transition-colors',
                    collapsed && 'justify-center px-0',
                    active ? 'bg-brand-50 text-brand-700' : 'text-ink-muted hover:bg-surface-sunken hover:text-ink',
                )}
            >
                {/* Active state is carried by a bar and a label, not colour alone. */}
                {active && !collapsed && (
                    <span aria-hidden="true" className="absolute inset-y-1 left-0 w-0.5 rounded-full bg-brand-600" />
                )}
                <Icon name={item.icon} className={cx('h-4 w-4', active && 'text-brand-600')} />
                {!collapsed && <span className="min-w-0 flex-1 truncate">{item.name}</span>}
                {!collapsed && badgeCount > 0 && (
                    <span className="rounded-full bg-brand-600 px-1.5 text-micro font-bold text-ink-inverse">{badgeCount}</span>
                )}
                {!collapsed && item.availability && <PreviewBadge state={item.availability} />}
                {collapsed && badgeCount > 0 && (
                    <span aria-hidden="true" className="absolute right-1.5 top-1.5 h-1.5 w-1.5 rounded-full bg-brand-600" />
                )}
                {collapsed && <span className="sr-only">{item.name}</span>}
            </Link>
        </li>
    );
}

function SidebarGroup({ group, collapsed, badges, onNavigate }) {
    const containsActive = group.items.some((item) => isCurrent(item.match));
    const [open, setOpen] = useState(!group.collapsible || containsActive);
    const expanded = !group.collapsible || open || containsActive;

    return (
        <div className="mb-4">
            {!collapsed &&
                (group.collapsible ? (
                    <button
                        type="button"
                        onClick={() => setOpen(!open)}
                        aria-expanded={expanded}
                        className="flex w-full items-center justify-between rounded px-2 py-1 text-micro font-bold uppercase text-ink-subtle hover:text-ink-muted"
                    >
                        {group.label}
                        <Icon name="chevronDown" className={cx('h-3.5 w-3.5 transition-transform', !expanded && '-rotate-90')} />
                    </button>
                ) : (
                    <p className="px-2 py-1 text-micro font-bold uppercase text-ink-subtle">{group.label}</p>
                ))}
            {collapsed && <div aria-hidden="true" className="mx-3 mb-2 border-t border-line" />}
            {expanded && (
                <ul className="space-y-0.5">
                    {group.items.map((item) => (
                        <SidebarItem
                            key={item.key}
                            item={item}
                            collapsed={collapsed}
                            badgeCount={item.badge ? (badges[item.badge] ?? 0) : 0}
                            onNavigate={onNavigate}
                        />
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function Sidebar({ collapsed = false, onToggleCollapse, onNavigate, inDrawer = false }) {
    const page = usePage();
    const badges = {
        inbox: page.props.inbox?.open_count ?? 0,
        notifications: page.props.notifications?.unread_count ?? 0,
    };
    const groups = resolveNavigation(hasRoute);
    const workspace = page.props.workspace?.name;

    return (
        <div className="flex h-full flex-col bg-surface">
            <div className={cx('flex items-center gap-2 border-b border-line px-3', collapsed ? 'justify-center' : '', 'h-topbar')}>
                <span
                    aria-hidden="true"
                    className="flex h-7 w-7 shrink-0 items-center justify-center rounded-control bg-brand-600 text-sm font-black text-ink-inverse"
                >
                    M
                </span>
                {!collapsed && (
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-bold text-ink">Miriam</p>
                        {workspace && <p className="truncate text-xs text-ink-subtle">{workspace}</p>}
                    </div>
                )}
                {!inDrawer && (
                    <button
                        type="button"
                        onClick={onToggleCollapse}
                        aria-label={collapsed ? 'Expand navigation' : 'Collapse navigation'}
                        title={collapsed ? 'Expand navigation' : 'Collapse navigation'}
                        className="hidden h-8 w-8 items-center justify-center rounded-control text-ink-subtle hover:bg-surface-sunken hover:text-ink lg:inline-flex"
                    >
                        <Icon name={collapsed ? 'chevronRight' : 'chevronLeft'} className="h-4 w-4" />
                    </button>
                )}
            </div>

            <nav aria-label="Primary" className="premium-scrollbar min-h-0 flex-1 overflow-y-auto px-2 py-3">
                {groups.map((group) => (
                    <SidebarGroup key={group.label} group={group} collapsed={collapsed} badges={badges} onNavigate={onNavigate} />
                ))}
            </nav>
        </div>
    );
}
