export type WorkspaceTheme =
  | 'blue'
  | 'emerald'
  | 'violet'
  | 'orange'
  | 'rose'
  | 'cyan'
  | 'indigo'
  | 'teal'
  | 'amber'
  | 'custom';

const STORAGE_PREFIX = 'akunta.workspace.theme.';
const CUSTOM_COLOR_PREFIX = 'akunta.workspace.theme.custom.';

type PresetTheme = Exclude<WorkspaceTheme, 'custom'>;

const themes: Record<
  PresetTheme,
  {
    label: string;
    primary: string;
    light: string;
    active: string;
    sidebar: string;
    sidebarGradient: string;
    gradient: string;
  }
> = {
  blue: {
    label: 'Ocean',
    primary: '#1b84ff',
    light: '#eff6ff',
    active: '#056ee9',
    sidebar: '#081f45',
    sidebarGradient: 'linear-gradient(145deg, #061633 0%, #064e6b 100%)',
    gradient: 'linear-gradient(135deg, #2563eb, #06b6d4)',
  },
  emerald: {
    label: 'Forest',
    primary: '#10b981',
    light: '#ecfdf5',
    active: '#047857',
    sidebar: '#022c22',
    sidebarGradient: 'linear-gradient(145deg, #022c22 0%, #365314 100%)',
    gradient: 'linear-gradient(135deg, #059669, #84cc16)',
  },
  violet: {
    label: 'Aurora',
    primary: '#8b5cf6',
    light: '#f5f3ff',
    active: '#6d28d9',
    sidebar: '#250441',
    sidebarGradient: 'linear-gradient(145deg, #18002e 0%, #701a4d 100%)',
    gradient: 'linear-gradient(135deg, #7c3aed, #ec4899)',
  },
  orange: {
    label: 'Sunset',
    primary: '#f97316',
    light: '#fff7ed',
    active: '#c2410c',
    sidebar: '#431407',
    sidebarGradient: 'linear-gradient(145deg, #431407 0%, #713f12 100%)',
    gradient: 'linear-gradient(135deg, #f97316, #facc15)',
  },
  rose: {
    label: 'Berry',
    primary: '#f43f5e',
    light: '#fff1f2',
    active: '#be123c',
    sidebar: '#4c0519',
    sidebarGradient: 'linear-gradient(145deg, #4c0519 0%, #3b0764 100%)',
    gradient: 'linear-gradient(135deg, #e11d48, #a855f7)',
  },
  cyan: {
    label: 'Lagoon',
    primary: '#0891b2',
    light: '#ecfeff',
    active: '#0e7490',
    sidebar: '#083344',
    sidebarGradient: 'linear-gradient(145deg, #083344 0%, #172554 100%)',
    gradient: 'linear-gradient(135deg, #0891b2, #3b82f6)',
  },
  indigo: {
    label: 'Indigo',
    primary: '#6366f1',
    light: '#eef2ff',
    active: '#4338ca',
    sidebar: '#1e1b4b',
    sidebarGradient: 'linear-gradient(145deg, #1e1b4b 0%, #3b0764 100%)',
    gradient: 'linear-gradient(135deg, #4f46e5, #9333ea)',
  },
  teal: {
    label: 'Teal',
    primary: '#0d9488',
    light: '#f0fdfa',
    active: '#0f766e',
    sidebar: '#042f2e',
    sidebarGradient: 'linear-gradient(145deg, #042f2e 0%, #052e16 100%)',
    gradient: 'linear-gradient(135deg, #0f766e, #14b8a6)',
  },
  amber: {
    label: 'Amber',
    primary: '#d97706',
    light: '#fffbeb',
    active: '#b45309',
    sidebar: '#451a03',
    sidebarGradient: 'linear-gradient(145deg, #451a03 0%, #78350f 100%)',
    gradient: 'linear-gradient(135deg, #d97706, #f59e0b)',
  },
};

function storageKey(workspaceId: string): string {
  return `${STORAGE_PREFIX}${workspaceId}`;
}

function customColorKey(workspaceId: string): string {
  return `${CUSTOM_COLOR_PREFIX}${workspaceId}`;
}

