<?php

namespace App\Exceptions;

use RuntimeException;

class PayrollException extends RuntimeException
{
    public static function notDraft(string $status): self
    {
        return new self("Payroll run must be in draft status to approve; current [{$status}].");
    }

    public static function notApproved(string $status): self
    {
        return new self("Payroll run must be approved before pay; current [{$status}].");
    }

    public static function zeroTotal(): self
    {
        return new self('Payroll run total_wages must be greater than zero.');
    }

    public static function accountingApiFailed(string $reason): self
    {
        return new self("Auto-journal posting failed: {$reason}");
    }
}
