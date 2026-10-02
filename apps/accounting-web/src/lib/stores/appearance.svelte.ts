export type ColorMode = 'light' | 'dark';

const STORAGE_KEY = 'akunta.color-mode';

export function getColorMode(): ColorMode {
  if (typeof localStorage === 'undefined') return 'light';

  return localStorage.getItem(STORAGE_KEY) === 'dark' ? 'dark' : 'light';
}

export function applyColorMode(mode: ColorMode): void {
  if (typeof document === 'undefined') return;

  document.documentElement.dataset.colorMode = mode;
  document.documentElement.style.colorScheme = mode;
}

export function setColorMode(mode: ColorMode): void {
  if (typeof localStorage !== 'undefined') localStorage.setItem(STORAGE_KEY, mode);
  applyColorMode(mode);
}
