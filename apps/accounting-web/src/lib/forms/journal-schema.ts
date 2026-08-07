import { z } from 'zod';

const entryRow = z.object({
  account_id: z.string().length(26, 'Pilih akun'),
  amount: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Nominal tidak valid'),
  memo: z.string().max(255).nullable().default(null),
});

export const journalSchema = z
  .object({
    number: z.string().max(40).optional(),
    date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Tanggal tidak valid'),
    memo: z.string().min(1, 'Keterangan wajib').max(400),
    reference: z.string().max(120).nullable().default(null),
    type: z.string().default('general'),
    entries_debit: z.array(entryRow).min(1, 'Minimal 1 baris debit'),
    entries_credit: z.array(entryRow).min(1, 'Minimal 1 baris kredit'),
  })
  .superRefine((data, ctx) => {
    const sum = (rows: typeof data.entries_debit) =>
      rows.reduce((acc, r) => acc + Number(r.amount || 0), 0);
    const d = sum(data.entries_debit);
    const c = sum(data.entries_credit);
    if (Math.abs(d - c) >= 0.005 || d <= 0) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: 'Debit harus sama dengan kredit dan > 0',
        path: ['entries_debit'],
      });
    }
  });

export type JournalForm = z.infer<typeof journalSchema>;
