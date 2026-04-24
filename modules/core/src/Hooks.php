<?php

declare(strict_types=1);

namespace Akunta\Core;

/**
 * Catalog of v1 minimum hooks (spec §6.1).
 * Naming convention: {resource}.before_{action} / {resource}.after_{action} / {resource}.{action}_failed
 */
final class Hooks
{
    public const JOURNAL_BEFORE_CREATE  = 'journal.before_create';
    public const JOURNAL_AFTER_CREATE   = 'journal.after_create';
    public const JOURNAL_BEFORE_POST    = 'journal.before_post';
    public const JOURNAL_AFTER_POST     = 'journal.after_post';
    public const JOURNAL_BEFORE_REVERSE = 'journal.before_reverse';
    public const JOURNAL_AFTER_REVERSE  = 'journal.after_reverse';

    public const PERIOD_BEFORE_CLOSE = 'period.before_close';
    public const PERIOD_AFTER_CLOSE  = 'period.after_close';

    public const PAYROLL_BEFORE_APPROVE = 'payroll.before_approve';
    public const PAYROLL_AFTER_APPROVE  = 'payroll.after_approve';
    public const PAYROLL_BEFORE_PAY     = 'payroll.before_pay';
    public const PAYROLL_AFTER_PAY      = 'payroll.after_pay';

    public const EXPENSE_BEFORE_APPROVE = 'expense.before_approve';
    public const EXPENSE_AFTER_APPROVE  = 'expense.after_approve';
    public const EXPENSE_BEFORE_PAY     = 'expense.before_pay';
    public const EXPENSE_AFTER_PAY      = 'expense.after_pay';

    public const PAYMENT_BEFORE_EXECUTE = 'payment.before_execute';
    public const PAYMENT_AFTER_EXECUTE  = 'payment.after_execute';

    public const USER_ROLE_ASSIGNED = 'user.role_assigned';
    public const USER_ROLE_REVOKED  = 'user.role_revoked';

    public const TENANT_BEFORE_PROVISION = 'tenant.before_provision';
    public const TENANT_AFTER_PROVISION  = 'tenant.after_provision';
}
