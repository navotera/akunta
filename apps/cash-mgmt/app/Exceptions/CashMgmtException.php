<?php

namespace App\Exceptions;

use RuntimeException;

class CashMgmtException extends RuntimeException
{
    public static function notDraft(string $status): self
    {
        return new self("Expense must be in draft status to approve; current [{$status}].");
    }

    public static function notApproved(string $status): self
    {
        return new self("Expense must be approved before pay; current [{$status}].");
    }

    public static function zeroAmount(): self
    {
        return new self('Expense amount must be greater than zero.');
    }

    public static function inactiveFund(string $fundName): self
    {
        return new self("Fund [{$fundName}] is inactive; cannot pay from it.");
    }

    public static function accountingApiFailed(string $reason): self
    {
        return new self("Auto-journal posting failed: {$reason}");
    }
}
