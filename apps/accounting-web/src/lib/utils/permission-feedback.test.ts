import { beforeEach, describe, expect, it } from 'vitest';
import { toast } from '$lib/stores/toast.svelte.js';
import {
  notifyPermissionDenied,
  permissionDeniedMessage,
  readOnlyNotice,
} from './permission-feedback.js';

beforeEach(() => toast.clear());

describe('permission feedback', () => {
  it('names the missing action and whom to ask', () => {
    expect(permissionDeniedMessage('membuat akun')).toBe(
      'Anda belum memiliki izin untuk membuat akun. Hubungi admin workspace untuk meminta akses.',
    );
    expect(readOnlyNotice('mengedit jurnal')).toBe(
      'Mode baca saja. Anda belum memiliki izin untuk mengedit jurnal. Hubungi admin workspace untuk meminta akses.',
    );
  });

  it('answers a denied click with a single warning, even when clicked repeatedly', () => {
    notifyPermissionDenied(permissionDeniedMessage('mereview jurnal'));
    notifyPermissionDenied(permissionDeniedMessage('menyetujui jurnal'));

    expect(toast.items).toHaveLength(1);
    expect(toast.items[0]).toMatchObject({
      kind: 'warning',
      title: 'Akses dibatasi',
      message: permissionDeniedMessage('menyetujui jurnal'),
    });
  });
});
