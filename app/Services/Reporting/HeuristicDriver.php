<?php

namespace App\Services\Reporting;

/**
 * Template parser for the governed question set. Anything outside
 * the templates returns null (refused with examples) instead of
 * guessing — a wrong number is worse than no number.
 */
class HeuristicDriver implements NlDriver
{
    /**
     * @return array{sql: string, bindings: list<mixed>, columns: list<string>, kind: string, title: string}|null
     */
    public function parse(string $question, string $from, string $to): ?array
    {
        $q = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $question)));

        if (str_contains($q, 'revenue') && str_contains($q, 'by source')) {
            return $this->revenueBySource($from, $to);
        }

        if (str_contains($q, 'revenue')) {
            return $this->revenueDaily($from, $to);
        }

        if (str_contains($q, 'occupancy')) {
            return $this->occupancyDaily($from, $to);
        }

        if (str_contains($q, 'adr') || str_contains($q, 'average daily rate')) {
            return $this->adrDaily($from, $to);
        }

        if (str_contains($q, 'arrival')) {
            return $this->arrivals($from, $to);
        }

        if (str_contains($q, 'departure')) {
            return $this->departures($from, $to);
        }

        if (str_contains($q, 'outstanding') || str_contains($q, 'unpaid') || str_contains($q, 'balance')) {
            return $this->outstanding();
        }

        if (str_contains($q, 'void')) {
            return $this->voids($from, $to);
        }

        if (str_contains($q, 'refund')) {
            return $this->refunds($from, $to);
        }

        return null;
    }

    /**
     * @return array{sql: string, bindings: list<mixed>, columns: list<string>, kind: string, title: string}
     */
    private function revenueDaily(string $from, string $to): array
    {
        return [
            'sql' => 'select business_date as day, sum(total_room_revenue) as room_revenue, sum(total_other_charges) as other_revenue, sum(total_payments) as payments from daily_ledgers where branch_id = ? and business_date between ? and ? group by business_date order by business_date limit '.SqlGuard::MAX_ROWS,
            'bindings' => [null, $from, $to],
            'columns' => ['day', 'room_revenue', 'other_revenue', 'payments'],
            'kind' => 'daily',
            'title' => 'Revenue by day',
        ];
    }

    /**
     * @return array{sql: string, bindings: list<mixed>, columns: list<string>, kind: string, title: string}
     */
    private function revenueBySource(string $from, string $to): array
    {
        return [
            'sql' => 'select source, count(*) as nights, sum(total_amount) as revenue from reservations where branch_id = ? and check_in_date between ? and ? group by source order by revenue desc limit '.SqlGuard::MAX_ROWS,
            'bindings' => [null, $from, $to],
            'columns' => ['source', 'nights', 'revenue'],
            'kind' => 'table',
            'title' => 'Revenue by source',
        ];
    }

    /**
     * @return array{sql: string, bindings: list<mixed>, columns: list<string>, kind: string, title: string}
     */
    private function occupancyDaily(string $from, string $to): array
    {
        return [
            'sql' => 'select business_date as day, sum(rooms_posted) as rooms_sold from daily_ledgers where branch_id = ? and business_date between ? and ? group by business_date order by business_date limit '.SqlGuard::MAX_ROWS,
            'bindings' => [null, $from, $to],
            'columns' => ['day', 'rooms_sold'],
            'kind' => 'daily',
            'title' => 'Rooms sold by day',
        ];
    }

    /**
     * @return array{sql: string, bindings: list<mixed>, columns: list<string>, kind: string, title: string}
     */
    private function adrDaily(string $from, string $to): array
    {
        return [
            'sql' => 'select business_date as day, sum(total_room_revenue) as revenue, sum(rooms_posted) as sold from daily_ledgers where branch_id = ? and business_date between ? and ? group by business_date order by business_date limit '.SqlGuard::MAX_ROWS,
            'bindings' => [null, $from, $to],
            'columns' => ['day', 'revenue', 'sold'],
            'kind' => 'daily',
            'title' => 'ADR inputs by day',
        ];
    }

    /**
     * @return array{sql: string, bindings: list<mixed>, columns: list<string>, kind: string, title: string}
     */
    private function arrivals(string $from, string $to): array
    {
        return [
            'sql' => 'select confirmation_number, guest_name, guest_email, check_in_date, check_out_date, status from reservations where branch_id = ? and check_in_date between ? and ? order by check_in_date limit '.SqlGuard::MAX_ROWS,
            'bindings' => [null, $from, $to],
            'columns' => ['confirmation_number', 'guest_name', 'guest_email', 'check_in_date', 'check_out_date', 'status'],
            'kind' => 'table',
            'title' => 'Arrivals',
        ];
    }

    /**
     * @return array{sql: string, bindings: list<mixed>, columns: list<string>, kind: string, title: string}
     */
    private function departures(string $from, string $to): array
    {
        return [
            'sql' => 'select confirmation_number, guest_name, check_in_date, check_out_date, status from reservations where branch_id = ? and check_out_date between ? and ? order by check_out_date limit '.SqlGuard::MAX_ROWS,
            'bindings' => [null, $from, $to],
            'columns' => ['confirmation_number', 'guest_name', 'check_in_date', 'check_out_date', 'status'],
            'kind' => 'table',
            'title' => 'Departures',
        ];
    }

    /**
     * @return array{sql: string, bindings: list<mixed>, columns: list<string>, kind: string, title: string}
     */
    private function outstanding(): array
    {
        return [
            'sql' => 'select f.folio_number, r.guest_name, r.confirmation_number, f.balance from folios f join reservations r on r.id = f.reservation_id where f.branch_id = ? and f.balance > 0 order by f.balance desc limit '.SqlGuard::MAX_ROWS,
            'bindings' => [null],
            'columns' => ['folio_number', 'guest_name', 'confirmation_number', 'balance'],
            'kind' => 'table',
            'title' => 'Outstanding balances',
        ];
    }

    /**
     * @return array{sql: string, bindings: list<mixed>, columns: list<string>, kind: string, title: string}
     */
    private function voids(string $from, string $to): array
    {
        return [
            'sql' => 'select t.business_date as day, count(*) as voids from transactions t join folios f on f.id = t.folio_id where f.branch_id = ? and t.is_voided = true and t.business_date between ? and ? group by t.business_date order by day limit '.SqlGuard::MAX_ROWS,
            'bindings' => [null, $from, $to],
            'columns' => ['day', 'voids'],
            'kind' => 'daily',
            'title' => 'Voids by day',
        ];
    }

    /**
     * @return array{sql: string, bindings: list<mixed>, columns: list<string>, kind: string, title: string}
     */
    private function refunds(string $from, string $to): array
    {
        return [
            'sql' => 'select t.business_date as day, count(*) as refunds, sum(t.amount) as amount from transactions t join folios f on f.id = t.folio_id where f.branch_id = ? and t.type = ? and t.is_voided = false and t.business_date between ? and ? group by t.business_date order by day limit '.SqlGuard::MAX_ROWS,
            'bindings' => [null, 'refund', $from, $to],
            'columns' => ['day', 'refunds', 'amount'],
            'kind' => 'daily',
            'title' => 'Refunds by day',
        ];
    }
}
