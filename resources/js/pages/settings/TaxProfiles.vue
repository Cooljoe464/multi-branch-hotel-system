<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface TaxComponent {
    id: number;
    code: string;
    mode: string;
    rate_bps: number;
    applies_to: string;
    sequence: number;
}

interface TaxProfile {
    id: number;
    jurisdiction: string;
    name: string;
    active: boolean;
    components: TaxComponent[];
}

const props = defineProps<{
    branch: { id: number; name: string; code: string };
    profiles: TaxProfile[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Tax Profiles', href: '#' },
        ],
    },
});

const form = useForm({
    jurisdiction: 'NG-LA',
    name: '',
    components: [
        { code: 'VAT', mode: 'exclusive', rate_bps: 750, applies_to: 'all' },
    ],
});

function submit() {
    form.post(`/branches/${props.branch.id}/tax-profiles`);
}

function deactivate(id: number) {
    router.post(`/branches/${props.branch.id}/tax-profiles/${id}/deactivate`);
}
</script>

<template>
    <Head title="Tax Profiles" />
    <div class="space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold">
                Tax Profiles — {{ branch.name }}
            </h1>
            <p class="text-muted-foreground text-sm">
                Rates snapshot onto every folio line at posting time.
                Deactivating never rewrites history.
            </p>
        </div>

        <div
            v-for="profile in profiles"
            :key="profile.id"
            class="rounded-lg border p-4"
        >
            <div class="flex items-center justify-between">
                <div class="font-medium">
                    {{ profile.name }}
                    <span class="text-muted-foreground"
                        >({{ profile.jurisdiction }})</span
                    >
                </div>
                <div class="flex items-center gap-2">
                    <span
                        class="text-xs uppercase"
                        :class="
                            profile.active
                                ? 'text-emerald-600'
                                : 'text-muted-foreground'
                        "
                        >{{ profile.active ? 'active' : 'inactive' }}</span
                    >
                    <Button
                        v-if="profile.active"
                        variant="outline"
                        size="sm"
                        @click="deactivate(profile.id)"
                        >Deactivate</Button
                    >
                </div>
            </div>
            <table class="mt-2 w-full text-sm">
                <tbody>
                    <tr
                        v-for="c in profile.components"
                        :key="c.id"
                        class="border-t"
                    >
                        <td class="py-1 font-mono">{{ c.code }}</td>
                        <td class="py-1">{{ c.mode }}</td>
                        <td class="py-1">
                            {{ (c.rate_bps / 100).toFixed(2) }}%
                        </td>
                        <td class="text-muted-foreground py-1">
                            {{ c.applies_to }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <form @submit.prevent="submit" class="space-y-4 rounded-lg border p-4">
            <h2 class="font-semibold">New profile</h2>
            <div class="grid gap-2">
                <Label>Jurisdiction</Label>
                <Input v-model="form.jurisdiction" required />
            </div>
            <div class="grid gap-2">
                <Label>Name</Label>
                <Input v-model="form.name" required />
            </div>
            <Button type="submit" :disabled="form.processing"
                >Create profile</Button
            >
        </form>
    </div>
</template>
