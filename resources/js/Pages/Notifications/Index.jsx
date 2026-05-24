import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Badge, EmptyState, Panel, primaryButton, secondaryButton } from '@/Components/Ui';

export default function Index({ notifications }) {
    const unread = notifications.filter((notification) => notification.unread).length;

    return (
        <AuthenticatedLayout title="Notifications" subtitle="Friday updates that need attention.">
            <Head title="Notifications" />

            <div className="space-y-5">
                <Panel className="overflow-hidden">
                    <div className="flex flex-col gap-3 bg-[radial-gradient(circle_at_top_left,_rgba(99,102,241,0.12),_transparent_30%),linear-gradient(135deg,_#ffffff,_#f8fafc_58%,_#eef2ff)] p-5 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p className="text-sm font-semibold uppercase tracking-wide text-slate-500">Inbox</p>
                        <h2 className="mt-2 text-xl font-semibold text-slate-950">{unread} unread</h2>
                    </div>
                    <button
                        type="button"
                        onClick={() => router.patch(route('notifications.read-all'))}
                        disabled={unread === 0}
                        className={primaryButton}
                    >
                        Mark All Read
                    </button>
                    </div>
                </Panel>

                <Panel className="overflow-hidden">
                    {notifications.length === 0 ? (
                        <EmptyState title="No notifications yet" description="Assignment, comment, attachment, and reminder updates will appear here." />
                    ) : (
                        <div className="divide-y divide-slate-100">
                            {notifications.map((notification) => (
                                <div
                                    key={notification.id}
                                    className={`flex flex-col gap-3 p-5 lg:flex-row lg:items-center lg:justify-between ${
                                        notification.unread ? 'bg-blue-50/50' : 'bg-white'
                                    }`}
                                >
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-semibold text-slate-950">{notification.title}</span>
                                            {notification.unread && (
                                                <Badge tone="bg-blue-600 text-white ring-blue-600">Unread</Badge>
                                            )}
                                        </div>
                                        <p className="mt-1 text-sm text-slate-600">{notification.message}</p>
                                        <p className="mt-1 text-xs text-slate-500">{notification.created_at}</p>
                                    </div>
                                    <div className="flex gap-2">
                                        {notification.action_url && (
                                            <Link
                                                href={notification.action_url}
                                                className={secondaryButton}
                                            >
                                                Open
                                            </Link>
                                        )}
                                        {notification.unread && (
                                            <button
                                                type="button"
                                                onClick={() => router.patch(route('notifications.read', notification.id))}
                                                className={primaryButton}
                                            >
                                                Mark Read
                                            </button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </Panel>
            </div>
        </AuthenticatedLayout>
    );
}
