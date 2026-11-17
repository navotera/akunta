import { onDestroy, tick, untrack } from 'svelte';
import { auth } from '$lib/stores/auth.svelte.js';
import { tenant } from '$lib/stores/tenant.svelte.js';

interface ReturnRecord<T> {
  id: string;
  context: T;
  scroll: Array<{ top: number; left: number }>;
  windowX: number;
  windowY: number;
}

/** Remember a list entry without changing its cells or its authorization. */
export function createListReturn<T>(
  name: string,
  context: () => T,
  restoreContext: (saved: T) => void,
  ready: () => boolean,
) {
  let armed = $state(false);
  let disposed = false;
  let cleanup = () => {};
  const key = () => `akunta.list-return.${auth.user?.id}.${tenant.id}.${name}`;
  const row = (id: string) =>
    Array.from(document.querySelectorAll<HTMLElement>('[data-return-row]')).find(
      (element) => element.dataset.returnRow === `${name}:${id}`,
    );
  function read(): ReturnRecord<T> | null {
    try {
      return JSON.parse(sessionStorage.getItem(key()) ?? 'null');
    } catch {
      return null;
    }
  }

  $effect(() => {
    if (!armed || !ready()) return;
    untrack(() => {
      void show();
    });
  });

  async function show() {
    armed = false;
    const saved = read();
    if (!saved) return;
    await tick();
    if (disposed || !ready()) {
      if (!disposed) armed = true;
      return;
    }
    const target = row(saved.id);
    if (!target) {
      sessionStorage.removeItem(key());
      return;
    }
    sessionStorage.removeItem(key());
    let parent = target.parentElement;
    let index = 0;
    while (parent) {
      const position = saved.scroll[index++];
      if (position) {
        parent.scrollTop = position.top;
        parent.scrollLeft = position.left;
      }
      parent = parent.parentElement;
    }
    window.scrollTo(saved.windowX, saved.windowY);
    // A saved edit can change ordering. Reveal the target vertically without
    // letting scrollIntoView move the table's columns horizontally.
    for (let ancestor = target.parentElement; ancestor; ancestor = ancestor.parentElement) {
      if (!/(auto|scroll)/.test(getComputedStyle(ancestor).overflowY)) continue;
      const bounds = target.getBoundingClientRect();
      const viewport = ancestor.getBoundingClientRect();
      if (bounds.top < viewport.top) ancestor.scrollTop += bounds.top - viewport.top;
      else if (bounds.bottom > viewport.bottom)
        ancestor.scrollTop += bounds.bottom - viewport.bottom;
    }
    const visibleBounds = target.getBoundingClientRect();
    if (visibleBounds.top < 0 || visibleBounds.bottom > innerHeight) {
      window.scrollTo(
        window.scrollX,
        window.scrollY + visibleBounds.top - (innerHeight - visibleBounds.height) / 2,
      );
    }
    cleanup();
    const overlay = document.createElement('div');
    overlay.dataset.testid = 'list-return-highlight';
    overlay.setAttribute('aria-hidden', 'true');
    Object.assign(overlay.style, {
      position: 'fixed',
      pointerEvents: 'none',
      zIndex: '25',
      background:
        'radial-gradient(ellipse 65% 160% at 18% 45%, rgba(27,132,255,.2), rgba(27,132,255,.08) 48%, transparent 80%), radial-gradient(ellipse 50% 140% at 72% 60%, rgba(0,190,255,.14), transparent 75%)',
    });
    const position = () => {
      const bounds = target.getBoundingClientRect();
      let left = Math.max(0, bounds.left),
        right = Math.min(innerWidth, bounds.right);
      let top = Math.max(0, bounds.top),
        bottom = Math.min(innerHeight, bounds.bottom);
      for (let ancestor = target.parentElement; ancestor; ancestor = ancestor.parentElement) {
        const style = getComputedStyle(ancestor);
        const clip = ancestor.getBoundingClientRect();
        if (/(auto|scroll|hidden)/.test(style.overflowX)) {
          left = Math.max(left, clip.left);
          right = Math.min(right, clip.right);
        }
        if (/(auto|scroll|hidden)/.test(style.overflowY)) {
          top = Math.max(top, clip.top);
          bottom = Math.min(bottom, clip.bottom);
        }
      }
      Object.assign(overlay.style, {
        left: `${left}px`,
        top: `${top}px`,
        width: `${Math.max(0, right - left)}px`,
        height: `${Math.max(0, bottom - top)}px`,
      });
    };
    position();
    document.body.append(overlay);
    const animation = overlay.animate(
      [{ opacity: 0 }, { opacity: 1, offset: 0.12 }, { opacity: 1, offset: 0.6 }, { opacity: 0 }],
      { duration: 1300, easing: 'ease-out', fill: 'forwards' },
    );
    document.addEventListener('scroll', position, true);
    window.addEventListener('resize', position);
    cleanup = () => {
      animation.cancel();
      overlay.remove();
      document.removeEventListener('scroll', position, true);
      window.removeEventListener('resize', position);
    };
    animation.onfinish = cleanup;
  }

  onDestroy(() => {
    disposed = true;
    cleanup();
  });
  return {
    remember(id: string) {
      cleanup();
      armed = false;
      const scroll: ReturnRecord<T>['scroll'] = [];
      for (let parent = row(id)?.parentElement; parent; parent = parent.parentElement)
        scroll.push({ top: parent.scrollTop, left: parent.scrollLeft });
      sessionStorage.setItem(
        key(),
        JSON.stringify({ id, context: context(), scroll, windowX: scrollX, windowY: scrollY }),
      );
    },
    restore() {
      const saved = read();
      if (saved) {
        restoreContext(saved.context);
        armed = true;
      }
    },
    resume() {
      armed = true;
    },
  };
}
