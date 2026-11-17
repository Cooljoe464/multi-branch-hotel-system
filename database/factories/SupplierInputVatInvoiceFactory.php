<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Supplier;
use App\Models\SupplierInputVatInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierInputVatInvoice>
 */
class SupplierInputVatInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'tenant_id' => fn (array $attributes): int => Branch::query()->findOrFail($this->branchId($attributes['branch_id'] ?? null))->tenant_id,
            'supplier_id' => fn (array $attributes): int => Supplier::query()->create([
                'branch_id' => $this->branchId($attributes['branch_id'] ?? null),
                'name' => fake()->company(),
            ])->id,
            'supplier_invoice_number' => fake()->unique()->bothify('INV-########'),
            'supplier_tax_id' => fake()->numerify('#############'),
            'invoice_date' => now()->subDays(5)->toDateString(),
            'supply_date' => now()->subDays(5)->toDateString(),
            'currency_code' => 'NGN',
            'net_amount_minor' => 100000,
            'vat_amount_minor' => 7500,
            'taxable_use_minor' => 80000,
            'exempt_use_minor' => 20000,
            'validated_einvoice' => true,
            'einvoice_reference' => fake()->bothify('NRS-########'),
            'evidence_snapshot' => ['source' => 'fixture'],
            'posted_at' => now(),
        ];
    }

    private function branchId(mixed $value): int
    {
        $branchId = filter_var($value, FILTER_VALIDATE_INT);

        if (! is_int($branchId)) {
            throw new \LogicException('Supplier input VAT invoice factory requires a branch ID.');
        }

        return $branchId;
    }
}
