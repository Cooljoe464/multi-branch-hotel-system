<?php

namespace App\Services;

use App\Events\BillSplit;
use App\Events\CourseFired;
use App\Events\OfflineQueueSynced;
use App\Events\TableSeated;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Folio;
use App\Models\HappyHour;
use App\Models\KotItem;
use App\Models\MenuItem;
use App\Models\Outlet;
use App\Models\PosCharge;
use App\Models\PosModifier;
use App\Models\Reservation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * POS completeness: table tabs, course firing, integer-exact splits,
 * priced modifiers, server-side happy hours frozen per line, and
 * nonce-deduped offline replay. All money in integer minor units.
 */
class PosService
{
    public const COURSES = ['starter', 'main', 'dessert'];

    /**
     * Open a pending tab, optionally seating a table.
     */
    public function openTab(
        Branch $branch,
        Outlet $outlet,
        Reservation $reservation,
        ?DiningTable $table = null,
        int $covers = 1,
        ?User $by = null,
        ?string $offlineNonce = null,
    ): PosCharge {
        if ($outlet->branch_id !== $branch->id) {
            throw new AvailabilityException('POS_OUTLET', 'The outlet does not belong to this property.');
        }

        if ($reservation->branch_id !== $branch->id || $reservation->status !== 'checked_in') {
            throw new AvailabilityException('POS_RESERVATION', 'Tabs open only on checked-in reservations of this property.');
        }

        return DB::transaction(function () use ($branch, $outlet, $reservation, $table, $covers, $by, $offlineNonce) {
            if ($table) {
                $locked = DiningTable::where('id', $table->id)->lockForUpdate()->firstOrFail();

                if ($locked->outlet_id !== $outlet->id) {
                    throw new AvailabilityException('POS_TABLE', 'The table does not belong to this outlet.');
                }

                if ($locked->status !== DiningTable::STATUS_FREE) {
                    throw new AvailabilityException('POS_TABLE_BUSY', "Table {$locked->code} is already seated.");
                }

                $locked->update(['status' => DiningTable::STATUS_SEATED]);
            }

            $charge = PosCharge::firstOrCreate(
                $offlineNonce !== null ? ['offline_nonce' => $offlineNonce] : ['id' => 0],
                [
                    'branch_id' => $branch->id,
                    'reservation_id' => $reservation->id,
                    'folio_id' => $this->openFolio($branch, $reservation)->id,
                    'outlet' => $outlet->code,
                    'items' => [],
                    'subtotal' => 0,
                    'tax_amount' => 0,
                    'total' => 0,
                    'status' => 'pending',
                    'dining_table_id' => isset($locked) ? $locked->id : null,
                    'course' => 'main',
                    'offline_nonce' => $offlineNonce,
                    'metadata' => ['covers' => max(1, $covers), 'opened_by' => $by?->id],
                ],
            );

            if (isset($locked)) {
                event(new TableSeated($locked->fresh() ?? $locked, $charge->fresh() ?? $charge));
            }

            return $charge->fresh() ?? $charge;
        });
    }

