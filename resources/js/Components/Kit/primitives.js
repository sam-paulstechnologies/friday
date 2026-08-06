/**
 * Shared class recipes.
 *
 * Components import these instead of hand-writing class strings, so a token
 * change propagates everywhere and variants cannot drift page by page.
 */

export function cx(...values) {
    return values.flat(Infinity).filter(Boolean).join(' ');
}

const focusable =
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 focus-visible:ring-offset-2 focus-visible:ring-offset-surface';

const buttonBase = cx(
    'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-control font-semibold',
    'transition-colors duration-150 disabled:pointer-events-none disabled:opacity-50',
    focusable,
);

export const buttonSizes = {
    xs: 'h-7 px-2 text-xs',
    sm: 'h-8 px-2.5 text-sm',
    md: 'h-9 px-3 text-sm',
    lg: 'h-10 px-4 text-sm',
    // 44px square — the minimum comfortable touch target.
    icon: 'h-9 w-9 p-0',
    iconLg: 'h-11 w-11 p-0',
};

export const buttonVariants = {
    primary: 'bg-brand-600 text-ink-inverse hover:bg-brand-700 active:bg-brand-700',
    secondary: 'border border-line-strong bg-surface text-ink hover:bg-surface-sunken',
    ghost: 'text-ink-muted hover:bg-surface-sunken hover:text-ink',
    subtle: 'bg-surface-sunken text-ink-muted hover:bg-line hover:text-ink',
    danger: 'bg-urgent text-ink-inverse hover:brightness-95',
    dangerGhost: 'text-urgent-ink hover:bg-urgent-soft',
};

export function buttonClass({ variant = 'secondary', size = 'md', className = '' } = {}) {
    return cx(buttonBase, buttonSizes[size] ?? buttonSizes.md, buttonVariants[variant] ?? buttonVariants.secondary, className);
}

export const fieldClass = cx(
    'block w-full rounded-control border-line-strong bg-surface text-sm text-ink shadow-none',
    'placeholder:text-ink-subtle',
    'focus:border-brand-500 focus:ring-2 focus:ring-brand-500/25',
    'disabled:bg-surface-sunken disabled:text-ink-subtle',
    'aria-[invalid=true]:border-urgent aria-[invalid=true]:focus:ring-urgent/25',
);

export const panelClass = 'rounded-panel border border-line bg-surface shadow-panel';

export const badgeTones = {
    neutral: 'bg-surface-sunken text-ink-muted ring-line',
    brand: 'bg-brand-50 text-brand-700 ring-brand-200',
    urgent: 'bg-urgent-soft text-urgent-ink ring-urgent/20',
    warn: 'bg-warn-soft text-warn-ink ring-warn/20',
    good: 'bg-good-soft text-good-ink ring-good/20',
    info: 'bg-info-soft text-info-ink ring-info/20',
};

export const focusRing = focusable;
