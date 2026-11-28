-- Multiplies the ledger of a DISPOSABLE v1 (pre-migration) database tenfold, for the volume rehearsal
-- (docs/ecopa-multi-org-migration.md section 4a, docs/release-readiness-multi-org.md section 1).
--
--   psql -d <disposable copy of the v1 dump> -f scripts/rehearsal-multiply-volume.sql
--
-- Each copy gets new ids, a number/idempotency key/transaction code suffix (the unique indexes stay
-- valid) and no reversal link; entries keep their accounts and amounts, so every journal still balances
-- and every report total is exactly (copies + 1) times the original. Attachments are not copied.
-- Never run this against a database that holds real data: it only adds rows, but they are fake.
DO $$
DECLARE
    k int;
    copies constant int := 9; -- 9 copies + the original = 10x
BEGIN
    FOR k IN 1..copies LOOP
        INSERT INTO journals (id, entity_id, period_id, type, number, date, reference, memo, source_app, source_id,
                              idempotency_key, status, posted_at, posted_by, reversed_by_journal_id, created_by,
                              created_at, updated_at, auto_reverse_on, template_id, journal_mode, review_note,
                              reviewed_by, reviewed_at, auto_mapping_raw_data_id, auto_mapping_rule_id,
                              transaction_code, input_group_id, deleted_at)
        SELECT upper(substr(md5(id || k::text), 1, 26)), entity_id, period_id, type, number || '-X' || k, date,
               reference, memo, source_app, source_id,
               CASE WHEN idempotency_key IS NULL THEN NULL ELSE idempotency_key || '-x' || k END,
               status, posted_at, posted_by, NULL, created_by, created_at, updated_at, auto_reverse_on, template_id,
               journal_mode, review_note, reviewed_by, reviewed_at, auto_mapping_raw_data_id, auto_mapping_rule_id,
               CASE WHEN transaction_code IS NULL THEN NULL ELSE transaction_code || '-x' || k END,
               input_group_id, deleted_at
        FROM journals
        WHERE number NOT LIKE '%-X%';

        INSERT INTO journal_entries (id, journal_id, line_no, account_id, debit, credit, memo, metadata,
                                     cost_center_id, project_id, branch_id, tax_code_id, tax_base, source_app,
                                     source_ref_type, source_ref_id)
        SELECT upper(substr(md5(e.id || k::text), 1, 26)), upper(substr(md5(e.journal_id || k::text), 1, 26)),
               e.line_no, e.account_id, e.debit, e.credit, e.memo, e.metadata, e.cost_center_id, e.project_id,
               e.branch_id, e.tax_code_id, e.tax_base, e.source_app, e.source_ref_type, e.source_ref_id
        FROM journal_entries e
        JOIN journals j ON j.id = e.journal_id
        WHERE j.number NOT LIKE '%-X%';
    END LOOP;
END $$;

ANALYZE;

SELECT (SELECT count(*) FROM journals) AS journals,
       (SELECT count(*) FROM journal_entries) AS entries,
       pg_size_pretty(pg_database_size(current_database())) AS size;
