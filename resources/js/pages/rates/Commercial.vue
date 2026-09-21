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

interface Season {
    id: number;
    name: string;
    code: string;
    start_month: number;
    start_day: number;
    end_month: number;
    end_day: number;
    multiplier_bps: number;
    priority: number;
}

interface Promo {
    id: number;
    code: string;
    discount_bps: number | null;
    discount_fixed_minor: number | null;
    max_uses: number | null;
    uses_count: number;
    min_nights: number;
    valid_from: string;
    valid_to: string | null;
}

interface Corporate {
    id: number;
    name: string;
    code: string;
    negotiated_plan_id: number | null;
    negotiated_plan: Plan | null;
    discount_bps: number;
    ledger_account: string | null;
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    seasons: Season[];
    promos: Promo[];
    corporates: Corporate[];
    plans: Plan[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Commercial', href: '#' },
        ],
    },
});

const seasonForm = useForm({
    name: '',
    code: '',
    start_month: 12,
    start_day: 20,
    end_month: 1,
    end_day: 5,
    multiplier_bps: 12000,
    priority: 10,
});

const promoForm = useForm({
    code: '',
    discount_bps: undefined as number | undefined,
    discount_fixed_minor: undefined as number | undefined,
    max_uses: undefined as number | undefined,
    min_nights: 1,
    valid_from: '',
    valid_to: '' as string,
});

const corporateForm = useForm({
    name: '',
    code: '',
    negotiated_plan_id: null as number | null,
    discount_bps: 0,
    ledger_account: '',
});

const base = `/branches/${props.branch.id}/commercial`;

function refresh() {
    router.get(`${base}`, {}, { preserveState: true });
}
</script>

