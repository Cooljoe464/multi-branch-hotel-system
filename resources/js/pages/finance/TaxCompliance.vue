<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Tax compliance', href: '#' },
        ],
    },
});

interface SupplierInvoice {
    id: number;
    supplier_id: number;
    invoice_number: string;
    supplier_tax_id: string | null;
    supply_date: string;
    net_amount_minor: number;
    input_vat_minor: number;
    recoverable_input_vat_minor: number;
    non_recoverable_input_vat_minor: number;
    validated_einvoice: boolean;
    claimable: boolean;
}

interface Workpaper {
    period: string;
    currency_code: string;
    vat_status: string;
    output_vat_minor: number;
    input_vat_minor: number;
    recoverable_input_vat_minor: number;
    non_recoverable_input_vat_minor: number;
    net_vat_payable_minor: number;
    output_transaction_count: number;
    supplier_invoice_count: number;
    supplier_invoices: SupplierInvoice[];
}

const props = defineProps<{
    branch: { id: number; name: string; currency_code: string };
    month: string;
    workpaper: Workpaper | null;
    workpaperError: string | null;
    ruleVersions: {
        id: number;
        rule_key: string;
        value: Record<string, unknown>;
        effective_from: string;
        effective_until: string | null;
        approval_reference: string;
        scope: string;
    }[];
    suppliers: { id: number; name: string }[];
}>();

const base = `/branches/${props.branch.id}/tax`;
const selectedMonth = ref(props.month);

const money = (minor: number) =>
    new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: props.workpaper?.currency_code ?? props.branch.currency_code,
    }).format(minor / 100);

const changeMonth = () => {
    router.get(`${base}/compliance`, { month: selectedMonth.value }, { preserveScroll: true });
};

const toMinor = (value: string) => Math.round(Number(value || 0) * 100);

const invoice = ref({
    supplier_id: props.suppliers[0]?.id ?? '',
    supplier_invoice_number: '',
    supplier_tax_id: '',
    invoice_date: '',
    supply_date: '',
    net: '',
    vat: '',
    taxable_use: '',
    exempt_use: '0',
    validated_einvoice: false,
    einvoice_reference: '',
    evidence_reference: '',
});
const invoiceErrors = ref<Record<string, string>>({});

const recordInvoice = () => {
    router.post(
        `${base}/supplier-invoices`,
        {
            supplier_id: invoice.value.supplier_id,
            supplier_invoice_number: invoice.value.supplier_invoice_number,
            supplier_tax_id: invoice.value.supplier_tax_id,
            invoice_date: invoice.value.invoice_date,
            supply_date: invoice.value.supply_date,
            net_amount_minor: toMinor(invoice.value.net),
            vat_amount_minor: toMinor(invoice.value.vat),
            taxable_use_minor: toMinor(invoice.value.taxable_use),
            exempt_use_minor: toMinor(invoice.value.exempt_use),
            validated_einvoice: invoice.value.validated_einvoice,
            einvoice_reference: invoice.value.einvoice_reference || null,
            evidence_reference: invoice.value.evidence_reference,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                invoiceErrors.value = {};
            },
            onError: (e) => {
                invoiceErrors.value = e;
            },
        },
    );
};

const voidInvoice = (id: number) => {
    const reason = window.prompt('Reason for voiding this invoice?');

    if (reason) {
        router.post(`${base}/supplier-invoices/${id}/void`, { reason }, { preserveScroll: true });
    }
};

const rule = ref({
    rule_key: '',
    value: '{}',
    effective_from: '',
    effective_until: '',
    evidence_note: '',
    approval_reference: '',
});
const ruleErrors = ref<Record<string, string>>({});

const publishRule = () => {
    let value: Record<string, string | number | boolean | null>;

    try {
        value = JSON.parse(rule.value.value);
    } catch {
        ruleErrors.value = { value: 'Value must be valid JSON, e.g. {"rate_bps": 750}.' };

        return;
    }

    router.post(
        `${base}/rule-versions`,
        {
            rule_key: rule.value.rule_key,
            value,
            effective_from: rule.value.effective_from,
            effective_until: rule.value.effective_until || null,
            evidence_snapshot: { note: rule.value.evidence_note },
            approval_reference: rule.value.approval_reference,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                ruleErrors.value = {};
            },
            onError: (e) => {
                ruleErrors.value = e;
            },
        },
    );
};
</script>

