import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { accessDenied } from '$lib/stores/access-denied.svelte.js';
import { api, ApiError, isEcopaIntegrationEnabled } from './client.js';

beforeEach(() => {
  accessDenied.clear();
  const values = new Map<string, string>();
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => values.set(key, value),
    removeItem: (key: string) => values.delete(key),
  });
});

afterEach(() => vi.unstubAllGlobals());

describe('ApiError', () => {
  it('uses the server business message instead of exposing the HTTP status', () => {
    const error = new ApiError(403, { message: 'Anda tidak memiliki izin untuk aksi ini.' });

    expect(error.message).toBe('Anda tidak memiliki izin untuk aksi ini.');
    expect(error.status).toBe(403);
  });

  it('falls back to the first validation error when no top-level message exists', () => {
    const error = new ApiError(422, { errors: { name: ['Nama wajib diisi.'] } });

    expect(error.message).toBe('Nama wajib diisi.');
  });

  it('promotes a 403 response to the global access-denied state', async () => {
    localStorage.setItem('akunta.active_entity_id', 'entity-a');
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(JSON.stringify({ message: 'Anda tidak memiliki izin untuk aksi ini.' }), {
          status: 403,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    );

    await expect(api('/api/v1/spa/journals')).rejects.toMatchObject({ status: 403 });

    expect(accessDenied.detail).toMatchObject({
      message: 'Anda tidak memiliki izin untuk aksi ini.',
      entityId: 'entity-a',
      requestPath: '/api/v1/spa/journals',
      method: 'GET',
    });
  });
});

describe('Ecopa integration entity scope', () => {
  it('reads the toggle only from the active entity namespace', () => {
    localStorage.setItem('akunta.ecopa.integration.entity-a', 'on');
    localStorage.setItem('akunta.ecopa.integration.entity-b', 'off');

    localStorage.setItem('akunta.active_entity_id', 'entity-a');
    expect(isEcopaIntegrationEnabled()).toBe(true);

    localStorage.setItem('akunta.active_entity_id', 'entity-b');
    expect(isEcopaIntegrationEnabled()).toBe(false);
  });
});
