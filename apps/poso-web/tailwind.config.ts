import type { Config } from 'tailwindcss';

export default {
  content: ['./src/**/*.{html,js,svelte,ts}'],
  theme: {
    extend: {
      colors: {
        ink: '#0F172A',
        muted: '#64748B',
        line: '#E2E8F0',
        soft: '#F8FAFC',
        panel: '#FFFFFF',
        blue: '#2563EB',
        'blue-soft': '#DBEAFE',
        green: '#16A34A',
        'green-soft': '#DCFCE7',
        amber: '#F59E0B',
        'amber-soft': '#FEF3C7',
        red: '#DC2626',
        'red-soft': '#FEE2E2',
        violet: '#7C3AED',
        'violet-soft': '#EDE9FE'
      },
      borderRadius: {
        poso: '8px'
      },
      boxShadow: {
        poso: '0 1px 2px rgba(15, 23, 42, 0.04), 0 10px 24px rgba(15, 23, 42, 0.05)'
      }
    }
  },
  plugins: []
} satisfies Config;

