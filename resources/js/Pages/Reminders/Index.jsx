import { Head, Link } from '@inertiajs/react';
import AppShell from '@/Layouts/AppShell';
import { Badge, ConnectionStatus, EmptyState, Icon, Panel, PanelHeader, cx } from '@/Components/Kit';

/** Finite Reminder Poker: how far through the escalation this reminder is. */
function PokerProgress({ attempts, max, exhausted }) {
    return (
        <span className="inline-flex items-center gap-1" title={`${attempts} of ${max} reminders sent`}>
            <span className="sr-only">
                {attempts} of {max} reminders sent{exhausted ? ', escalation finished' : ''}
            </span>
            {Array.from({ length: max }).map((_, index) => (
                <span
                    key={index}
                    aria-hidden="true"
                    className={cx('h-1.5 w-4 rounded-full', index < attempts ? (exhausted ? 'bg-urgent' : 'bg-brand-500') : 'bg-line')}
                />
            ))}
        </span>
    );
}

function ReminderRow({ reminder }) {
    return (
        <li className="flex flex-col gap-2 px-4 py-3 sm:px-5 lg:flex-row lg:items-center lg:gap-4">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="truncate text-sm font-semibold text-ink">{reminder.title}</span>
                    {reminder.acknowledged && <Badge tone="good">Done</Badge>}
                    {reminder.status === 'snoozed' && <Badge tone="info">Snoozed</Badge>}
                    {reminder.exhausted && <Badge tone="urgent">Escalation finished</Badge>}
                    {reminder.awaiting_confirmation && <Badge tone="warn">Awaiting confirmation</Badge>}
                    {reminder.delivery_failed && <Badge tone="urgent">Delivery failed</Badge>}
                </div>
                <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-subtle">
                    {reminder.due_at && (
                        <span className="inline-flex items-center gap-1">
                            <Icon name="calendar" className="h-3 w-3" />
                            Due {reminder.due_at}
                        </span>
                    )}
                    {reminder.next_reminder_at && !reminder.acknowledged && (
                        <span className="inline-flex items-center gap-1">
                            <Icon name="bell" className="h-3 w-3" />
                            Next {reminder.next_reminder_at}
                        </span>
                    )}
                    {reminder.last_sent_at && (
                        <span className="inline-flex items-center gap-1">
                            <Icon name="send" className="h-3 w-3" />
                            Last sent {reminder.last_sent_at}
                        </span>
                    )}
                </div>
            </div>

            <div className="flex shrink-0 items-center gap-4">
                <PokerProgress attempts={reminder.attempts} max={reminder.max_pokes} exhausted={reminder.exhausted} />
                {reminder.task ? (
                    <Link href={reminder.task.url} className="text-xs font-semibold text-brand-700 hover:underline">
                        Open task
                    </Link>
                ) : reminder.capture_url ? (
                    <Link href={reminder.capture_url} className="text-xs font-semibold text-brand-700 hover:underline">
                        Review capture
                    </Link>
                ) : null}
            </div>
        </li>
    );
}

function Group({ title, description, reminders, emptyDescription }) {
    return (
        <Panel>
            <PanelHeader title={title} description={description} count={reminders.length} />
            {reminders.length === 0 ? (
                <EmptyState icon="clock" title="Nothing here" description={emptyDescription} />
            ) : (
                <ul className="divide-y divide-line">
                    {reminders.map((reminder) => (
                        <ReminderRow key={reminder.id} reminder={reminder} />
                    ))}
                </ul>
            )}
        </Panel>
    );
}

/**
 * Reminders.
 *
 * Read-only by design: acknowledgement and snooze happen on the Slack buttons
 * that drive the finite escalation. This page reports that state truthfully
 * rather than offering controls that are not wired.
 */
export default function RemindersIndex({ groups, counts, poker, delivery }) {
    return (
        <AppShell
            title="Reminders"
            subtitle="What Miriam will chase you about, and how far it has got."
            meta={
                <>
                    <ConnectionStatus
                        connected={delivery?.slack_configured}
                        connectedLabel="Slack delivery configured"
                        disconnectedLabel="Slack delivery not configured"
                    />
                    <span className="text-xs text-ink-subtle">Times in {delivery?.timezone}</span>
                </>
            }
        >
            <Head title="Reminders" />

            <div className="space-y-4">
                <div className="rounded-panel border border-line bg-surface px-4 py-3 text-sm text-ink-muted">
                    <p className="font-semibold text-ink">How reminding works</p>
                    <p className="mt-1 leading-6">
                        Miriam sends a reminder when it is due, again after {poker?.second_poke_minutes} minutes, and once more after{' '}
                        {Math.round((poker?.final_poke_minutes ?? 120) / 60)} hours. After {poker?.max_pokes} attempts it stops and marks the
                        reminder as needing attention. It never nags indefinitely.
                    </p>
                    {!delivery?.channel_configured && (
                        <p className="mt-2 text-xs font-semibold text-warn-ink">
                            No Slack channel is configured, so reminders fall back to the default channel if one is set.
                        </p>
                    )}
                </div>

                <Group
                    title="Due now"
                    description="Past their reminder time and not yet acknowledged."
                    reminders={groups?.due ?? []}
                    emptyDescription="Nothing is due right now."
                />
                <Group
                    title="Needs attention"
                    description="Escalation finished without an answer, or a capture still awaiting confirmation."
                    reminders={groups?.needs_attention ?? []}
                    emptyDescription="Nothing has gone unanswered."
                />
                <Group
                    title="Snoozed"
                    description="Pushed back to a later time."
                    reminders={groups?.snoozed ?? []}
                    emptyDescription="Nothing is snoozed."
                />
                <Group
                    title="Scheduled"
                    description="Waiting for their time to come."
                    reminders={groups?.scheduled ?? []}
                    emptyDescription="No upcoming reminders."
                />
                <Group
                    title="Finished"
                    description="Acknowledged, cancelled or expired."
                    reminders={groups?.closed ?? []}
                    emptyDescription="Nothing finished yet."
                />
            </div>
        </AppShell>
    );
}
