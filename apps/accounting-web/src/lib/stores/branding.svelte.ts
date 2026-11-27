import { brandingApi, type Branding } from '$lib/api/branding.js';

const DEFAULT_BRANDING: Branding = { app_name: 'Akunta', logo_url: null };

/**
 * Application name and logo: one per installation, set by the Platform Owner.
 * Falls back to the built-in "Akunta" when the backend cannot be reached, so
 * the shell and the login page always have something to show.
 */
class BrandingStore {
  value = $state<Branding>({ ...DEFAULT_BRANDING });
  loaded = $state(false);

  get appName(): string {
    return this.value.app_name || DEFAULT_BRANDING.app_name;
  }

  get logoUrl(): string | null {
    return this.value.logo_url;
  }

  async load(): Promise<void> {
    try {
      this.value = await brandingApi.get();
    } catch {
      this.value = { ...DEFAULT_BRANDING };
    } finally {
      this.loaded = true;
    }
  }

  set(next: Branding): void {
    this.value = next;
    this.loaded = true;
  }
}

export const branding = new BrandingStore();
