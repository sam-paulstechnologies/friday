import { Badge, EmptyState, PageSection, primaryButton, secondaryButton } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ googleCalendar }) {
    const connection = googleCalendar.connection;
    const connected = Boolean(connection?.connected);
    const ready = googleCalendar.enabled && googleCalendar.configured;

    const sync = () => router.post(route('settings.integrations.google.sync'), {}, { preserveScroll: true });
    const disconnect = () => router.patch(route('settings.integrations.google.disconnect', connection.id), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout title="Integrations" subtitle="Connect external tools without exposing credentials in Miriam.">
            <Head title="Integrations" />

            <div className="space-y-4">
                <PageSection
                    title="Google Calendar"
                    description="Sync dated Miriam tasks to Google Calendar and show external calendar events in Planner."
                    action={<Badge tone={connected ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : 'bg-slate-100 text-slate-700 ring-slate-200'}>{connected ? 'Connected' : 'Not connected'}</Badge>}
                >
                    <div className="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                        <div className="space-y-3 text-sm">
                            {!ready && (
                                <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
                                    Google Calendar is disabled or missing OAuth configuration. Add placeholder-backed values in the environment before connecting.
                                </div>
                            )}

                            {connected ? (
                                <div className="grid gap-3 md:grid-cols-3">
                                    <Meta label="Account" value={connection.provider_account_email ?? 'Google account'} />
                                    <Meta label="Workspace" value={connection.workspace?.name ?? 'Personal'} />
                                    <Meta label="Last sync" value={connection.last_synced_at ?? 'Never'} />
                                </div>
                            ) : (
                                <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 text-slate-600">
                                    No Google Calendar connection is active for your account.
                                </div>
                            )}
                        </div>

                        <div className="flex flex-wrap gap-2 lg:justify-end">
                            {!connected && (
                                <Link href={route('settings.integrations.google.connect')} className={`${primaryButton} ${!ready ? 'pointer-events-none opacity-50' : ''}`}>
                                    Connect
                                </Link>
                            )}
                            {connected && (
                                <>
                                    <button type="button" onClick={sync} disabled={!ready} className={primaryButton}>Manual sync</button>
                                    <button type="button" onClick={disconnect} className={secondaryButton}>Disconnect</button>
                                </>
                            )}
                        </div>
                    </div>
                </PageSection>

                <PageSection title="Recent sync logs" description="Non-sensitive sync status for your Google Calendar connection.">
                    {googleCalendar.logs.length === 0 ? (
                        <EmptyState title="No sync activity yet" />
                    ) : (
                        <div className="divide-y divide-slate-100">
                            {googleCalendar.logs.map((log) => (
                                <div key={log.id} className="grid gap-2 px-4 py-3 text-sm md:grid-cols-[120px_120px_minmax(0,1fr)_180px] md:items-center">
                                    <Badge tone={log.status === 'success' ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : log.status === 'failed' ? 'bg-rose-50 text-rose-700 ring-rose-100' : 'bg-slate-100 text-slate-700 ring-slate-200'}>
                                        {log.status}
                                    </Badge>
                                    <span className="font-medium text-slate-700">{log.direction}</span>
                                    <span className="truncate text-slate-600">{log.message}</span>
                                    <span className="text-xs text-slate-500 md:text-right">{log.created_at}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </PageSection>
            </div>
        </AuthenticatedLayout>
    );
}

function Meta({ label, value }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-3">
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 truncate font-semibold text-slate-950">{value}</div>
        </div>
    );
}
