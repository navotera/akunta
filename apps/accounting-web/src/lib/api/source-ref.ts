import { api } from './client.js';

/**
 * Source-ref registry — generic cross-app meta ingest. Drives the
 * filter dropdown UI and the "Buku Pembantu" report. See
 * docs/source-refs.md for the convention registry of (source_app,
 * ref_type) pairs that secondary apps publish into akunta.
 */

export interface SourceRefRegistryItem {
  source_app: string;
  ref_type: string;
  ref_id: string;
  code: string | null;
  label: string | null;
  entry_count: number;
  last_seen_at: string | null;
}

export interface SourceRefAggregateRow {
  ref_id: string;
  code: string | null;
  label: string | null;
  total_debit: string;
  total_credit: string;
  net: string;
  entry_count: number;
}

export interface SourceRefAggregateMeta {
  source_app: string;
  ref_type: string;
  period_start: string;
  period_end: string;
  totals: { debit: string; credit: string };
}

export const sourceRefApi = {
  list: (
    filter: { source_app?: string; ref_type?: string; q?: string } = {},
    tenantSlug?: string | null,
  ) => {
    const params = new URLSearchParams();
    if (filter.source_app) params.set('source_app', filter.source_app);
    if (filter.ref_type) params.set('ref_type', filter.ref_type);
    if (filter.q) params.set('q', filter.q);
    const qs = params.toString();
    return api<{ data: SourceRefRegistryItem[] }>(
      `/api/v1/spa/source-refs${qs ? `?${qs}` : ''}`,
      { tenantSlug },
    ).then((r) => r.data);
  },

  aggregate: (
    sourceApp: string,
    refType: string,
    periodStart: string,
    periodEnd: string,
    accountId?: string | null,
    tenantSlug?: string | null,
  ) => {
    const params = new URLSearchParams({
      source_app: sourceApp,
      ref_type: refType,
      period_start: periodStart,
      period_end: periodEnd,
    });
    if (accountId) params.set('account_id', accountId);
    return api<{ data: SourceRefAggregateRow[]; meta: SourceRefAggregateMeta }>(
      `/api/v1/spa/reports/by-source-ref?${params.toString()}`,
      { tenantSlug },
    );
  },
};
