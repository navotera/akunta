/** Global state for the command palette (Cmd+K) modal. */
class PaletteStore {
  open = $state(false);

  toggle(): void {
    this.open = !this.open;
  }

  show(): void {
    this.open = true;
  }

  hide(): void {
    this.open = false;
  }
}

export const palette = new PaletteStore();
