/**
 * Akunta design tokens — lifted from
 * apps/accounting/resources/css/filament/accounting/theme-metronic.css.
 * Source of truth for the SvelteKit SPA. Mirror the values into a CSS layer
 * for runtime use; this TS module is the single import path for components.
 */
export const tokens = {
  color: {
    primary: '#2563EB',
    primaryLight: '#DBEAFE',
    success: '#16A34A',
    successLight: '#DCFCE7',
    warning: '#F59E0B',
    warningLight: '#FEF3C7',
    danger: '#DC2626',
    dangerLight: '#FEE2E2',
    paid: '#16A34A',
    paidLight: '#DCFCE7',
    unpaid: '#DC2626',
    unpaidLight: '#FEE2E2',
    text: '#0F172A',
    textMuted: '#64748B',
    bgCard: '#FFFFFF',
    bgPage: '#F8FAFC',
    border: '#E2E8F0',
    borderSoft: '#EEF2F6',
    gray50: '#F8FAFC',
    gray100: '#F1F5F9',
    gray200: '#E2E8F0',
    gray300: '#CBD5E1',
    gray400: '#94A3B8',
    gray500: '#64748B',
    gray600: '#475569',
    gray700: '#334155',
    gray800: '#1E293B',
    gray900: '#0F172A',
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
    sm: '0 1px 3px rgba(15, 23, 42, 0.08), 0 1px 2px rgba(15, 23, 42, 0.04)',
    md: '0 4px 12px rgba(15, 23, 42, 0.08)',
    lg: '0 10px 24px rgba(15, 23, 42, 0.12)',
  },
  font: {
    sans: 'Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
    mono: '"JetBrains Mono", ui-monospace, SFMono-Regular, "Menlo", monospace',
  },
} as const;

export type Tokens = typeof tokens;
