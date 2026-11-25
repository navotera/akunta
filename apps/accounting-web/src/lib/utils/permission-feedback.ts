import { toast } from '$lib/stores/toast.svelte.js';

/**
 * Wording shared by every control that reacts to a missing permission.
 *
 * UI convention (see docs/permission-management.md):
 *  - an action the user can never run is hidden;
 *  - a detail form opened without edit rights shows a read-only notice;
 *  - a control that must stay visible answers a click with this message.
 * The API remains the authorization boundary in every case.
 */
const ASK_ADMIN = 'Hubungi admin workspace untuk meminta akses.';

/** `action` completes the sentence "izin untuk …", e.g. "membuat akun". */
export function permissionDeniedMessage(action: string): string {
  return `Anda belum memiliki izin untuk ${action}. ${ASK_ADMIN}`;
}

export function readOnlyNotice(action: string): string {
  return `Mode baca saja. ${permissionDeniedMessage(action)}`;
}

let activeToastId: string | null = null;

/** Replaces the previous denial toast so repeated clicks do not stack notices. */
export function notifyPermissionDenied(message: string): void {
  if (activeToastId) toast.dismiss(activeToastId);
  activeToastId = toast.warning(message, { title: 'Akses dibatasi' });
}
