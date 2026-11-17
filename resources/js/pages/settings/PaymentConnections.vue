<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CreditCard } from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Payment connections', href: '/settings/payment-connections' },
        ],
    },
});

interface Connection {
    id: number;
    name: string;
    provider: string;
    mode: string;
    status: string;
    is_default: boolean;
    scope: 'this_property' | 'all_properties';
    merchant_reference: string | null;
    has_webhook_secret: boolean;
    webhook_url: string;
}

defineProps<{
    branch: { id: number; name: string };
    connections: Connection[];
    providers: string[];
}>();

const blankForm = () => ({
    name: '',
    provider: 'paystack',
    mode: 'test',
    scope: 'this_property',
    secret_key: '',
    webhook_secret: '',
    merchant_reference: '',
    is_default: false,
});

const form = ref(blankForm());
const errors = ref<Record<string, string>>({});
const saving = ref(false);

const create = () => {
    saving.value = true;
    router.post('/settings/payment-connections', form.value, {
        preserveScroll: true,
        onSuccess: () => {
            form.value = blankForm();
            errors.value = {};
        },
        onError: (e) => {
            errors.value = e;
        },
        onFinish: () => {
            saving.value = false;
        },
    });
};

const patch = (
    connection: Connection,
    data: { status?: string; is_default?: boolean },
) => {
    router.put(`/settings/payment-connections/${connection.id}`, data, {
        preserveScroll: true,
    });
};

const remove = (connection: Connection) => {
    router.delete(`/settings/payment-connections/${connection.id}`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Payment connections" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
        <div>
            <h1 class="text-foreground text-2xl font-bold">Payment connections</h1>
            <p class="text-muted-foreground text-sm">
                Gateway accounts that receive guest payments for {{ branch.name }}. Secrets are stored encrypted and never shown again.
            </p>
        </div>

        <div class="border-border rounded-lg border">
            <div
                v-if="connections.length === 0"
                class="text-muted-foreground p-6 text-center text-sm"
            >
                No connections yet. Add one below to take online payments.
            </div>

            <div
                v-for="connection in connections"
                :key="connection.id"
                class="border-border flex flex-wrap items-center justify-between gap-3 border-b p-4 last:border-b-0"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <CreditCard class="size-4" />
                        <span class="text-foreground font-medium">{{ connection.name }}</span>
                        <Badge variant="outline" class="capitalize">{{ connection.provider }}</Badge>
                        <Badge variant="outline">{{ connection.mode }}</Badge>
                        <Badge v-if="connection.is_default">Default</Badge>
                        <Badge
                            variant="outline"
                            :class="
                                connection.status === 'active'
                                    ? 'border-green-300 text-green-700 dark:text-green-300'
                                    : 'border-red-300 text-red-700 dark:text-red-300'
                            "
                        >
                            {{ connection.status }}
                        </Badge>
                    </div>
                    <p class="text-muted-foreground mt-1 text-xs">
                        {{ connection.scope === 'all_properties' ? 'All properties' : 'This property' }}
                        · Webhook secret {{ connection.has_webhook_secret ? 'stored' : 'missing' }}
                    </p>
                    <code class="bg-muted text-foreground mt-1 inline-block rounded px-2 py-1 text-xs">
                        {{ connection.webhook_url }}
                    </code>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="!connection.is_default"
                        size="sm"
                        variant="outline"
                        @click="patch(connection, { is_default: true })"
                    >
                        Make default
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="patch(connection, { status: connection.status === 'active' ? 'disabled' : 'active' })"
                    >
                        {{ connection.status === 'active' ? 'Disable' : 'Enable' }}
                    </Button>
                    <Button size="sm" variant="destructive" @click="remove(connection)">
                        Delete
                    </Button>
                </div>
            </div>
        </div>

        <form
            class="border-border grid gap-4 rounded-lg border p-4 md:grid-cols-2"
            @submit.prevent="create"
        >
            <h2 class="text-foreground text-lg font-semibold md:col-span-2">Add connection</h2>

            <div>
                <Label for="pc-name">Name</Label>
                <Input id="pc-name" v-model="form.name" placeholder="Main Paystack account" />
                <p v-if="errors.name" class="text-destructive mt-1 text-xs">{{ errors.name }}</p>
            </div>

            <div>
                <Label for="pc-provider">Provider</Label>
                <select
                    id="pc-provider"
                    v-model="form.provider"
                    class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm capitalize"
                >
                    <option v-for="provider in providers" :key="provider" :value="provider">
                        {{ provider }}
                    </option>
                </select>
            </div>

            <div>
                <Label for="pc-mode">Mode</Label>
                <select
                    id="pc-mode"
                    v-model="form.mode"
                    class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                >
                    <option value="test">Test</option>
                    <option value="live">Live</option>
                </select>
            </div>

            <div>
                <Label for="pc-scope">Applies to</Label>
                <select
                    id="pc-scope"
                    v-model="form.scope"
                    class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                >
                    <option value="this_property">This property only</option>
                    <option value="all_properties">All properties</option>
                </select>
            </div>

            <div>
                <Label for="pc-secret">Secret key</Label>
                <Input id="pc-secret" v-model="form.secret_key" type="password" autocomplete="off" />
                <p v-if="errors.secret_key" class="text-destructive mt-1 text-xs">{{ errors.secret_key }}</p>
            </div>

            <div>
                <Label for="pc-webhook">Webhook secret</Label>
                <Input id="pc-webhook" v-model="form.webhook_secret" type="password" autocomplete="off" />
            </div>

            <div>
                <Label for="pc-ref">Merchant reference (optional)</Label>
                <Input id="pc-ref" v-model="form.merchant_reference" />
            </div>

            <label class="flex items-center gap-2 self-end text-sm font-medium">
                <input v-model="form.is_default" type="checkbox" />
                Use as the default connection
            </label>

            <div class="flex justify-end md:col-span-2">
                <Button type="submit" :disabled="saving">
                    {{ saving ? 'Saving…' : 'Add connection' }}
                </Button>
            </div>
        </form>
    </div>
</template>
