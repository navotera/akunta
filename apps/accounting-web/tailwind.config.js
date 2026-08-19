import { tokens } from '../../packages/akunta-ui/src/tokens/index.ts';

/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './src/**/*.{html,js,svelte,ts}',
    '../../packages/akunta-ui/src/**/*.{js,ts,svelte}',
  ],
  theme: {
    extend: {
      colors: {
        primary: 'var(--m-primary)',
        'primary-light': 'var(--m-primary-light)',
        'primary-active': 'var(--m-primary-active)',
        success: tokens.color.success,
        'success-light': tokens.color.successLight,
        danger: tokens.color.danger,
        'danger-light': tokens.color.dangerLight,
        warning: tokens.color.warning,
        'warning-light': tokens.color.warningLight,
        info: tokens.color.info,
        'info-light': tokens.color.infoLight,
        paid: tokens.color.paid,
        'paid-light': tokens.color.paidLight,
        unpaid: tokens.color.unpaid,
        'unpaid-light': tokens.color.unpaidLight,
        'text-default': tokens.color.text,
        'text-muted': tokens.color.textMuted,
        'text-strong': tokens.color.textStrong,
        'card-bg': tokens.color.bgCard,
        'page-bg': tokens.color.bgPage,
        'sidebar-bg': 'var(--m-sidebar-bg)',
        'topbar-bg': tokens.color.bgTopbar,
        'border-default': tokens.color.border,
        'border-soft': tokens.color.borderSoft,
        gray: {
          50: tokens.color.gray50,
          100: tokens.color.gray100,
          200: tokens.color.gray200,
          300: tokens.color.gray300,
          400: tokens.color.gray400,
          500: tokens.color.gray500,
          600: tokens.color.gray600,
          700: tokens.color.gray700,
          800: tokens.color.gray800,
          900: tokens.color.gray900,
        },
      },
      borderRadius: tokens.radius,
      fontFamily: {
        sans: tokens.font.sans.split(','),
        mono: tokens.font.mono.split(','),
      },
      boxShadow: tokens.shadow,
    },
  },
  plugins: [],
};
