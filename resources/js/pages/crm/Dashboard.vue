<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Tier {
    id: number;
    name: string;
    threshold_nights: number;
    earn_bps: number;
}

interface TierMix {
    tier: string;
    accounts: number;
    points: number;
}

interface Account {
    id: number;
    points: number;
    tier: string;
    guest: { email: string };
}

const props = defineProps<{
    branch: { id: number; name: string };
    tiers: Tier[];
    tierMix: TierMix[];
    consentCoverage: { guests: number; consented: number };
    recent: Account[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'CRM', href: '#' },
        ],
    },
});

const enrollForm = useForm({ guest_id: undefined as number | undefined });
const redeemForm = useForm({
    guest_id: undefined as number | undefined,
    folio_id: undefined as number | undefined,
    points: 1,
});

const base = `/branches/${props.branch.id}/crm`;
</script>

<template>
    <Head title="CRM & Loyalty" />
    <div class="space-y-8 p-6">
        <div>
            <h1 class="text-2xl font-semibold">CRM — {{ branch.name }}</h1>
            <p class="text-muted-foreground text-sm">
                Tiers, wallets, consent coverage. Points buy folio credit at 10
                minor each.
            </p>
        </div>

        <section class="grid gap-3 md:grid-cols-4">
            <div v-for="t in tiers" :key="t.id" class="rounded-lg border p-4">
                <div class="font-medium">{{ t.name }}</div>
                <div class="text-muted-foreground font-mono text-xs">
                    {{ t.threshold_nights }}+ nights ·
                    {{ (t.earn_bps / 100).toFixed(0) }}% earn
                </div>
            </div>
        </section>

        <section class="grid gap-6 md:grid-cols-2">
            <div class="space-y-3">
                <h2 class="text-lg font-medium">Tier mix</h2>
                <div class="rounded-lg border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="text-muted-foreground border-b text-left"
                            >
                                <th class="p-3">Tier</th>
                                <th class="p-3">Wallets</th>
                                <th class="p-3">Points</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="m in tierMix"
                                :key="m.tier"
                                class="border-b last:border-0"
                            >
                                <td class="p-3 font-medium">{{ m.tier }}</td>
                                <td class="p-3">{{ m.accounts }}</td>
                                <td class="p-3 font-mono text-xs">
                                    {{ m.points }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted-foreground text-sm">
                    Consent coverage: {{ consentCoverage.consented }} /
                    {{ consentCoverage.guests }} stay guests opted in.
                </p>
            </div>
            <div class="space-y-3">
                <h2 class="text-lg font-medium">Enroll + redeem</h2>
                <form
                    @submit.prevent="
                        enrollForm.post(`${base}/enroll`, {
                            onSuccess: () => enrollForm.reset(),
                        })
                    "
                    class="flex items-end gap-2 rounded-lg border p-4"
                >
                    <div class="grid gap-2">
                        <Label>Guest id</Label
                        ><Input
                            v-model.number="enrollForm.guest_id"
                            type="number"
                            required
                        />
                    </div>
                    <Button type="submit">Enroll</Button>
                </form>
                <form
                    @submit.prevent="
                        redeemForm.post(`${base}/redeem`, {
                            onSuccess: () => redeemForm.reset(),
                        })
                    "
                    class="grid grid-cols-3 items-end gap-2 rounded-lg border p-4"
                >
                    <div class="grid gap-2">
                        <Label>Guest id</Label
                        ><Input
                            v-model.number="redeemForm.guest_id"
                            type="number"
                            required
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Folio id</Label
                        ><Input
                            v-model.number="redeemForm.folio_id"
                            type="number"
                            required
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Points</Label
                        ><Input
                            v-model.number="redeemForm.points"
                            type="number"
                            min="1"
                            required
                        />
                    </div>
                    <div class="col-span-3">
                        <Button type="submit">Redeem to folio</Button>
                    </div>
                </form>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Recent wallets</h2>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Guest</th>
                            <th class="p-3">Tier</th>
                            <th class="p-3">Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="a in recent"
                            :key="a.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ a.guest.email }}</td>
                            <td class="p-3">{{ a.tier }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ a.points }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
