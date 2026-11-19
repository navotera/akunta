export type JournalAttachmentView = 'thumbnail' | 'icon';

const state = $state<{ userId: string | null; value: JournalAttachmentView }>({
  userId: null,
  value: 'thumbnail',
});

function storageKey(userId: string): string {
  return `akunta.journal-attachment-view.${userId}`;
}

export const journalAttachmentView = {
  get value(): JournalAttachmentView {
    return state.value;
  },

  hydrate(userId: string | null): void {
    if (state.userId === userId) return;
    state.userId = userId;
    const saved =
      userId && typeof localStorage !== 'undefined'
        ? localStorage.getItem(storageKey(userId))
        : null;
    state.value = saved === 'icon' ? 'icon' : 'thumbnail';
  },

  set(value: JournalAttachmentView): void {
    state.value = value;
    if (state.userId && typeof localStorage !== 'undefined') {
      localStorage.setItem(storageKey(state.userId), value);
    }
  },
};