export function workspaceThemes(): Array<{ value: WorkspaceTheme; label: string; color: string }> {
  return Object.entries(themes).map(([value, theme]) => ({
    value: value as WorkspaceTheme,
    label: theme.label,
    color: theme.gradient,
  }));
}

export function getWorkspaceTheme(workspaceId: string | null): WorkspaceTheme {
  if (!workspaceId || typeof localStorage === 'undefined') return 'blue';
  const value = localStorage.getItem(storageKey(workspaceId)) as WorkspaceTheme | null;
  return value === 'custom' || (value && value in themes) ? value : 'blue';
}

export function applyWorkspaceTheme(
  workspaceId: string | null,
  value: string = getWorkspaceTheme(workspaceId),
): void {
  if (typeof document === 'undefined') return;
  const customColor =
    workspaceId && typeof localStorage !== 'undefined'
      ? localStorage.getItem(customColorKey(workspaceId))
      : null;
  const theme =
    value === 'custom' && customColor
      ? createCustomTheme(customColor)
      : /^#[0-9a-f]{6}$/i.test(value)
        ? createCustomTheme(value)
        : value in themes
          ? themes[value as PresetTheme]
          : themes.blue;
  document.documentElement.style.setProperty('--m-primary', theme.primary);
  document.documentElement.style.setProperty('--m-primary-light', theme.light);
  document.documentElement.style.setProperty('--m-primary-active', theme.active);
  document.documentElement.style.setProperty('--m-sidebar-bg', theme.sidebar);
  document.documentElement.style.setProperty('--m-sidebar-gradient', theme.sidebarGradient);
  document.documentElement.style.setProperty('--m-sidebar-text', '#ffffff');
  document.documentElement.style.setProperty('--m-sidebar-muted', 'rgba(255, 255, 255, 0.72)');
}

export function setWorkspaceTheme(
  workspaceId: string | null,
  value: WorkspaceTheme | string,
): void {
  if (!workspaceId) return;
  if (typeof localStorage !== 'undefined') localStorage.setItem(storageKey(workspaceId), value);
  applyWorkspaceTheme(workspaceId, value);
}

export function getWorkspaceCustomColor(workspaceId: string | null): string {
  if (!workspaceId || typeof localStorage === 'undefined') return '#1b84ff';
  return localStorage.getItem(customColorKey(workspaceId)) ?? '#1b84ff';
}

export function setWorkspaceCustomColor(workspaceId: string | null, color: string): void {
  if (!workspaceId || !/^#[0-9a-f]{6}$/i.test(color)) return;
  if (typeof localStorage !== 'undefined') localStorage.setItem(customColorKey(workspaceId), color);
  setWorkspaceTheme(workspaceId, 'custom');
}

function createCustomTheme(color: string): {
  primary: string;
  light: string;
  active: string;
  sidebar: string;
  sidebarGradient: string;
} {
  const { r, g, b } = hexToRgb(color);
  const mix = (target: number, amount: number) => Math.round(target + (255 - target) * amount);
  const shade = (target: number, amount: number) => Math.round(target * (1 - amount));
  return {
    primary: color,
    light: `rgb(${mix(r, 0.9)}, ${mix(g, 0.9)}, ${mix(b, 0.9)})`,
    active: `rgb(${shade(r, 0.2)}, ${shade(g, 0.2)}, ${shade(b, 0.2)})`,
    sidebar: `rgb(${mix(r, 0.97)}, ${mix(g, 0.97)}, ${mix(b, 0.97)})`,
    sidebarGradient: `linear-gradient(145deg, rgb(${shade(r, 0.62)}, ${shade(g, 0.62)}, ${shade(b, 0.62)}) 0%, rgb(${shade(r, 0.35)}, ${shade(g, 0.35)}, ${shade(b, 0.35)}) 100%)`,
  };
}

function hexToRgb(color: string): { r: number; g: number; b: number } {
  const value = color.slice(1);
  return {
    r: Number.parseInt(value.slice(0, 2), 16),
    g: Number.parseInt(value.slice(2, 4), 16),
    b: Number.parseInt(value.slice(4, 6), 16),
  };
}
