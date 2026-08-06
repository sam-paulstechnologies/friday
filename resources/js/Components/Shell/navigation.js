/**
 * Miriam's information architecture.
 *
 * Ordered by how the daily loop is actually worked: capture lands in Inbox,
 * triage sends it to Today, everything else supports that.
 *
 * `availability` is honest metadata, not decoration. Anything that is not
 * fully wired carries a badge so no menu item promises more than it delivers.
 */
export const navigationGroups = [
    {
        label: 'Command',
        items: [
            { key: 'today', name: 'Today', route: 'today.index', match: ['today.*'], icon: 'today' },
            { key: 'inbox', name: 'Inbox', route: 'inbox.index', match: ['inbox.*'], icon: 'inbox', badge: 'inbox' },
            { key: 'tasks', name: 'My Tasks', route: 'tasks.index', match: ['tasks.*', 'task-review.*'], icon: 'check' },
        ],
    },
    {
        label: 'Workspaces',
        items: [
            { key: 'projects', name: 'Projects', route: 'projects.index', match: ['projects.*'], icon: 'folder' },
            { key: 'portfolios', name: 'Products', route: 'portfolios.index', match: ['portfolios.*'], icon: 'grid' },
        ],
    },
    {
        label: 'Review',
        items: [
            { key: 'reminders', name: 'Reminders', route: 'reminders.index', match: ['reminders.*'], icon: 'clock' },
            { key: 'waiting', name: 'Waiting for', route: 'waiting.index', match: ['waiting.*'], icon: 'send' },
            { key: 'approvals', name: 'Approvals', route: 'approvals.index', match: ['approvals.*'], icon: 'flag' },
            { key: 'decisions', name: 'Decisions', route: 'decisions.index', match: ['decisions.*'], icon: 'spark' },
            { key: 'blockers', name: 'Blockers', route: 'blockers.index', match: ['blockers.*', 'risks.*'], icon: 'alert' },
        ],
    },
    {
        label: 'Personal',
        items: [
            { key: 'health', name: 'Health', route: 'health.index', match: ['health.*'], icon: 'heart' },
            { key: 'spiritual', name: 'Bible reading', route: 'spiritual.index', match: ['spiritual.*'], icon: 'book' },
            { key: 'planner', name: 'Planner', route: 'planner.index', match: ['planner.*', 'calendar.*', 'workload.*'], icon: 'calendar' },
        ],
    },
    {
        label: 'More',
        collapsible: true,
        items: [
            { key: 'notifications', name: 'Notifications', route: 'notifications.index', match: ['notifications.*'], icon: 'bell', badge: 'notifications' },
            { key: 'notes', name: 'Notes', route: 'notes.index', match: ['notes.*'], icon: 'list' },
            { key: 'reports', name: 'Reports', route: 'reports.index', match: ['reports.*', 'goals.*', 'areas.*'], icon: 'grid' },
            { key: 'operations', name: 'Operations map', route: 'operations-center.index', match: ['operations-center.*'], icon: 'layers', availability: 'preview' },
            { key: 'assistant', name: 'Assistant', route: 'assistant.index', match: ['assistant.*'], icon: 'spark', availability: 'manual' },
            { key: 'agents', name: 'Agents', route: 'agents.index', match: ['agents.*'], icon: 'spark', availability: 'preview' },
            { key: 'dashboard', name: 'Legacy dashboard', route: 'dashboard', match: ['dashboard'], icon: 'grid', availability: 'preview' },
        ],
    },
    {
        label: 'Settings',
        collapsible: true,
        items: [
            { key: 'workspace', name: 'Workspace', route: 'settings.workspace.edit', match: ['settings.workspace.*'], icon: 'user' },
            { key: 'integrations', name: 'Integrations', route: 'settings.integrations.index', match: ['settings.integrations.*'], icon: 'layers' },
            { key: 'system-health', name: 'System health', route: 'settings.system-health.index', match: ['settings.system-health.*'], icon: 'alert' },
            { key: 'automations', name: 'Automations', route: 'settings.automations.index', match: ['settings.automations.*'], icon: 'refresh' },
            { key: 'ai', name: 'AI brain', route: 'settings.ai.edit', match: ['settings.ai.*'], icon: 'spark', availability: 'not_connected' },
            { key: 'templates', name: 'Templates', route: 'templates.index', match: ['templates.*'], icon: 'grid' },
            { key: 'custom-fields', name: 'Custom fields', route: 'admin.custom-fields.index', match: ['admin.custom-fields.*'], icon: 'settings' },
        ],
    },
];

/** Bottom-bar destinations for phones: the daily loop plus capture. */
export const mobilePrimary = ['today', 'inbox', 'tasks', 'reminders'];

/**
 * Drop anything whose route is not registered, so navigation can never point
 * at a page that does not exist.
 */
export function resolveNavigation(hasRoute) {
    return navigationGroups
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => hasRoute(item.route)),
        }))
        .filter((group) => group.items.length > 0);
}
