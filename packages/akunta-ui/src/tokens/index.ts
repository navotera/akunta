/**
 * Akunta design tokens — lifted from the prior
 * apps/accounting/resources/css/filament/accounting/theme-metronic.css.
 * Source of truth for the SvelteKit SPA. Same values are exposed as CSS
 * custom properties at runtime via app.css.
 */
export const tokens = {
  color: {
    // Metronic Demo3 — primary
    primary: '#1B84FF',
    primaryLight: '#EFF6FF',
    primaryActive: '#056EE9',

    // Semantics
    success: '#17C653',
    successLight: '#DFFFEA',
    warning: '#F6C000',
    warningLight: '#FFF8DD',
    danger: '#F8285A',
    dangerLight: '#FFEEF3',
    info: '#7239EA',
    infoLight: '#F1E6FF',

    // Domain aliases (paid/unpaid — used across status pills)
    paid: '#17C653',
    paidLight: '#DFFFEA',
    unpaid: '#F8285A',
    unpaidLight: '#FFEEF3',

    text: '#252F4A',
    textMuted: '#78829D',
    textStrong: '#071437',
    bgCard: '#FFFFFF',
    bgPage: '#FAFAFB',
    bgSidebar: '#FFFFFF',
    bgTopbar: '#FFFFFF',
    border: '#DBDFE9',
    borderSoft: '#E5E7EB',

    // Metronic gray scale
    gray50: '#FAFAFB',
    gray100: '#F1F1F4',
    gray200: '#DBDFE9',
    gray300: '#C4CADA',
    gray400: '#99A1B7',
    gray500: '#78829D',
    gray600: '#4B5675',
    gray700: '#252F4A',
    gray800: '#15182E',
    gray900: '#071437',
  },
  spacing: {
    xs: '0.25rem',
    sm: '0.5rem',
    md: '0.75rem',
    lg: '1rem',
    xl: '1.5rem',
    xxl: '2rem',
  },
  radius: {
    xs: '0.25rem',
    sm: '0.375rem',
    md: '0.5rem',
    lg: '0.75rem',
  },
  shadow: {
    xs: '0 1px 2px rgba(15, 23, 42, 0.04)',
    sm: '0 2px 4px rgba(15, 23, 42, 0.05)',
    md: '0 4px 13px rgba(15, 23, 42, 0.07)',
    lg: '0 12px 28px rgba(15, 23, 42, 0.12)',
    focus: '0 0 0 3px rgba(27, 132, 255, 0.18)',
  },
  font: {
    sans: '"Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
    mono: '"JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace',
  },
} as const;

export type Tokens = typeof tokens;
