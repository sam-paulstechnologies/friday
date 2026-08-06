import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppShell from '@/Layouts/AppShell';
import QuickCapture from '@/Components/Shell/QuickCapture';
import { Badge, Button, EmptyState, Icon, LinkButton, Panel, PanelHeader, ViewTabs, cx } from '@/Components/Kit';

const STATE_TONE = {
    unprocessed: 'warn',
    clarification_needed: 'info',
    converted: 'good',
    dismissed: 'neutral',
    duplicate: 'neutral',
};

function CaptureListItem({ item, selected, onSelect }) {
    return (
        <li>
            <button
                type="button"
                onClick={() => onSelect(item)}
                aria-current={selected ? 'true' : undefined}
                className={cx(
                    'flex w-full flex-col gap-1 border-l-2 px-4 py-3 text-left transition-colors',
                    selected ? 'border-brand-600 bg-brand-50' : 'border-transparent hover:bg-surface-sunken',
                )}
            >
                <span className="flex items-center gap-2">
                    <Badge tone={STATE_TONE[item.state] ?? 'neutral'}>{item.state_label}</Badge>
                    <span className="text-micro font-semibold uppercase text-ink-subtle">{item.capture_source}</span>
                </span>
                <span className={cx('truncate text-sm font-semibold', selected ? 'text-brand-700' : 'text-ink')}>{item.title}</span>
                <span className="truncate text-xs text-ink-subtle">{item.captured_at_local}</span>
            </button>
        </li>
    );
}

