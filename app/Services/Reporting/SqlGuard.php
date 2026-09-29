<?php

namespace App\Services\Reporting;

use App\Exceptions\AvailabilityException;

/**
 * Hallucination guard for generated SQL — current heuristic output
 * and any future model output must both pass. Single read-only
 * SELECT over allow-listed tables/columns, always branch-scoped,
 * never touching secrets, keys, or auth material.
 */
class SqlGuard
{
    /**
     * @var array<string, list<string>>
     */
    public const TABLES = [
        'revenue_snapshots' => ['stay_date', 'snapshot_date', 'rooms_available', 'rooms_sold', 'room_revenue_minor', 'total_revenue_minor', 'currency_code', 'branch_id'],
        'reservations' => ['id', 'confirmation_number', 'guest_name', 'guest_email', 'guest_phone', 'room_type_id', 'check_in_date', 'check_out_date', 'status', 'source', 'room_rate', 'total_amount', 'amount_paid', 'currency_code', 'branch_id'],
        'folios' => ['id', 'folio_number', 'reservation_id', 'status', 'balance', 'currency_code', 'branch_id'],
        'transactions' => ['id', 'folio_id', 'type', 'category', 'description', 'amount', 'is_voided', 'business_date', 'created_at'],
        'daily_ledgers' => ['business_date', 'rooms_posted', 'total_room_revenue', 'total_tax', 'total_other_charges', 'total_payments', 'net_revenue', 'branch_id'],
    ];

    public const MAX_ROWS = 5000;

    public static function validate(string $sql): void
    {
        if (! preg_match('/^\s*select\s/i', $sql)) {
            throw new AvailabilityException('NL_QUERY_REFUSED', 'Only read-only SELECT queries are allowed.');
        }

        if (str_contains($sql, ';')) {
            throw new AvailabilityException('NL_QUERY_REFUSED', 'Multi-statement queries are not allowed.');
        }

        if (preg_match('/\b(password|remember_token|key_enc|oauth_enc|webhook_secret|cdr_secret|api_secret)\b/i', $sql)) {
            throw new AvailabilityException('NL_QUERY_REFUSED', 'That question reaches restricted columns.');
        }

        if (! preg_match('/\bbranch_id\b/i', $sql)) {
            throw new AvailabilityException('NL_QUERY_REFUSED', 'Queries must stay inside one property.');
        }

        preg_match_all('/\b(?:from|join)\s+"?([a-z_]+)"?/i', $sql, $tables);

        foreach ($tables[1] as $table) {
            if (! array_key_exists(strtolower($table), self::TABLES)) {
                throw new AvailabilityException('NL_QUERY_REFUSED', "Table {$table} is not queryable.");
            }
        }

        $select = (string) preg_replace('/^\s*select\s/i', '', $sql);
        $fromPos = stripos($select, ' from ');

        if ($fromPos === false) {
            throw new AvailabilityException('NL_QUERY_REFUSED', 'Could not parse the query shape.');
        }

        $projection = substr($select, 0, $fromPos);

        foreach (self::splitProjection($projection) as $expr) {
            self::validateExpression($expr);
        }
    }

    /**
     * @return list<string>
     */
    private static function splitProjection(string $projection): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($projection) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    private static function validateExpression(string $expr): void
    {
        $expr = trim((string) preg_replace('/\s+as\s+\w+$/i', '', trim($expr)));

        if ($expr === '') {
            throw new AvailabilityException('NL_QUERY_REFUSED', 'Explicit columns only.');
        }

        if (preg_match('/^count\s*\(\s*\*\s*\)$/i', $expr)) {
            return;
        }

        if (preg_match('/^(count|sum|avg|min|max|round|coalesce|extract|date_trunc)\s*\(/i', $expr)) {
            $inner = (string) preg_replace('/^(count|sum|avg|min|max|round|coalesce|extract|date_trunc)\s*\(/i', '', $expr);
            $inner = (string) preg_replace('/\)[^)]*$/', '', $inner);

            foreach (explode(',', $inner) as $part) {
                self::validateOperand(trim($part));
            }

            return;
        }

        self::validateOperand($expr);
    }

    private static function validateOperand(string $operand): void
    {
        $operand = trim($operand, " '\"");

        if ($operand === '' || $operand === '*' || is_numeric($operand)) {
            if ($operand === '' || $operand === '*') {
                throw new AvailabilityException('NL_QUERY_REFUSED', 'Explicit columns only.');
            }

            return;
        }

        if (str_contains($operand, '(') || str_contains($operand, ')')) {
            throw new AvailabilityException('NL_QUERY_REFUSED', 'That expression is not allowed.');
        }

        $column = strtolower((string) preg_replace('/^.*\./', '', $operand));

        foreach (self::TABLES as $columns) {
            if (in_array($column, $columns, true)) {
                return;
            }
        }

        throw new AvailabilityException('NL_QUERY_REFUSED', "Column {$column} is not queryable.");
    }
}
