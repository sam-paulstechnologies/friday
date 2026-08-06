import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Miriam design tokens.
 *
 * Every value here resolves to a CSS custom property declared in
 * resources/css/app.css, so a token can be retuned in one place without
 * touching component class strings.
 */

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                // Surfaces — a calm neutral canvas and the working surfaces on it.
                canvas: 'rgb(var(--m-canvas) / <alpha-value>)',
                surface: {
                    DEFAULT: 'rgb(var(--m-surface) / <alpha-value>)',
                    sunken: 'rgb(var(--m-surface-sunken) / <alpha-value>)',
                    raised: 'rgb(var(--m-surface-raised) / <alpha-value>)',
                },
                line: {
                    DEFAULT: 'rgb(var(--m-line) / <alpha-value>)',
                    strong: 'rgb(var(--m-line-strong) / <alpha-value>)',
                },
                ink: {
                    DEFAULT: 'rgb(var(--m-ink) / <alpha-value>)',
                    muted: 'rgb(var(--m-ink-muted) / <alpha-value>)',
                    subtle: 'rgb(var(--m-ink-subtle) / <alpha-value>)',
                    inverse: 'rgb(var(--m-ink-inverse) / <alpha-value>)',
                },

                // One controlled primary accent.
                brand: {
                    50: 'rgb(var(--m-brand-50) / <alpha-value>)',
                    100: 'rgb(var(--m-brand-100) / <alpha-value>)',
                    200: 'rgb(var(--m-brand-200) / <alpha-value>)',
                    500: 'rgb(var(--m-brand-500) / <alpha-value>)',
                    600: 'rgb(var(--m-brand-600) / <alpha-value>)',
                    700: 'rgb(var(--m-brand-700) / <alpha-value>)',
                },

                // Restrained semantic colours. Never the only carrier of meaning.
                urgent: {
                    soft: 'rgb(var(--m-urgent-soft) / <alpha-value>)',
                    DEFAULT: 'rgb(var(--m-urgent) / <alpha-value>)',
                    ink: 'rgb(var(--m-urgent-ink) / <alpha-value>)',
                },
                warn: {
                    soft: 'rgb(var(--m-warn-soft) / <alpha-value>)',
                    DEFAULT: 'rgb(var(--m-warn) / <alpha-value>)',
                    ink: 'rgb(var(--m-warn-ink) / <alpha-value>)',
                },
                good: {
                    soft: 'rgb(var(--m-good-soft) / <alpha-value>)',
                    DEFAULT: 'rgb(var(--m-good) / <alpha-value>)',
                    ink: 'rgb(var(--m-good-ink) / <alpha-value>)',
                },
                info: {
                    soft: 'rgb(var(--m-info-soft) / <alpha-value>)',
                    DEFAULT: 'rgb(var(--m-info) / <alpha-value>)',
                    ink: 'rgb(var(--m-info-ink) / <alpha-value>)',
                },
            },

            borderRadius: {
                control: 'var(--m-radius-control)',
                panel: 'var(--m-radius-panel)',
            },

            fontSize: {
                micro: ['0.6875rem', { lineHeight: '1rem', letterSpacing: '0.04em' }],
            },

            spacing: {
                sidebar: 'var(--m-sidebar-width)',
                'sidebar-rail': 'var(--m-sidebar-rail)',
                topbar: 'var(--m-topbar-height)',
            },

            boxShadow: {
                panel: '0 1px 2px 0 rgb(15 23 42 / 0.04)',
                raised: '0 4px 12px -2px rgb(15 23 42 / 0.08), 0 2px 4px -2px rgb(15 23 42 / 0.04)',
                overlay: '0 16px 48px -12px rgb(15 23 42 / 0.24)',
            },

            zIndex: {
                sidebar: '40',
                topbar: '30',
                drawer: '50',
                dialog: '60',
                toast: '70',
            },
        },
    },

    plugins: [forms],
};