<template>
    <Head title="Tax compliance" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-foreground text-2xl font-bold">Tax compliance</h1>
                <p class="text-muted-foreground text-sm">
                    VAT workpaper, supplier input VAT and tax rules for {{ branch.name }}.
                </p>
            </div>
            <div class="flex items-end gap-2">
                <div>
                    <Label for="tc-month">Month</Label>
                    <Input id="tc-month" v-model="selectedMonth" type="month" />
                </div>
                <Button variant="outline" @click="changeMonth">Load</Button>
            </div>
        </div>

        <p v-if="workpaperError" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:bg-amber-950">
            {{ workpaperError }}
        </p>

        <section v-if="workpaper" class="grid gap-4 md:grid-cols-4">
            <div class="border-border rounded-lg border p-4">
                <p class="text-muted-foreground text-xs">Output VAT</p>
                <p class="text-xl font-semibold">{{ money(workpaper.output_vat_minor) }}</p>
                <p class="text-muted-foreground text-xs">{{ workpaper.output_transaction_count }} transactions</p>
            </div>
            <div class="border-border rounded-lg border p-4">
                <p class="text-muted-foreground text-xs">Recoverable input VAT</p>
                <p class="text-xl font-semibold">{{ money(workpaper.recoverable_input_vat_minor) }}</p>
                <p class="text-muted-foreground text-xs">
                    of {{ money(workpaper.input_vat_minor) }} · {{ money(workpaper.non_recoverable_input_vat_minor) }} not recoverable
                </p>
            </div>
            <div class="border-border rounded-lg border p-4">
                <p class="text-muted-foreground text-xs">Net VAT payable</p>
                <p class="text-xl font-semibold">{{ money(workpaper.net_vat_payable_minor) }}</p>
            </div>
            <div class="border-border rounded-lg border p-4">
                <p class="text-muted-foreground text-xs">VAT status</p>
                <p class="text-xl font-semibold capitalize">{{ workpaper.vat_status.replace(/_/g, ' ') }}</p>
                <p class="text-muted-foreground text-xs">{{ workpaper.supplier_invoice_count }} supplier invoices</p>
            </div>
        </section>

        <section v-if="workpaper">
            <h2 class="text-foreground mb-2 text-lg font-semibold">Supplier invoices this month</h2>
            <div class="border-border overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted text-left">
                        <tr>
                            <th class="p-3">Invoice</th>
                            <th class="p-3">Supply date</th>
                            <th class="p-3">Net</th>
                            <th class="p-3">Input VAT</th>
                            <th class="p-3">Recoverable</th>
                            <th class="p-3">Status</th>
                            <th class="p-3" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="workpaper.supplier_invoices.length === 0">
                            <td colspan="7" class="text-muted-foreground p-6 text-center">None recorded.</td>
                        </tr>
                        <tr v-for="row in workpaper.supplier_invoices" :key="row.id" class="border-border border-t">
                            <td class="p-3">{{ row.invoice_number }}</td>
                            <td class="p-3">{{ row.supply_date }}</td>
                            <td class="p-3">{{ money(row.net_amount_minor) }}</td>
                            <td class="p-3">{{ money(row.input_vat_minor) }}</td>
                            <td class="p-3">{{ money(row.recoverable_input_vat_minor) }}</td>
                            <td class="p-3">
                                <Badge variant="outline">{{ row.claimable ? 'Claimable' : 'Not claimable' }}</Badge>
                            </td>
                            <td class="p-3 text-right">
                                <Button size="sm" variant="destructive" @click="voidInvoice(row.id)">Void</Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <form class="border-border grid gap-4 rounded-lg border p-4 md:grid-cols-3" @submit.prevent="recordInvoice">
            <h2 class="text-foreground text-lg font-semibold md:col-span-3">Record supplier invoice</h2>

            <div>
                <Label for="si-supplier">Supplier</Label>
                <select
                    id="si-supplier"
                    v-model="invoice.supplier_id"
                    class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                >
                    <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">
                        {{ supplier.name }}
                    </option>
                </select>
                <p v-if="invoiceErrors.supplier_id" class="text-destructive mt-1 text-xs">{{ invoiceErrors.supplier_id }}</p>
            </div>
            <div>
                <Label for="si-number">Invoice number</Label>
                <Input id="si-number" v-model="invoice.supplier_invoice_number" />
                <p v-if="invoiceErrors.supplier_invoice_number" class="text-destructive mt-1 text-xs">{{ invoiceErrors.supplier_invoice_number }}</p>
            </div>
            <div>
                <Label for="si-tin">Supplier tax ID</Label>
                <Input id="si-tin" v-model="invoice.supplier_tax_id" />
                <p v-if="invoiceErrors.supplier_tax_id" class="text-destructive mt-1 text-xs">{{ invoiceErrors.supplier_tax_id }}</p>
            </div>
            <div>
                <Label for="si-date">Invoice date</Label>
                <Input id="si-date" v-model="invoice.invoice_date" type="date" />
            </div>
            <div>
                <Label for="si-supply">Supply date</Label>
                <Input id="si-supply" v-model="invoice.supply_date" type="date" />
            </div>
            <div>
                <Label for="si-evidence">Evidence reference</Label>
                <Input id="si-evidence" v-model="invoice.evidence_reference" />
                <p v-if="invoiceErrors.evidence_reference" class="text-destructive mt-1 text-xs">{{ invoiceErrors.evidence_reference }}</p>
            </div>
            <div>
                <Label for="si-net">Net amount</Label>
                <Input id="si-net" v-model="invoice.net" type="number" step="0.01" min="0" />
            </div>
            <div>
                <Label for="si-vat">VAT amount</Label>
                <Input id="si-vat" v-model="invoice.vat" type="number" step="0.01" min="0" />
            </div>
            <div>
                <Label for="si-taxable">Used for taxable supplies</Label>
                <Input id="si-taxable" v-model="invoice.taxable_use" type="number" step="0.01" min="0" />
            </div>
            <div>
                <Label for="si-exempt">Used for exempt supplies</Label>
                <Input id="si-exempt" v-model="invoice.exempt_use" type="number" step="0.01" min="0" />
            </div>
            <label class="flex items-center gap-2 self-end text-sm font-medium">
                <input v-model="invoice.validated_einvoice" type="checkbox" />
                Validated e-invoice
            </label>
            <div v-if="invoice.validated_einvoice">
                <Label for="si-ref">E-invoice reference</Label>
                <Input id="si-ref" v-model="invoice.einvoice_reference" />
                <p v-if="invoiceErrors.einvoice_reference" class="text-destructive mt-1 text-xs">{{ invoiceErrors.einvoice_reference }}</p>
            </div>
            <div class="flex justify-end md:col-span-3">
                <Button type="submit">Record invoice</Button>
            </div>
        </form>

        <section>
            <h2 class="text-foreground mb-2 text-lg font-semibold">Tax rule versions</h2>
            <div class="border-border rounded-lg border">
                <div v-if="ruleVersions.length === 0" class="text-muted-foreground p-6 text-center text-sm">
                    No published rules yet.
                </div>
                <div
                    v-for="version in ruleVersions"
                    :key="version.id"
                    class="border-border flex flex-wrap items-center justify-between gap-3 border-b p-4 last:border-b-0"
                >
                    <div>
                        <span class="text-foreground font-medium">{{ version.rule_key }}</span>
                        <code class="bg-muted ml-2 rounded px-2 py-1 text-xs">{{ JSON.stringify(version.value) }}</code>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ version.effective_from }} → {{ version.effective_until ?? 'open' }} · approval {{ version.approval_reference }}
                        </p>
                    </div>
                    <Badge variant="outline">{{ version.scope === 'all_properties' ? 'All properties' : 'This property' }}</Badge>
                </div>
            </div>
        </section>

        <form class="border-border grid gap-4 rounded-lg border p-4 md:grid-cols-2" @submit.prevent="publishRule">
            <h2 class="text-foreground text-lg font-semibold md:col-span-2">Publish tax rule version</h2>
            <div>
                <Label for="tr-key">Rule key</Label>
                <Input id="tr-key" v-model="rule.rule_key" placeholder="tax_component.vat.rate_bps" />
                <p v-if="ruleErrors.rule_key" class="text-destructive mt-1 text-xs">{{ ruleErrors.rule_key }}</p>
            </div>
            <div>
                <Label for="tr-value">Value (JSON)</Label>
                <Input id="tr-value" v-model="rule.value" placeholder='{"rate_bps": 750}' />
                <p v-if="ruleErrors.value" class="text-destructive mt-1 text-xs">{{ ruleErrors.value }}</p>
                <p v-if="ruleErrors['value.rate_bps']" class="text-destructive mt-1 text-xs">{{ ruleErrors['value.rate_bps'] }}</p>
                <p v-if="ruleErrors['value.status']" class="text-destructive mt-1 text-xs">{{ ruleErrors['value.status'] }}</p>
            </div>
            <div>
                <Label for="tr-from">Effective from</Label>
                <Input id="tr-from" v-model="rule.effective_from" type="date" />
            </div>
            <div>
                <Label for="tr-until">Effective until (optional)</Label>
                <Input id="tr-until" v-model="rule.effective_until" type="date" />
            </div>
            <div>
                <Label for="tr-evidence">Evidence (source of the change)</Label>
                <Input id="tr-evidence" v-model="rule.evidence_note" placeholder="Finance Act 2026, s.12" />
            </div>
            <div>
                <Label for="tr-approval">Approval reference</Label>
                <Input id="tr-approval" v-model="rule.approval_reference" />
                <p v-if="ruleErrors.approval_reference" class="text-destructive mt-1 text-xs">{{ ruleErrors.approval_reference }}</p>
            </div>
            <div class="flex justify-end md:col-span-2">
                <Button type="submit">Publish rule</Button>
            </div>
        </form>
    </div>
</template>
