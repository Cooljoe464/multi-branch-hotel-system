<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

interface Accrual {
    id: number;
    source: string;
    base_minor: number;
    amount_minor: number;
    status: string;
    reservation: { confirmation_number: string } | null;
}

interface Payout {
    id: number;
    source: string;
    amount_minor: number;
    status: string;
    reference: string | null;
    accruals: { id: number }[];
}

interface Rule {
    id: number;
    source: string;
    rate_bps: number;
    base: string;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    accruals: { data: Accrual[] };
    payouts: Payout[];
    rules: Rule[];
    filters: { source: string | null; status: string | null };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Commissions', href: '#' },
        ],
    },
});

const ruleForm = useForm({ source: '', rate_bps: 1500, base: 'net_room' });
const payoutForm = useForm({
    source: '',
    accrual_ids: [] as number[],
    amount_minor: undefined as number | undefined,
    reference: '',
});

const base = `/branches/${props.branch.id}/commissions`;

function toggleAccrual(id: number) {
    const ids = payoutForm.accrual_ids;
    payoutForm.accrual_ids = ids.includes(id)
        ? ids.filter((i) => i !== id)
        : [...ids, id];
}
</script>

<template>
    <Head title="Commissions" />
    <div class="space-y-8 p-6">
        <div>
            <h1 class="text-2xl font-semibold">
                Commissions — {{ branch.name }}
            </h1>
            <p class="text-muted-foreground text-sm">
                Accrual inbox, payout builder and rate rules. Amounts in minor
                units.
            </p>
        </div>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Payout builder</h2>
            <form
                @submit.prevent="payoutForm.post(`${base}/payouts`)"
                class="grid grid-cols-2 gap-3 rounded-lg border p-4 md:grid-cols-4"
            >
                <div class="grid gap-2">
                    <Label>Source</Label
                    ><Input v-model="payoutForm.source" required />
                </div>
                <div class="grid gap-2">
                    <Label>Amount (minor, blank = linked sum)</Label
                    ><Input
                        v-model.number="payoutForm.amount_minor"
                        type="number"
                        min="0"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Reference</Label
                    ><Input v-model="payoutForm.reference" />
                </div>
                <div class="flex items-end">
                    <Button
                        type="submit"
                        :disabled="!payoutForm.accrual_ids.length"
                        >Build payout ({{
                            payoutForm.accrual_ids.length
                        }})</Button
                    >
                </div>
            </form>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3"></th>
                            <th class="p-3">Accrual</th>
                            <th class="p-3">Booking</th>
                            <th class="p-3">Source</th>
                            <th class="p-3">Base</th>
                            <th class="p-3">Amount</th>
                            <th class="p-3">Status</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="a in accruals.data"
                            :key="a.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3">
                                <input
                                    v-if="a.status === 'accrued'"
                                    type="checkbox"
                                    :checked="
                                        payoutForm.accrual_ids.includes(a.id)
                                    "
                                    @change="toggleAccrual(a.id)"
                                />
                            </td>
                            <td class="p-3 font-medium">#{{ a.id }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ a.reservation?.confirmation_number ?? '—' }}
                            </td>
                            <td class="p-3">{{ a.source }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ a.base_minor }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ a.amount_minor }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ a.status }}
                            </td>
                            <td class="p-3 text-right">
                                <Button
                                    v-if="a.status === 'accrued'"
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        router.post(
                                            `${base}/accruals/${a.id}/dispute`,
                                        )
                                    "
                                    >Dispute</Button
                                >
                                <Button
                                    v-else-if="a.status === 'disputed'"
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        router.post(
                                            `${base}/accruals/${a.id}/resolve`,
                                        )
                                    "
                                    >Resolve</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Payouts</h2>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Payout</th>
                            <th class="p-3">Source</th>
                            <th class="p-3">Amount</th>
                            <th class="p-3">Lines</th>
                            <th class="p-3">Status</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in payouts"
                            :key="p.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">
                                #{{ p.id
                                }}{{ p.reference ? ` (${p.reference})` : '' }}
                            </td>
                            <td class="p-3">{{ p.source }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ p.amount_minor }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ p.accruals.length }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ p.status }}
                            </td>
                            <td class="p-3 text-right">
                                <Button
                                    v-if="p.status === 'pending'"
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        router.post(
                                            `${base}/payouts/${p.id}/pay`,
                                        )
                                    "
                                    >Pay</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Rate rules</h2>
            <form
                @submit.prevent="ruleForm.post(`${base}/rules`)"
                class="grid grid-cols-2 gap-3 rounded-lg border p-4 md:grid-cols-4"
            >
                <div class="grid gap-2">
                    <Label>Source</Label
                    ><Input
                        v-model="ruleForm.source"
                        placeholder="bookingcom"
                        required
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Rate (bps)</Label
                    ><Input
                        v-model.number="ruleForm.rate_bps"
                        type="number"
                        min="0"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Base</Label>
                    <Select
                        :model-value="ruleForm.base"
                        @update:model-value="ruleForm.base = String($event)"
                    >
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="net_room">Net room</SelectItem>
                            <SelectItem value="gross">Gross</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="flex items-end">
                    <Button type="submit">Save rule</Button>
                </div>
            </form>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Source</th>
                            <th class="p-3">Rate</th>
                            <th class="p-3">Base</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in rules"
                            :key="r.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ r.source }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ (r.rate_bps / 100).toFixed(1) }}%
                            </td>
                            <td class="p-3 font-mono text-xs">{{ r.base }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
