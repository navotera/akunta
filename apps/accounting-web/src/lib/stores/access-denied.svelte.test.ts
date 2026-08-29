import { beforeEach, describe, expect, it } from 'vitest';
import { accessDenied } from './access-denied.svelte.js';

const denial = {
  message: 'Anda tidak memiliki izin untuk aksi ini.',
  routeKey: '/journals/new',
  entityId: 'entity-a',
  requestPath: '/api/v1/spa/journals',
  method: 'POST',
};

beforeEach(() => accessDenied.clear());

describe('accessDenied', () => {
  it('keeps a denial while the user remains on the same route and entity', () => {
    accessDenied.show(denial);
    accessDenied.clearIfContextChanged('/journals/new', 'entity-a');

    expect(accessDenied.active).toBe(true);
    expect(accessDenied.detail?.message).toBe(denial.message);
  });

  it('clears a denial when navigating to another route', () => {
    accessDenied.show(denial);
    accessDenied.clearIfContextChanged('/akun', 'entity-a');

    expect(accessDenied.active).toBe(false);
  });

  it('clears a denial when switching entity', () => {
    accessDenied.show(denial);
    accessDenied.clearIfContextChanged('/journals/new', 'entity-b');

    expect(accessDenied.active).toBe(false);
  });
});
