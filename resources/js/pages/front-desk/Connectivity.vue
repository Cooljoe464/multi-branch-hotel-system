<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';

interface Branch {
    id: number;
    name: string;
}
interface Rate {
    id: number;
    destination_prefix: string;
    rate_minor_per_min: number;
    is_active: boolean;
}
interface Call {
    id: number;
    extension: string;
    destination: string;
    duration_secs: number;
    charge_minor: number;
    reservation_id: number | null;
    cdr_id: string;
    created_at: string;
}
interface Voucher {
    id: number;
    voucher: string;
    reservation_id: number | null;
    expires_at: string | null;
    revoked_at: string | null;
}
interface Key {
    id: number;
    device_id: string;
    status: string;
    valid_to: string | null;
}
interface Lookup {
    id: number;
    confirmation_number: string;
    guest_name: string;
    status: string;
    keys: Key[];
}

const props = defineProps<{
    branch: Branch;
    rates: Rate[];
    calls: Call[];
    vouchers: Voucher[];
    cdr_configured: boolean;
    lookup: Lookup | null;
    confirmation: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Front Desk', href: '/front-desk' },
            { title: 'Connectivity', href: '/front-desk' },
        ],
    },
});

const confirmation = ref(props.confirmation);
const deviceId = ref('');
const rateForm = ref({ destination_prefix: '', rate_minor_per_min: 0 });
const secret = ref('');
const voucherForm = ref({ confirmation_number: '', guest_name: '' });

const search = () =>
    router.get(
        `/branches/${props.branch.id}/connectivity`,
        { confirmation: confirmation.value },
        { preserveState: true },
    );
const issueKey = () =>
    router.post(`/branches/${props.branch.id}/mobile-keys`, {
        reservation_id: props.lookup?.id,
        device_id: deviceId.value,
    });
const revokeKey = (id: number) =>
    router.delete(`/branches/${props.branch.id}/mobile-keys/${id}`);
const saveRate = () =>
    router.post(`/branches/${props.branch.id}/telecom/rates`, {
        ...rateForm.value,
    });
const saveSecret = () =>
    router.post(`/branches/${props.branch.id}/telecom/secret`, {
        cdr_secret: secret.value,
    });
const issueVoucher = () =>
    router.post(`/branches/${props.branch.id}/wifi/vouchers`, {
        ...voucherForm.value,
    });
const revokeVoucher = (id: number) =>
    router.post(`/branches/${props.branch.id}/wifi/vouchers/${id}/revoke`);
</script>

<template>
    <Head title="Connectivity" />
    <div class="p-6">
        <h1 class="text-foreground mb-6 text-2xl font-bold">
            Keys & Connectivity — {{ branch.name }}
        </h1>

        <h2 class="text-foreground mb-3 text-lg font-semibold">Mobile Keys</h2>
        <div class="mb-4 flex items-end gap-3">
            <div class="grid gap-2">
                <Label>Confirmation</Label
                ><Input v-model="confirmation" placeholder="HTL-…" />
            </div>
            <Button variant="outline" @click="search">Look Up</Button>
        </div>
        <div v-if="lookup" class="border-border mb-8 rounded-lg border p-4">
            <p class="text-foreground mb-2 text-sm">
                <span class="font-medium">{{ lookup.guest_name }}</span> ·
                {{ lookup.status }}
            </p>
            <div class="mb-3 space-y-1">
                <div
                    v-for="k in lookup.keys"
                    :key="k.id"
                    class="flex items-center justify-between text-sm"
                >
                    <span class="text-foreground font-mono">{{
                        k.device_id
                    }}</span>
                    <span class="flex items-center gap-2">
                        <Badge variant="outline">{{ k.status }}</Badge>
                        <Button
                            v-if="k.status === 'active'"
                            size="sm"
                            variant="outline"
                            @click="revokeKey(k.id)"
                            >Revoke</Button
                        >
                    </span>
                </div>
                <p
                    v-if="lookup.keys.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No keys yet.
                </p>
            </div>
            <div class="flex items-end gap-3">
                <div class="grid gap-2">
                    <Label>Device ID</Label
                    ><Input v-model="deviceId" placeholder="guest-phone-1" />
                </div>
                <Button @click="issueKey">Issue Key</Button>
            </div>
        </div>

        <h2 class="text-foreground mb-3 text-lg font-semibold">
            Wi-Fi Vouchers
        </h2>
        <div class="mb-4 flex items-end gap-3">
            <div class="grid gap-2">
                <Label>Confirmation</Label
                ><Input v-model="voucherForm.confirmation_number" />
            </div>
            <div class="grid gap-2">
                <Label>Guest name</Label
                ><Input v-model="voucherForm.guest_name" />
            </div>
            <Button @click="issueVoucher">Issue & Print</Button>
        </div>
        <div class="border-border mb-8 rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Voucher
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Expires
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Status
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-right font-medium"
                        >
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="v in vouchers"
                        :key="v.id"
                        class="border-border border-t"
                    >
                        <td
                            class="text-foreground p-4 font-mono text-lg font-bold tracking-widest"
                        >
                            {{ v.voucher }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ v.expires_at ?? '—' }}
                        </td>
                        <td class="p-4">
                            <Badge variant="outline">{{
                                v.revoked_at ? 'revoked' : 'live'
                            }}</Badge>
                        </td>
                        <td class="p-4 text-right">
                            <Button
                                v-if="!v.revoked_at"
                                size="sm"
                                variant="outline"
                                @click="revokeVoucher(v.id)"
                                >Revoke</Button
                            >
                        </td>
                    </tr>
                    <tr v-if="vouchers.length === 0">
                        <td
                            colspan="4"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No vouchers yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 class="text-foreground mb-3 text-lg font-semibold">Telecom</h2>
        <p class="text-muted-foreground mb-3 text-sm">
            CDR webhook:
            <span class="font-mono">POST /api/cdr/{{ branch.id }}</span> signed
            with the branch secret.
            <Badge variant="outline" class="ml-2">{{
                cdr_configured ? 'configured' : 'no secret'
            }}</Badge>
        </p>
        <div class="mb-4 flex items-end gap-3">
            <div class="grid gap-2">
                <Label>CDR secret (min 32 chars)</Label
                ><Input v-model="secret" type="password" class="w-72" />
            </div>
            <Button variant="outline" @click="saveSecret">Save Secret</Button>
        </div>
        <div class="mb-4 flex items-end gap-3">
            <div class="grid gap-2">
                <Label>Destination prefix</Label
                ><Input
                    v-model="rateForm.destination_prefix"
                    placeholder="+234"
                />
            </div>
            <div class="grid gap-2">
                <Label>Minor per minute</Label
                ><Input
                    v-model.number="rateForm.rate_minor_per_min"
                    type="number"
                    min="0"
                />
            </div>
            <Button variant="outline" @click="saveRate">Save Rate</Button>
        </div>
        <div class="border-border rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Extension
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Destination
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Secs
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Charge
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            CDR
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="c in calls"
                        :key="c.id"
                        class="border-border border-t"
                    >
                        <td class="text-foreground p-4">{{ c.extension }}</td>
                        <td class="text-muted-foreground p-4">
                            {{ c.destination }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ c.duration_secs }}
                        </td>
                        <td class="text-foreground p-4">
                            {{ c.charge_minor }}
                        </td>
                        <td class="text-muted-foreground p-4 font-mono text-xs">
                            {{ c.cdr_id }}
                        </td>
                    </tr>
                    <tr v-if="calls.length === 0">
                        <td
                            colspan="5"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No calls yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