    /**
     * Append priced lines (modifiers + happy hour applied and frozen).
     * Depletes ingredients at add time, matching the terminal flow.
     *
     * @param  list<array<string, mixed>>  $rows  {menu_item_id?, name, quantity, price, modifier_ids?[], course?}.
     */
    public function addItems(PosCharge $charge, array $rows, ?User $by = null): PosCharge
    {
        return DB::transaction(function () use ($charge, $rows, $by) {
            $locked = PosCharge::where('id', $charge->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new AvailabilityException('POS_STATE', 'Only pending tabs accept new lines.');
            }

            $branch = Branch::findOrFail($locked->branch_id);
            $outlet = Outlet::where('branch_id', $branch->id)->where('code', $locked->outlet)->firstOrFail();
            $lines = array_values($locked->items);
            $now = Carbon::now($branch->timezone ?? 'Africa/Lagos');

            foreach ($rows as $row) {
                $menuItemId = $row['menu_item_id'] ?? null;
                $menuItemId = is_int($menuItemId) ? $menuItemId : null;
                $name = $row['name'] ?? 'Item';
                $name = is_string($name) ? $name : 'Item';
                $qtyRaw = $row['quantity'] ?? 0;
                $qty = is_int($qtyRaw) ? $qtyRaw : 0;
                $priceRaw = $row['price'] ?? 0;
                $price = is_int($priceRaw) ? $priceRaw : 0;
                $courseRaw = $row['course'] ?? 'main';
                $course = is_string($courseRaw) && in_array($courseRaw, self::COURSES, true) ? $courseRaw : 'main';

                if ($qty < 1 || $price < 0) {
                    throw new AvailabilityException('POS_LINE', 'Lines need a positive quantity and a non-negative price.');
                }

                $modifierIds = $row['modifier_ids'] ?? [];
                $modifierIds = is_array($modifierIds) ? $modifierIds : [];
                $modifierNames = [];
                foreach ($modifierIds as $modifierId) {
                    if (! is_int($modifierId)) {
                        continue;
                    }
                    $modifier = PosModifier::forBranch($branch->id)->active()->find($modifierId);

                    if ($modifier) {
                        $price += $modifier->price_delta_minor;
                        $modifierNames[] = $modifier->name;
                    }
                }

                $happy = $this->activeHappyHour($outlet, $menuItemId, $now);
                $happyCut = 0;
                $happyId = null;

                if ($happy && $price > 0) {
                    $happyCut = RateEngine::mulDiv($price, $happy->discount_bps);
                    $price -= $happyCut;
                    $happyId = $happy->id;
                }

                $lines[] = [
                    'menu_item_id' => $menuItemId,
                    'name' => $name,
                    'quantity' => $qty,
                    'price' => max(0, $price),
                    'base_price' => is_int($row['price'] ?? null) ? $row['price'] : $price,
                    'modifier_names' => $modifierNames,
                    'course' => $course,
                    'happy_hour_id' => $happyId,
                    'happy_discount_minor' => $happyCut * $qty,
                    'fired' => false,
                ];

                if ($menuItemId !== null) {
                    $menuItem = MenuItem::find($menuItemId);

                    if ($menuItem) {
                        // Cost-aware depletion: blocks on stock-out and
                        // journals COGS (legacy terminal flow keeps the
                        // plain deductIngredients path).
                        (new CostingService)->depleteMenuItem($menuItem, $qty, $by);
                    }
                }
            }

            $this->retotal($locked, $lines);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Fire one course to the kitchen: per-course KOTs in order.
     *
     * @return list<KotItem>
     */
    public function fireCourse(PosCharge $charge, string $course, ?User $by = null): array
    {
        if (! in_array($course, self::COURSES, true)) {
            throw new AvailabilityException('POS_COURSE', "Unknown course {$course}.");
        }

        return DB::transaction(function () use ($charge, $course, $by) {
            $locked = PosCharge::where('id', $charge->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new AvailabilityException('POS_STATE', 'Only pending tabs can fire courses.');
            }

            $lines = array_values($locked->items);
            $router = new KotRoutingService;
            $fired = [];
            $changed = false;

            foreach ($lines as $index => $line) {
                $lineCourse = $line['course'] ?? 'main';
                $lineFired = $line['fired'] ?? false;

                if (! is_string($lineCourse) || $lineCourse !== $course || $lineFired) {
                    continue;
                }

                $menuItemId = $line['menu_item_id'] ?? null;
                $menuItemId = is_int($menuItemId) ? $menuItemId : null;
                $itemName = $line['name'] ?? 'Item';
                $itemName = is_string($itemName) ? $itemName : 'Item';
                $itemQty = $line['quantity'] ?? 0;
                $itemQty = is_int($itemQty) ? $itemQty : 0;
                $itemPrice = $line['price'] ?? 0;
                $itemPrice = is_int($itemPrice) ? $itemPrice : 0;

                $routable = ['name' => $itemName, 'quantity' => $itemQty, 'price' => $itemPrice];

                if ($menuItemId !== null) {
                    $routable['menu_item_id'] = $menuItemId;
                }

                foreach ($router->routeItems([$routable], $locked->branch_id) as $routed) {
                    $fired[] = KotItem::create([
                        'pos_charge_id' => $locked->id,
                        'branch_id' => $locked->branch_id,
                        'outlet' => $routed['outlet'],
                        'item_name' => $routed['name'],
                        'quantity' => $routed['quantity'],
                        'status' => 'pending',
                        'course' => $course,
                        'priority' => 'normal',
                    ]);
                }

                $lines[$index]['fired'] = true;
                $changed = true;
            }

            if (! $changed) {
                throw new AvailabilityException('POS_COURSE_FIRED', "Course {$course} has nothing left to fire.");
            }

            $locked->update(['items' => $lines, 'fired_at' => now()]);

            event(new CourseFired($locked->fresh() ?? $locked, $course, count($fired), $by));

            return $fired;
        });
    }

    /**
     * Split a pending tab. Legs divide by percent, exact amount or
     * item indexes; the integer remainder lands on the first leg so
     * shares always sum exactly to the tab total.
     *
     * @param  list<array<string, mixed>>  $legs  Raw legs; each normalizes
     *                                            to percent | amount | items or the split is rejected.
     * @return list<PosCharge>
     */
    public function splitBill(PosCharge $charge, array $legs, ?User $by = null): array
    {
        $clean = [];
        foreach ($legs as $leg) {
            $indexes = $leg['item_indexes'] ?? null;

            if (is_array($indexes)) {
                $picked = [];
                foreach ($indexes as $index) {
                    if (is_int($index)) {
                        $picked[] = $index;
                    }
                }
                $clean[] = ['kind' => 'items', 'indexes' => $picked];

                continue;
            }

            $bps = $leg['percent_bps'] ?? null;

            if (is_int($bps)) {
                $clean[] = ['kind' => 'percent', 'bps' => $bps];

                continue;
            }

            $amount = $leg['amount_minor'] ?? null;

            if (is_int($amount)) {
                $clean[] = ['kind' => 'amount', 'amount' => $amount];

                continue;
            }

            throw new AvailabilityException('POS_SPLIT', 'Each leg needs percent_bps, amount_minor or item_indexes.');
        }

        if ($clean === []) {
            throw new AvailabilityException('POS_SPLIT', 'A split needs at least one leg.');
        }

        return DB::transaction(function () use ($charge, $clean, $by) {
            $locked = PosCharge::where('id', $charge->id)->lockForUpdate()->firstOrFail();

            if ($locked->splitChildren()->exists()) {
                throw new AvailabilityException('POS_SPLIT_EXISTS', 'This tab is already split.');
            }

            if ($locked->status !== 'pending') {
                throw new AvailabilityException('POS_STATE', 'Only pending tabs can be split.');
            }

            $lines = array_values($locked->items);
            $shares = $this->splitShares($locked->total, $lines, $clean);

            $children = [];
            foreach ($shares as $index => $share) {
                $children[] = PosCharge::create([
                    'branch_id' => $locked->branch_id,
                    'reservation_id' => $locked->reservation_id,
                    'folio_id' => $locked->folio_id,
                    'outlet' => $locked->outlet,
                    'items' => $share['lines'],
                    'subtotal' => $share['subtotal'],
                    'tax_amount' => $share['tax'],
                    'total' => $share['total'],
                    'status' => 'pending',
                    'dining_table_id' => $locked->dining_table_id,
                    'course' => $locked->course,
                    'parent_split_id' => $locked->id,
                    'metadata' => ['split_index' => $index, 'split_of' => $locked->id, 'split_by' => $by?->id],
                ]);
            }

            $locked->update(['status' => 'split']);

            event(new BillSplit($locked->fresh() ?? $locked, $children));

            return $children;
        });
    }

    /**
     * Post a pending tab to its folio. Split parents never post;
     * children post individually.
     */
    public function postTab(PosCharge $charge, int $postedBy): Transaction
    {
        return DB::transaction(function () use ($charge, $postedBy) {
            $locked = PosCharge::where('id', $charge->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new AvailabilityException('POS_STATE', 'Only pending tabs can be posted.');
            }

            if ($locked->total <= 0) {
                throw new AvailabilityException('POS_EMPTY', 'Empty tabs cannot be posted.');
            }

            $folio = Folio::find($locked->folio_id);

            if (! $folio) {
                $folio = (new FolioService)->createFolio($locked->branch_id, $locked->reservation_id);
                $locked->update(['folio_id' => $folio->id]);
            }

            $transaction = (new FolioService)->postPosCharge($locked, $postedBy);

            $locked->update([
                'transaction_id' => $transaction->id,
                'status' => 'posted',
                'posted_at' => now(),
            ]);

            if ($locked->dining_table_id !== null) {
                DiningTable::where('id', $locked->dining_table_id)->update(['status' => DiningTable::STATUS_FREE]);
            }

            return $transaction;
        });
    }

    /**
     * Replay an ordered offline batch. Same nonce → same charge:
     * replay storms converge instead of duplicating.
     *
     * @param  list<array<string, mixed>>  $payloads  {offline_nonce, reservation_id, items, dining_table_id?, course?}.
     * @return array{posted: int, skipped: int, errors: list<string>}
     */
    public function replayOffline(Outlet $outlet, array $payloads, User $by): array
    {
        $branch = Branch::findOrFail($outlet->branch_id);
        $posted = 0;
        $skipped = 0;
        $errors = [];

        foreach ($payloads as $payload) {
            $nonce = $payload['offline_nonce'] ?? null;
            $nonce = is_string($nonce) && $nonce !== '' ? $nonce : null;
            $reservationId = $payload['reservation_id'] ?? null;
            $reservationId = is_int($reservationId) ? $reservationId : null;

            try {
                if ($nonce === null || $reservationId === null) {
                    throw new AvailabilityException('POS_OFFLINE', 'Offline payloads need a nonce and a reservation.');
                }

                $existing = PosCharge::where('offline_nonce', $nonce)->first();

                if ($existing && $existing->status === 'posted') {
                    $skipped++;

                    continue;
                }

                $reservation = Reservation::findOrFail($reservationId);

                $table = null;
                $tableId = $payload['dining_table_id'] ?? null;
                if (is_int($tableId)) {
                    $table = DiningTable::forOutlet($outlet->id)->find($tableId);
                }

                $rawItems = $payload['items'] ?? [];
                $items = [];
                if (is_array($rawItems)) {
                    foreach ($rawItems as $row) {
                        if (! is_array($row)) {
                            continue;
                        }
                        $line = [];
                        foreach ($row as $key => $value) {
                            if (is_string($key)) {
                                $line[$key] = $value;
                            }
                        }
                        $items[] = $line;
                    }
                }
                $course = $payload['course'] ?? 'main';
                $course = is_string($course) ? $course : 'main';

                if ($existing) {
                    $charge = $existing;
                } else {
                    $charge = $this->openTab($branch, $outlet, $reservation, $table, 1, $by, $nonce);
                    $courseRaw = $course;
                    $charge->update(['course' => in_array($courseRaw, self::COURSES, true) ? $courseRaw : 'main']);
                }

                $this->addItems($charge->fresh() ?? $charge, $items, $by);
                $this->postTab($charge->fresh() ?? $charge, $by->id);
                $posted++;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        event(new OfflineQueueSynced($outlet, $posted, $skipped));

        return ['posted' => $posted, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Best matching happy hour for a line, or null. Server time in the
     * branch timezone wins on boundaries and is frozen on the charge.
     */
    public function activeHappyHour(Outlet $outlet, ?int $menuItemId, Carbon $now): ?HappyHour
    {
        $best = null;

        foreach (HappyHour::forOutlet($outlet->id)->active()->get() as $rule) {
            if (! $this->windowMatches($rule->cron_window, $now)) {
                continue;
            }

            $ids = $rule->menuItemIds();

            if ($ids !== [] && ($menuItemId === null || ! in_array($menuItemId, $ids, true))) {
                continue;
            }

            if ($best === null || $rule->discount_bps > $best->discount_bps) {
                $best = $rule;
            }
        }

        return $best;
    }

    /**
     * Window format "FRI 17:00-19:00" or "MON,TUE,WED 12:00-14:00".
     * Unparseable windows never match (fail closed, logged).
     */
    private function windowMatches(string $window, Carbon $now): bool
    {
        $parts = explode(' ', trim($window));

        if (count($parts) !== 2) {
            return false;
        }

        $days = array_map('trim', explode(',', strtoupper($parts[0])));
        $range = explode('-', $parts[1]);

        if (count($range) !== 2) {
            return false;
        }

        if (! in_array(strtoupper($now->format('D')), $days, true)) {
            return false;
        }

        $time = $now->format('H:i');

        return $time >= $range[0] && $time < $range[1];
    }

    private function openFolio(Branch $branch, Reservation $reservation): Folio
    {
        $folio = Folio::where('reservation_id', $reservation->id)->where('status', 'open')->first();

        return $folio ?? (new FolioService)->createFolio($branch->id, $reservation->id);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function retotal(PosCharge $charge, array $lines): void
    {
        $subtotal = 0;
        foreach ($lines as $line) {
            $qty = is_int($line['quantity'] ?? null) ? $line['quantity'] : 0;
            $price = is_int($line['price'] ?? null) ? $line['price'] : 0;
            $subtotal += $qty * $price;
        }

        $branch = Branch::findOrFail($charge->branch_id);
        $taxRate = (float) $branch->tax_rate / 100;
        $tax = (int) round($subtotal * $taxRate);

        $charge->update([
            'items' => $lines,
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total' => $subtotal + $tax,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array{kind: string, indexes?: list<int>, bps?: int, amount?: int}>  $legs  Normalized by splitBill.
     * @return list<array{lines: list<array<string, mixed>>, subtotal: int, tax: int, total: int}>
     */
    private function splitShares(int $total, array $lines, array $legs): array
    {
        $lineTotals = [];
        foreach ($lines as $index => $line) {
            $qty = is_int($line['quantity'] ?? null) ? $line['quantity'] : 0;
            $price = is_int($line['price'] ?? null) ? $line['price'] : 0;
            $lineTotals[$index] = $qty * $price;
        }

        $shares = [];
        foreach ($legs as $leg) {
            if ($leg['kind'] === 'items') {
                $pick = [];
                $share = 0;
                foreach ($leg['indexes'] ?? [] as $index) {
                    if (isset($lines[$index])) {
                        $pick[] = $lines[$index];
                        $share += $lineTotals[$index] ?? 0;
                    }
                }
                $shares[] = ['lines' => $pick, 'base' => $share, 'money' => false];
            } elseif ($leg['kind'] === 'percent') {
                $shares[] = ['lines' => [], 'base' => RateEngine::mulDiv($total, (int) ($leg['bps'] ?? 0)), 'money' => true];
            } else {
                $shares[] = ['lines' => [], 'base' => (int) ($leg['amount'] ?? 0), 'money' => true];
            }
        }

        // Flooring dust lands on the first money leg; all-item splits
        // must partition the bill exactly.
        $dust = $total - array_sum(array_column($shares, 'base'));
        $dusted = false;
        foreach ($shares as $index => $share) {
            if ($share['money']) {
                $shares[$index]['base'] += $dust;
                $dusted = true;

                break;
            }
        }

        if (! $dusted && $dust !== 0) {
            throw new AvailabilityException('POS_SPLIT_MISMATCH', 'Item legs leave '.$dust.' unallocated; add a money leg for the remainder.');
        }

        $result = [];
        foreach ($shares as $share) {
            if ($share['base'] < 0) {
                throw new AvailabilityException('POS_SPLIT_MISMATCH', 'Split legs exceed the tab total.');
            }

            $result[] = $this->shareLines($share['lines'], $share['base']);
        }

        $sum = array_sum(array_column($result, 'total'));

        if ($sum !== $total) {
            throw new AvailabilityException('POS_SPLIT_MISMATCH', "Split shares {$sum} do not match the tab total {$total}.");
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{lines: list<array<string, mixed>>, subtotal: int, tax: int, total: int}
     */
    private function shareLines(array $lines, int $amount): array
    {
        if ($lines === []) {
            return ['lines' => [['name' => 'Split share', 'quantity' => 1, 'price' => $amount]], 'subtotal' => $amount, 'tax' => 0, 'total' => $amount];
        }

        $base = 0;
        foreach ($lines as $line) {
            $qty = is_int($line['quantity'] ?? null) ? $line['quantity'] : 0;
            $price = is_int($line['price'] ?? null) ? $line['price'] : 0;
            $base += $qty * $price;
        }

        // Item legs carry their lines verbatim (tax already inside the
        // parent total); the share amount is informational.
        return ['lines' => $lines, 'subtotal' => $base, 'tax' => 0, 'total' => $base];
    }
}
