import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, isEcopaIntegrationEnabled } from './client.js';

beforeEach(() => {
  const values = new Map<string, string>();
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => values.set(key, value),
    removeItem: (key: string) => values.delete(key),
  });
});

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
