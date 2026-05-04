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
        primary: tokens.color.primary,
        'primary-light': tokens.color.primaryLight,
        success: tokens.color.success,
        'success-light': tokens.color.successLight,
        danger: tokens.color.danger,
        'danger-light': tokens.color.dangerLight,
        warning: tokens.color.warning,
        'warning-light': tokens.color.warningLight,
        paid: tokens.color.paid,
        'paid-light': tokens.color.paidLight,
        unpaid: tokens.color.unpaid,
        'unpaid-light': tokens.color.unpaidLight,
        'text-default': tokens.color.text,
        'text-muted': tokens.color.textMuted,
        'card-bg': tokens.color.bgCard,
        'page-bg': tokens.color.bgPage,
        'border-default': tokens.color.border,
        'border-soft': tokens.color.borderSoft,
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
