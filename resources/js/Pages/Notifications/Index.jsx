import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ notifications }) {
    const unread = notifications.filter((notification) => notification.unread).length;

    return (
        <AuthenticatedLayout title="Notifications" subtitle="Friday updates that need attention.">
            <Head title="Notifications" />

            <div className="space-y-5">
                <section className="flex flex-col gap-3 rounded-md border border-slate-200 bg-white p-5 shadow-sm md:flex-row md:items-center md:justify-between">
                    <div>
                        <p className="text-sm font-semibold uppercase tracking-wide text-slate-500">Inbox</p>
                        <h2 className="mt-2 text-xl font-semibold text-slate-950">{unread} unread</h2>
                    </div>
                    <button
                        type="button"
                        onClick={() => router.patch(route('notifications.read-all'))}
                        disabled={unread === 0}
                        className="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                    >
                        Mark All Read
                    </button>
                </section>

                <section className="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
                    {notifications.length === 0 ? (
                        <div className="p-8 text-center text-sm text-slate-500">No notifications yet.</div>
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
                                                <span className="rounded-md bg-blue-600 px-2 py-0.5 text-xs font-semibold text-white">
                                                    Unread
                                                </span>
                                            )}
                                        </div>
                                        <p className="mt-1 text-sm text-slate-600">{notification.message}</p>
                                        <p className="mt-1 text-xs text-slate-500">{notification.created_at}</p>
                                    </div>
                                    <div className="flex gap-2">
                                        {notification.action_url && (
                                            <Link
                                                href={notification.action_url}
                                                className="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:text-slate-950"
                                            >
                                                Open
                                            </Link>
                                        )}
                                        {notification.unread && (
                                            <button
                                                type="button"
                                                onClick={() => router.patch(route('notifications.read', notification.id))}
                                                className="rounded-md bg-slate-950 px-3 py-1.5 text-xs font-semibold text-white shadow-sm"
                                            >
                                                Mark Read
                                            </button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
