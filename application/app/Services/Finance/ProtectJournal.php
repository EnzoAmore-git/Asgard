<?php

namespace App\Services\Finance;

use LogicException;

/** Defense against accidental DML through the application's normal connection, not a DBA boundary. */
final class ProtectJournal
{
    public static function check(string $sql): void
    {
        $sql = preg_replace('~/\*.*?\*/|--[^\r\n]*~s', ' ', $sql);
        if (preg_match('/\b(financial_events|member_entries|money_entries|financial_requests|club_actions|billing_settings|billing_periods|tariff_versions|exemption_versions|attendance_revisions)\b/i', $sql)
            && (preg_match('/^\s*(update|delete|replace|truncate)\b/i', $sql)
                || (preg_match('/^\s*with\b/i', $sql) && preg_match('/\b(update|delete|replace)\b/i', $sql))
                || preg_match('/\bon\s+duplicate\s+key\s+update\b/i', $sql))) {
            throw new LogicException('Проведенная финансовая история не изменяется и не удаляется. Добавьте связанное исправление.');
        }
    }
}
