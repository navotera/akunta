import { periodApi, type Period } from '$lib/api/period.js';

const STORAGE_KEY = 'akunta.active_period_id';

interface PeriodState {
  available: Period[];
  activeId: string | null;
  loading: boolean;
}

const state = $state<PeriodState>({
  available: [],
  activeId: null,
  loading: false,
});

function read(): string | null {
  if (typeof localStorage === 'undefined') return null;
  return localStorage.getItem(STORAGE_KEY);
}

function write(id: string | null): void {
  if (typeof localStorage === 'undefined') return;
  if (id) localStorage.setItem(STORAGE_KEY, id);
  else localStorage.removeItem(STORAGE_KEY);
}

function todayCovers(p: Period): boolean {
  const today = new Date().toISOString().slice(0, 10);
  return p.start_date <= today && today <= p.end_date;
}

export const period = {
  get available() {
    return state.available;
  },
  get activeId() {
    return state.activeId;
  },
  get active() {
    return state.available.find((p) => p.id === state.activeId) ?? null;
  },
  get loading() {
    return state.loading;
  },
  /** Group periods by year, descending. */
  get byYear(): Array<{ year: number; periods: Period[] }> {
    const grouped = new Map<number, Period[]>();
    for (const p of state.available) {
      const y = parseInt(p.start_date.slice(0, 4), 10);
      if (!grouped.has(y)) grouped.set(y, []);
      grouped.get(y)!.push(p);
    }
    return Array.from(grouped.entries())
      .sort(([a], [b]) => b - a)
      .map(([year, periods]) => ({
        year,
        periods: periods.sort((a, b) => b.start_date.localeCompare(a.start_date)),
      }));
  },

  async refresh(): Promise<void> {
    state.loading = true;
    try {
      const items = await periodApi.list();
      state.available = items;

      const stored = read();
      const valid = items.find((p) => p.id === stored && p.status === 'open');
      if (valid) {
        state.activeId = valid.id;
        return;
      }
      // Prefer the period covering today + status open.
      const todays = items.find((p) => todayCovers(p) && p.status === 'open');
      const fallback = todays ?? items.find((p) => p.status === 'open') ?? items[0] ?? null;
      state.activeId = fallback?.id ?? null;
      write(state.activeId);
    } catch {
      state.available = [];
      state.activeId = null;
    } finally {
      state.loading = false;
    }
  },

  switch(id: string): void {
    const target = state.available.find((p) => p.id === id);
    if (!target || target.status !== 'open') return;
    state.activeId = target.id;
    write(target.id);
  },

  clear(): void {
    state.available = [];
    state.activeId = null;
    write(null);
  },
};
