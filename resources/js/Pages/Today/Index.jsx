import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import AppShell from '@/Layouts/AppShell';
import QuickCapture from '@/Components/Shell/QuickCapture';
import TaskDetailDrawer from '@/Components/Tasks/TaskDetailDrawer';
import { TaskSection, useTaskCompletion } from '@/Components/Tasks/TaskList';
import { Badge, EmptyState, Icon, LinkButton, Panel, PanelHeader, cx } from '@/Components/Kit';

function greeting(name) {
    const hour = new Date().getHours();
    const part = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';

    return name ? `${part}, ${name.split(' ')[0]}` : part;
}

function FollowUpRow({ item }) {
    return (
        <li className="flex items-start gap-3 px-4 py-2.5 hover:bg-surface-sunken sm:px-5">
            <Icon name="send" className="mt-0.5 h-4 w-4 text-ink-subtle" />
            <div className="min-w-0 flex-1">
                <Link href={item.href} className="block truncate text-sm font-semibold text-ink hover:text-brand-700">
                    {item.title}
                </Link>
                <p className="mt-0.5 text-xs text-ink-subtle">
                    {item.reason}
                    {item.age ? ` · ${item.age}` : ''}
                </p>
            </div>
            <Link href={item.href} className="shrink-0 text-xs font-semibold text-brand-700 hover:underline">
                Open
            </Link>
        </li>
    );
}

function ReminderRow({ reminder }) {
    const tone = reminder.state === 'missed' ? 'urgent' : reminder.state === 'due' ? 'warn' : 'neutral';
    const label = reminder.state === 'missed' ? 'Missed' : reminder.state === 'due' ? 'Due now' : 'Scheduled';

    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 sm:px-5">
            <Badge tone={tone}>{label}</Badge>
            <span className="min-w-0 flex-1 truncate text-sm font-semibold text-ink">{reminder.title}</span>
            <span className="text-xs text-ink-subtle">{reminder.due_at_local}</span>
            {reminder.delivery_failed && (
                <span className="text-xs font-semibold text-urgent-ink">Delivery stopped after {reminder.attempts}</span>
            )}
            {reminder.href && (
                <Link href={reminder.href} className="text-xs font-semibold text-brand-700 hover:underline">
                    Open task
                </Link>
            )}
        </li>
    );
}

/**
 * Today — the daily command center.
 *
 * Actions, not analytics: capture, the work that is actually due, what is
 * waiting on someone, reminders, and the two personal routines that are
 * reliably wired. Nothing here is decorative.
 */