/** The review pane: original wording first, then what Miriam proposed. */
function CaptureDetail({ item, destinations }) {
    const [busy, setBusy] = useState(null);

    if (!item) {
        return (
            <EmptyState
                icon="inbox"
                title="Select a capture"
                description="Choose something on the left to see exactly what you said and what Miriam made of it."
            />
        );
    }

    const act = (routeName, data = {}) => {
        setBusy(routeName + (data.destination ?? ''));
        router.post(route(routeName, [item.source, item.id]), data, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        });
    };

    const proposed = item.proposed ?? {};
    const uncertain = proposed.confidence != null && proposed.confidence < 0.75;

    return (
        <div className="flex h-full flex-col">
            <div className="border-b border-line px-4 py-4 sm:px-5">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge tone={STATE_TONE[item.state] ?? 'neutral'}>{item.state_label}</Badge>
                    <span className="text-micro font-semibold uppercase text-ink-subtle">{item.capture_source}</span>
                    <span className="text-xs text-ink-subtle">{item.captured_at_local}</span>
                </div>
                <h2 className="mt-2 text-lg font-bold text-ink">{item.title}</h2>
            </div>

            <div className="premium-scrollbar min-h-0 flex-1 overflow-y-auto">
                <div className="px-4 py-4 sm:px-5">
                    <p className="text-micro font-bold uppercase text-ink-subtle">What you actually said</p>
                    <blockquote className="mt-1.5 whitespace-pre-wrap rounded-control bg-surface-sunken px-3 py-2.5 text-sm leading-6 text-ink-muted">
                        {item.original_text}
                    </blockquote>
                    <p className="mt-1.5 text-xs text-ink-subtle">This wording is kept on the record whatever you do next.</p>
                </div>

                <div className="border-t border-line px-4 py-4 sm:px-5">
                    <p className="text-micro font-bold uppercase text-ink-subtle">What Miriam read</p>
                    {uncertain && (
                        <p className="mt-1.5 rounded-control border border-info/25 bg-info-soft px-3 py-2 text-sm font-semibold text-info-ink">
                            Miriam was not confident about this. Check the details before converting.
                        </p>
                    )}
                    <dl className="mt-2 grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
                        {[
                            ['Due', proposed.due_date],
                            ['Time', proposed.due_time],
                            ['Priority', proposed.priority],
                            ['Type', proposed.display_type],
                            ['Project', proposed.project_name],
                            ['Confidence', proposed.confidence != null ? `${Math.round(proposed.confidence * 100)}%` : null],
                        ]
                            .filter(([, value]) => value)
                            .map(([label, value]) => (
                                <div key={label} className="flex gap-1.5">
                                    <dt className="font-semibold text-ink-subtle">{label}:</dt>
                                    <dd className="text-ink">{value}</dd>
                                </div>
                            ))}
                    </dl>
                    {proposed.project_name && !proposed.project_id && (
                        <p className="mt-2 text-xs text-ink-subtle">
                            Miriam read &ldquo;{proposed.project_name}&rdquo; but found no matching project, so nothing was attached.
                        </p>
                    )}
                </div>

                {item.task && (
                    <div className="border-t border-line px-4 py-4 sm:px-5">
                        <p className="text-micro font-bold uppercase text-ink-subtle">Resulting task</p>
                        <Link href={item.task.url} className="mt-1.5 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline">
                            <Icon name="check" className="h-4 w-4" />
                            {item.task.title}
                        </Link>
                    </div>
                )}
            </div>

            <div className="border-t border-line bg-surface-sunken px-4 py-3 sm:px-5">
                {item.can_convert ? (
                    <div className="flex flex-wrap gap-2">
                        <LinkButton href={route('inbox.show', [item.source, item.id])} variant="primary" size="sm">
                            Review &amp; edit
                        </LinkButton>
                        {Object.entries(destinations ?? {})
                            .filter(([key]) => ['today', 'later', 'waiting', 'delegated'].includes(key))
                            .map(([key, label]) => (
                                <Button key={key} size="sm" disabled={!!busy} onClick={() => act('inbox.move', { destination: key })}>
                                    {busy === `inbox.move${key}` ? '…' : label}
                                </Button>
                            ))}
                        <Button size="sm" variant="ghost" disabled={!!busy} onClick={() => act('inbox.dismiss')}>
                            Dismiss
                        </Button>
                    </div>
                ) : (
                    <div className="flex flex-wrap items-center gap-2">
                        {item.task && (
                            <LinkButton href={item.task.url} size="sm" variant="primary">
                                Open task
                            </LinkButton>
                        )}
                        <LinkButton href={route('inbox.show', [item.source, item.id])} size="sm">
                            View capture
                        </LinkButton>
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * Inbox — capture triage.
 *
 * A list of captures beside a review pane, so sorting is a single pass without
 * navigating away. Slack and web Quick Capture feed the same list.
 */
export default function InboxIndex({ inbox, destinations }) {
    const open = inbox?.open ?? [];
    const resolved = inbox?.resolved ?? [];
    const counts = inbox?.counts ?? {};
    const [tab, setTab] = useState('open');
    const list = tab === 'open' ? open : resolved;
    const [selectedKey, setSelectedKey] = useState(list[0]?.key ?? null);

    // Keep the selection valid as the list changes underneath.
    useEffect(() => {
        if (!list.some((item) => item.key === selectedKey)) {
            setSelectedKey(list[0]?.key ?? null);
        }
    }, [list, selectedKey]);

    const selected = list.find((item) => item.key === selectedKey) ?? null;

    return (
        <AppShell
            title="Inbox"
            subtitle="Everything you captured but have not decided on yet."
            meta={<Badge tone={counts.open ? 'warn' : 'good'}>{counts.open ?? 0} to triage</Badge>}
            tabs={
                <ViewTabs
                    current={tab}
                    onChange={setTab}
                    items={[
                        { key: 'open', label: 'To triage', count: counts.open ?? 0 },
                        { key: 'resolved', label: 'Sorted', count: (counts.converted ?? 0) + (counts.dismissed ?? 0) },
                    ]}
                />
            }
        >
            <Head title="Inbox" />

            <div data-testid="inbox-page" className="space-y-4">
                <Panel className="p-4 sm:p-5">
                    <h2 className="sr-only">Quick Capture</h2>
                    <QuickCapture placeholder="Capture something new…" showTodayAction={false} compact />
                </Panel>

                {list.length === 0 ? (
                    <Panel>
                        <EmptyState
                            icon="inbox"
                            title={tab === 'open' ? 'Your Inbox is clear' : 'Nothing sorted yet'}
                            description={
                                tab === 'open'
                                    ? 'The Inbox holds thoughts you have captured but not yet turned into work. Type one into the box above, or send Miriam a direct message on Slack — both land here. You never have to decide what something is at the moment you think of it.'
                                    : 'Captures you convert or dismiss are kept here so nothing you wrote down disappears.'
                            }
                        />
                    </Panel>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                        <Panel className="overflow-hidden">
                            <PanelHeader title={tab === 'open' ? 'To triage' : 'Sorted'} count={list.length} />
                            <ul className="divide-y divide-line">
                                {list.map((item) => (
                                    <CaptureListItem
                                        key={item.key}
                                        item={item}
                                        selected={item.key === selectedKey}
                                        onSelect={(next) => setSelectedKey(next.key)}
                                    />
                                ))}
                            </ul>
                        </Panel>

                        {/* Review pane sits beside the list on desktop and below it on
                            narrow screens; no horizontal scrolling either way. */}
                        <Panel className="overflow-hidden lg:sticky lg:top-[calc(theme(spacing.topbar)+1rem)] lg:max-h-[calc(100vh-8rem)]">
                            <CaptureDetail item={selected} destinations={destinations} />
                        </Panel>
                    </div>
                )}
            </div>
        </AppShell>
    );
}
