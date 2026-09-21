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

interface Plan {
    id: number;
    code: string;
    name: string;
}

interface Policy {
    id: number;
    kind: string;
    rate_plan_id: number | null;
    rate_plan: Plan | null;
    rules: Record<string, number | string>;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    policies: Policy[];
    plans: Plan[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Guarantees', href: '#' },
        ],
    },
});

const form = useForm({
    kind: 'deposit_schedule',
    rate_plan_id: null as number | null,
    deposit_bps: 2000,
    due_hours_before_arrival: 48,
    hold_hours: 24,
    cancel_free_until_hours: 24,
    no_show_fee: 'first_night',
    no_show_fee_bps: 0,
});

const base = `/branches/${props.branch.id}/guarantees`;

function submit() {
    form.post(base, {
        onSuccess: () => {
            form.reset();
            router.get(base, {}, { preserveState: true });
        },
    });
}

function rule(p: Policy, key: string): string {
    return String(p.rules?.[key] ?? '—');
}
</script>

<template>
    <Head title="Guarantee Policies" />
    <div class="space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">
                Guarantee Policies — {{ branch.name }}
            </h1>
            <p class="text-muted-foreground text-sm">
                Deposits, free-cancellation windows, no-show fees and hold
                lifetimes. Plan-specific policies override the branch default.
            </p>
        </div>

        <form
            @submit.prevent="submit"
            class="grid grid-cols-2 gap-3 rounded-lg border p-4 md:grid-cols-4"
        >
            <div class="grid gap-2">
                <Label>Kind</Label>
                <Select
                    :model-value="form.kind"
                    @update:model-value="form.kind = String($event)"
                >
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="deposit_schedule"
                            >Deposit schedule</SelectItem
                        >
                        <SelectItem value="card_guarantee"
                            >Card guarantee</SelectItem
                        >
                        <SelectItem value="company_guarantee"
                            >Company guarantee</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-2">
                <Label>Rate plan (blank = default)</Label>
                <Select
                    :model-value="
                        form.rate_plan_id ? String(form.rate_plan_id) : ''
                    "
                    @update:model-value="
                        form.rate_plan_id =
                            $event === '' ? null : Number($event)
                    "
                >
                    <SelectTrigger
                        ><SelectValue placeholder="Branch default"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="pl in plans"
                            :key="pl.id"
                            :value="String(pl.id)"
                            >{{ pl.code }} — {{ pl.name }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-2">
                <Label>Deposit (bps)</Label
                ><Input
                    v-model.number="form.deposit_bps"
                    type="number"
                    min="0"
                />
            </div>
            <div class="grid gap-2">
                <Label>Due (hrs before arrival)</Label
                ><Input
                    v-model.number="form.due_hours_before_arrival"
                    type="number"
                    min="0"
                />
            </div>
            <div class="grid gap-2">
                <Label>Hold (hours)</Label
                ><Input
                    v-model.number="form.hold_hours"
                    type="number"
                    min="1"
                />
            </div>
            <div class="grid gap-2">
                <Label>Free cancel (hrs)</Label
                ><Input
                    v-model.number="form.cancel_free_until_hours"
                    type="number"
                    min="0"
                />
            </div>
            <div class="grid gap-2">
                <Label>No-show fee</Label>
                <Select
                    :model-value="form.no_show_fee"
                    @update:model-value="form.no_show_fee = String($event)"
                >
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="first_night">First night</SelectItem>
                        <SelectItem value="percent">Percent of stay</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-2">
                <Label>Fee (bps, if percent)</Label
                ><Input
                    v-model.number="form.no_show_fee_bps"
                    type="number"
                    min="0"
                />
            </div>
            <div class="col-span-2 flex items-end md:col-span-4">
                <Button type="submit">Add policy</Button>
            </div>
        </form>

        <div class="rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-muted-foreground border-b text-left">
                        <th class="p-3">Kind</th>
                        <th class="p-3">Plan</th>
                        <th class="p-3">Deposit</th>
                        <th class="p-3">Hold</th>
                        <th class="p-3">Free cancel</th>
                        <th class="p-3">No-show</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="p in policies"
                        :key="p.id"
                        class="border-b last:border-0"
                    >
                        <td class="p-3 font-medium">{{ p.kind }}</td>
                        <td class="p-3">
                            {{ p.rate_plan?.code ?? 'default' }}
                        </td>
                        <td class="p-3 font-mono text-xs">
                            {{ rule(p, 'deposit_bps') }} bps
                        </td>
                        <td class="p-3 font-mono text-xs">
                            {{ rule(p, 'hold_hours') }}h
                        </td>
                        <td class="p-3 font-mono text-xs">
                            {{ rule(p, 'cancel_free_until_hours') }}h
                        </td>
                        <td class="p-3 font-mono text-xs">
                            {{ rule(p, 'no_show_fee')
                            }}{{
                                rule(p, 'no_show_fee') === 'percent'
                                    ? ` ${rule(p, 'no_show_fee_bps')}bps`
                                    : ''
                            }}
                        </td>
                        <td class="p-3 text-right">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="router.delete(`${base}/${p.id}`)"
                                >Delete</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
