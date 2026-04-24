<?php

namespace App\Exceptions;

use RuntimeException;

class JournalException extends RuntimeException
{
    public static function unbalanced(string $debit, string $credit): self
    {
        return new self("Journal is unbalanced: debit {$debit} ≠ credit {$credit}.");
    }

    public static function periodNotOpen(string $status): self
    {
        return new self("Cannot post into period with status [{$status}]; period must be open.");
    }

    public static function accountNotPostable(string $code): self
    {
        return new self("Account [{$code}] is not postable (is_postable=false).");
    }

    public static function notDraft(string $status): self
    {
        return new self("Journal must be in draft status to post; current status [{$status}].");
    }

    public static function notPosted(string $status): self
    {
        return new self("Journal must be posted to reverse; current status [{$status}].");
    }

    public static function noEntries(): self
    {
        return new self('Journal has no entries.');
    }

    public static function entityMismatch(): self
    {
        return new self('Journal entry account belongs to a different entity.');
    }
}