export default function TodayIndex({ groups, summary, reading, commandCenter, today, inboxCount = 0, auth }) {
    const cc = commandCenter ?? {};
    const [openTaskId, setOpenTaskId] = useState(null);
    const toggleCompletion = useTaskCompletion();
    const todayDate = today?.date;

    // Priority work, deduplicated across overdue / due today / placed in Today
    // so one task can never occupy three rows.
    const priority = [];
    const seen = new Set();
    for (const task of [...(groups?.overdue ?? []), ...(groups?.due_today ?? []), ...(groups?.scheduled_today ?? [])]) {
        if (!seen.has(task.id)) {
            seen.add(task.id);
            priority.push(task);
        }
    }

    const waiting = cc.waiting_on_me ?? [];
    const reminders = cc.reminders ?? [];
    const medication = cc.hero_status?.health;
    const completedToday = summary?.completed_today ?? 0;

    return (
        <AppShell
            title={greeting(auth?.user?.name)}
            subtitle={today?.label ? `${today.label} · ${today.timezone}` : undefined}
            meta={
                <>
                    <Badge tone={priority.length ? 'warn' : 'good'}>{priority.length} to do</Badge>
                    {completedToday > 0 && <Badge tone="good">{completedToday} done today</Badge>}
                    {waiting.length > 0 && <Badge tone="neutral">{waiting.length} waiting</Badge>}
                </>
            }
            actions={
                <LinkButton href={route('tasks.index')} variant="secondary">
                    Open My Tasks
                </LinkButton>
            }
        >
            <Head title="Today" />

            <div className="space-y-4">
                <Panel className="p-4 sm:p-5">
                    <h2 className="sr-only">Quick Capture</h2>
                    <QuickCapture placeholder="What is on your mind? Miriam will sort it out with you later." />
                </Panel>

                {inboxCount > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-panel border border-brand-200 bg-brand-50 px-4 py-3">
                        <p className="text-sm font-semibold text-brand-700">
                            {inboxCount} captured {inboxCount === 1 ? 'thought is' : 'thoughts are'} waiting to be sorted.
                        </p>
                        <LinkButton href={route('inbox.index')} variant="primary" size="sm">
                            Triage Inbox
                        </LinkButton>
                    </div>
                )}

                <TaskSection
                    title="Priority work"
                    description="Overdue first, then everything due or placed on today."
                    tasks={priority}
                    today={todayDate}
                    onOpen={(task) => setOpenTaskId(task.id)}
                    onToggle={toggleCompletion}
                    emptyTitle="Nothing due today"
                    emptyDescription="Nothing is overdue and nothing is scheduled for today. Pull something forward from My Tasks, or capture what is on your mind above."
                    emptyAction={
                        <LinkButton href={route('tasks.index', { view: 'upcoming' })} size="sm">
                            See what is coming up
                        </LinkButton>
                    }
                />

                <div className="grid gap-4 xl:grid-cols-2">
                    <Panel>
                        <PanelHeader title="Follow-up" description="Waiting on someone else, or needing your decision." count={waiting.length} />
                        {waiting.length === 0 ? (
                            <EmptyState
                                icon="send"
                                title="Nothing is waiting on you"
                                description="Approvals, delegated work and follow-ups appear here when they need you."
                            />
                        ) : (
                            <ul className="divide-y divide-line">
                                {waiting.map((item) => (
                                    <FollowUpRow key={item.id} item={item} />
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel>
                        <PanelHeader
                            title="Reminders"
                            description="Due or recently missed. Today and Slack read the same record."
                            count={reminders.length}
                            action={
                                <LinkButton href={route('reminders.index')} size="xs">
                                    All reminders
                                </LinkButton>
                            }
                        />
                        {reminders.length === 0 ? (
                            <EmptyState
                                icon="clock"
                                title="No reminders due"
                                description="Reminders you capture or schedule on a task appear here when they come due."
                            />
                        ) : (
                            <ul className="divide-y divide-line">
                                {reminders.map((reminder) => (
                                    <ReminderRow key={reminder.id} reminder={reminder} />
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                <div className="grid gap-4 xl:grid-cols-2">
                    {medication && (
                        <Panel>
                            <PanelHeader
                                title="Medication"
                                action={
                                    <LinkButton href={route('health.index')} size="xs">
                                        Open Health
                                    </LinkButton>
                                }
                            />
                            <div className="flex items-center gap-3 px-4 py-4 sm:px-5">
                                <span
                                    aria-hidden="true"
                                    className={cx(
                                        'h-2.5 w-2.5 rounded-full',
                                        medication.tone === 'critical' ? 'bg-urgent' : medication.tone === 'waiting' ? 'bg-warn' : 'bg-good',
                                    )}
                                />
                                <p className="text-sm font-semibold text-ink">{medication.label}</p>
                            </div>
                        </Panel>
                    )}

                    {reading?.has_plan && (
                        <Panel>
                            <PanelHeader
                                title="Bible reading"
                                action={
                                    <LinkButton href={reading.continue_url} size="xs">
                                        Continue
                                    </LinkButton>
                                }
                            />
                            <div className="px-4 py-4 sm:px-5">
                                <p className="text-sm font-semibold text-ink">{reading.today_label}</p>
                                <p className="mt-1 text-sm text-ink-muted">
                                    {reading.today_completed_chapters} of {reading.today_total_chapters} chapters complete
                                </p>
                            </div>
                        </Panel>
                    )}
                </div>

                {/* Codex is reported honestly rather than implied to be running. */}
                {cc.codex_workstream?.available === false && (
                    <p className="px-1 text-xs text-ink-subtle">
                        Codex / development pipeline: not available in this build.{' '}
                        <Link href={route('settings.system-health.index')} className="underline">
                            System health
                        </Link>
                    </p>
                )}
            </div>

            <TaskDetailDrawer taskId={openTaskId} open={openTaskId !== null} onClose={() => setOpenTaskId(null)} today={todayDate} />
        </AppShell>
    );
}
