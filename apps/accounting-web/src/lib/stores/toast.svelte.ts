export type ToastKind = 'success' | 'error' | 'info' | 'warning';

export interface Toast {
  id: string;
  kind: ToastKind;
  title?: string;
  message: string;
  ttl: number;
}

export interface ToastOptions {
  title?: string;
  /** Milliseconds before auto-dismiss. 0 = sticky. Defaults: success/info/warning 4000, error 6000. */
  ttl?: number;
}

const state = $state<{ items: Toast[] }>({ items: [] });
const timers = new Map<string, ReturnType<typeof setTimeout>>();

function nextId(): string {
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}

function defaultTtl(kind: ToastKind): number {
  return kind === 'error' ? 6000 : 4000;
}

function push(kind: ToastKind, message: string, opts: ToastOptions = {}): string {
  const id = nextId();
  const ttl = opts.ttl ?? defaultTtl(kind);
  state.items = [...state.items, { id, kind, message, title: opts.title, ttl }];
  if (ttl > 0) {
    const t = setTimeout(() => dismiss(id), ttl);
    timers.set(id, t);
  }
  return id;
}

function dismiss(id: string): void {
  const t = timers.get(id);
  if (t) {
    clearTimeout(t);
    timers.delete(id);
  }
  state.items = state.items.filter((it) => it.id !== id);
}

export const toast = {
  get items(): Toast[] {
    return state.items;
  },
  success(message: string, opts?: ToastOptions): string {
    return push('success', message, opts);
  },
  error(message: string, opts?: ToastOptions): string {
    return push('error', message, opts);
  },
  info(message: string, opts?: ToastOptions): string {
    return push('info', message, opts);
  },
  warning(message: string, opts?: ToastOptions): string {
    return push('warning', message, opts);
  },
  dismiss,
  clear(): void {
    for (const id of [...timers.keys()]) dismiss(id);
  },
};

/** Best-effort error-to-message extractor for ApiError-like objects. */
export function toastApiError(err: unknown, fallback = 'Terjadi kesalahan'): string {
  if (err && typeof err === 'object') {
    const body = (err as { body?: unknown }).body;
    if (body && typeof body === 'object') {
      const msg = (body as { message?: unknown }).message;
      if (typeof msg === 'string' && msg.length > 0) return toast.error(msg);
    }
    const msg = (err as { message?: unknown }).message;
    if (typeof msg === 'string' && msg.length > 0) return toast.error(msg);
  }
  return toast.error(fallback);
}
