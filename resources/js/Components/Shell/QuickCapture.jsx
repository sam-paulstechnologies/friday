import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Alert, Button, Icon, TextArea, cx } from '@/Components/Kit';

function newToken() {
    // Per-submission token: a double click or a replayed POST resolves to the
    // same capture instead of creating a second one.
    return `qc-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

/**
 * Quick Capture.
 *
 * One natural-language box. Enter submits, Shift+Enter adds a line. The input
 * is only cleared once the server confirms the capture was persisted, so a
 * failure never costs the operator their thought.
 */
export default function QuickCapture({
    autoFocus = false,
    placeholder = 'Capture a thought…',
    onCaptured,
    compact = false,
    showTodayAction = true,
}) {
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(null);
    const [result, setResult] = useState(null);
    const [failure, setFailure] = useState(null);
    const tokenRef = useRef(newToken());
    const inputRef = useRef(null);
    const page = usePage();

    useEffect(() => {
        if (autoFocus) inputRef.current?.focus();
    }, [autoFocus]);

    const submit = (destination) => {
        const value = text.trim();

        if (!value || busy) return;

        setBusy(destination);
        setFailure(null);
        setResult(null);

        router.post(
            route('capture.store'),
            { text: value, destination, client_token: tokenRef.current },
            {
                preserveScroll: true,
                preserveState: true,
                // Only Today/Inbox counts need refreshing; do not refetch the page.
                only: ['inbox', 'flash', 'groups', 'summary', 'commandCenter', 'inboxCount'],
                onSuccess: () => {
                    const captured = page.props.flash?.capture ?? null;
                    // Cleared only after the server confirmed persistence.
                    setText('');
                    tokenRef.current = newToken();
                    setResult(captured ?? { status: 'captured', message: destination === 'today' ? 'Added to Today.' : 'Captured to your Inbox.' });
                    onCaptured?.(captured);
                },
                onError: (errors) => {
                    setFailure(errors?.text ?? 'Miriam could not save that. Your text is still here — try again.');
                },
                onFinish: () => setBusy(null),
            },
        );
    };

    const onKeyDown = (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            submit('inbox');
        }
    };

    return (
        <div className="space-y-2">
            <div className={cx('flex gap-2', compact ? 'items-center' : 'items-start')}>
                <div className="relative min-w-0 flex-1">
                    <label htmlFor="quick-capture" className="sr-only">
                        Capture a thought
                    </label>
                    <TextArea
                        ref={inputRef}
                        id="quick-capture"
                        rows={compact ? 1 : 2}
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        onKeyDown={onKeyDown}
                        placeholder={placeholder}
                        aria-describedby="quick-capture-hint"
                        className="resize-none"
                    />
                </div>
                <div className="flex shrink-0 flex-col gap-2 sm:flex-row">
                    <Button variant="primary" onClick={() => submit('inbox')} disabled={!text.trim() || !!busy}>
                        {busy === 'inbox' ? 'Saving…' : 'Capture'}
                    </Button>
                    {showTodayAction && (
                        <Button onClick={() => submit('today')} disabled={!text.trim() || !!busy}>
                            {busy === 'today' ? 'Adding…' : 'Add to Today'}
                        </Button>
                    )}
                </div>
            </div>

            <p id="quick-capture-hint" className="text-xs text-ink-subtle">
                Enter to capture · Shift + Enter for a new line. Capture keeps it in your Inbox to sort later; Add to Today puts it on
                today&rsquo;s plate.
            </p>

            {failure && (
                <Alert tone="urgent" title="Not captured">
                    {failure}
                </Alert>
            )}

            {result && (
                <Alert
                    tone={result.status === 'needs_review' ? 'warn' : 'good'}
                    onDismiss={() => setResult(null)}
                    action={
                        result.url ? (
                            <a href={result.url} className="shrink-0 text-sm font-semibold underline underline-offset-2">
                                Open
                            </a>
                        ) : null
                    }
                >
                    <span className="inline-flex items-center gap-1.5">
                        <Icon name="check" className="h-3.5 w-3.5" />
                        {result.message}
                    </span>
                </Alert>
            )}
        </div>
    );
}