<template>
    <Head title="Commercial Setup" />
    <div class="space-y-8 p-6">
        <div>
            <h1 class="text-2xl font-semibold">
                Commercial — {{ branch.name }}
            </h1>
            <p class="text-muted-foreground text-sm">
                Seasons, promo codes and corporate accounts that feed the rate
                engine.
            </p>
        </div>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Seasons</h2>
            <form
                @submit.prevent="
                    seasonForm.post(`${base}/seasons`, {
                        onSuccess: () => {
                            seasonForm.reset();
                            refresh();
                        },
                    })
                "
                class="grid grid-cols-2 gap-3 rounded-lg border p-4 md:grid-cols-4"
            >
                <div class="grid gap-2">
                    <Label>Name</Label
                    ><Input v-model="seasonForm.name" required />
                </div>
                <div class="grid gap-2">
                    <Label>Code</Label
                    ><Input v-model="seasonForm.code" required />
                </div>
                <div class="grid gap-2">
                    <Label>Start (M/D)</Label>
                    <div class="flex gap-1">
                        <Input
                            v-model.number="seasonForm.start_month"
                            type="number"
                            min="1"
                            max="12"
                        /><Input
                            v-model.number="seasonForm.start_day"
                            type="number"
                            min="1"
                            max="31"
                        />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label>End (M/D)</Label>
                    <div class="flex gap-1">
                        <Input
                            v-model.number="seasonForm.end_month"
                            type="number"
                            min="1"
                            max="12"
                        /><Input
                            v-model.number="seasonForm.end_day"
                            type="number"
                            min="1"
                            max="31"
                        />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label>Multiplier (bps)</Label
                    ><Input
                        v-model.number="seasonForm.multiplier_bps"
                        type="number"
                        min="1000"
                        step="100"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Priority</Label
                    ><Input
                        v-model.number="seasonForm.priority"
                        type="number"
                        min="0"
                    />
                </div>
                <div class="col-span-2 flex items-end md:col-span-2">
                    <Button type="submit">Add season</Button>
                </div>
            </form>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Code</th>
                            <th class="p-3">Window</th>
                            <th class="p-3">Multiplier</th>
                            <th class="p-3">Priority</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="s in seasons"
                            :key="s.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ s.code }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ s.start_month }}/{{ s.start_day }} –
                                {{ s.end_month }}/{{ s.end_day }}
                            </td>
                            <td class="p-3">
                                {{ (s.multiplier_bps / 100).toFixed(0) }}%
                            </td>
                            <td class="p-3">{{ s.priority }}</td>
                            <td class="p-3 text-right">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        router.delete(`${base}/seasons/${s.id}`)
                                    "
                                    >Delete</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Promo codes</h2>
            <form
                @submit.prevent="
                    promoForm.post(`${base}/promos`, {
                        onSuccess: () => {
                            promoForm.reset();
                            refresh();
                        },
                    })
                "
                class="grid grid-cols-2 gap-3 rounded-lg border p-4 md:grid-cols-4"
            >
                <div class="grid gap-2">
                    <Label>Code</Label
                    ><Input v-model="promoForm.code" required />
                </div>
                <div class="grid gap-2">
                    <Label>Discount (bps)</Label
                    ><Input
                        v-model.number="promoForm.discount_bps"
                        type="number"
                        min="1"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Fixed (minor)</Label
                    ><Input
                        v-model.number="promoForm.discount_fixed_minor"
                        type="number"
                        min="1"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Max uses</Label
                    ><Input
                        v-model.number="promoForm.max_uses"
                        type="number"
                        min="1"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Min nights</Label
                    ><Input
                        v-model.number="promoForm.min_nights"
                        type="number"
                        min="1"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Valid from</Label
                    ><Input
                        v-model="promoForm.valid_from"
                        type="date"
                        required
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Valid to</Label
                    ><Input v-model="promoForm.valid_to" type="date" />
                </div>
                <div class="flex items-end">
                    <Button type="submit">Add promo</Button>
                </div>
            </form>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Code</th>
                            <th class="p-3">Discount</th>
                            <th class="p-3">Uses</th>
                            <th class="p-3">Window</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in promos"
                            :key="p.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ p.code }}</td>
                            <td class="p-3">
                                {{
                                    p.discount_bps
                                        ? `${(p.discount_bps / 100).toFixed(0)}%`
                                        : ''
                                }}
                                {{
                                    p.discount_fixed_minor
                                        ? `${p.discount_fixed_minor} minor`
                                        : ''
                                }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ p.uses_count
                                }}{{ p.max_uses ? ` / ${p.max_uses}` : '' }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ p.valid_from }} → {{ p.valid_to ?? '∞' }}
                            </td>
                            <td class="p-3 text-right">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        router.delete(`${base}/promos/${p.id}`)
                                    "
                                    >Delete</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Corporate accounts</h2>
            <form
                @submit.prevent="
                    corporateForm.post(`${base}/corporate`, {
                        onSuccess: () => {
                            corporateForm.reset();
                            refresh();
                        },
                    })
                "
                class="grid grid-cols-2 gap-3 rounded-lg border p-4 md:grid-cols-4"
            >
                <div class="grid gap-2">
                    <Label>Name</Label
                    ><Input v-model="corporateForm.name" required />
                </div>
                <div class="grid gap-2">
                    <Label>Code</Label
                    ><Input v-model="corporateForm.code" required />
                </div>
                <div class="grid gap-2">
                    <Label>Negotiated plan</Label>
                    <Select
                        :model-value="
                            corporateForm.negotiated_plan_id
                                ? String(corporateForm.negotiated_plan_id)
                                : ''
                        "
                        @update:model-value="
                            corporateForm.negotiated_plan_id =
                                $event === '' ? null : Number($event)
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="None"
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
                    <Label>Discount (bps)</Label
                    ><Input
                        v-model.number="corporateForm.discount_bps"
                        type="number"
                        min="0"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Ledger account</Label
                    ><Input v-model="corporateForm.ledger_account" />
                </div>
                <div class="flex items-end">
                    <Button type="submit">Add account</Button>
                </div>
            </form>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Code</th>
                            <th class="p-3">Name</th>
                            <th class="p-3">Plan / discount</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in corporates"
                            :key="c.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ c.code }}</td>
                            <td class="p-3">{{ c.name }}</td>
                            <td class="p-3">
                                {{
                                    c.negotiated_plan?.code ??
                                    `${(c.discount_bps / 100).toFixed(0)}% off`
                                }}
                            </td>
                            <td class="p-3 text-right">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        router.delete(
                                            `${base}/corporate/${c.id}`,
                                        )
                                    "
                                    >Delete</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
