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
    last_webhook: {
        event: string;
        status: string;
        attempts: number;
        last_error: string | null;
        delivered_at: string | null;
        signature: string;
    } | null;
}

interface WebhookTestResult {
    status: string;
    event: string;
    delivery_id: number | null;
    consumer_name: string | null;
    attempts: number;
    last_error: string | null;
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

const webhookResults = ref<Record<number, WebhookTestResult>>({});
const testingWebhookId = ref<number | null>(null);

const sendTestWebhook = async (connection: Connection) => {
    testingWebhookId.value = connection.id;

    webhookResults.value = {
        ...webhookResults.value,
        [connection.id]: {
            status: 'pending',
            event: 'payment.test',
            delivery_id: null,
            consumer_name: null,
            attempts: 0,
            last_error: null,
        },
    };

    try {
        const response = await fetch(`/settings/payment-connections/${connection.id}/webhook-tests`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
        });

        const data = (await response.json()) as { data?: WebhookTestResult };

        webhookResults.value = {
            ...webhookResults.value,
            [connection.id]: data.data ?? {
                status: response.ok ? 'pending' : 'failed',
                event: 'payment.test',
                delivery_id: null,
                consumer_name: null,
                attempts: 0,
                last_error: null,
            },
        };
    } catch {
        webhookResults.value = {
            ...webhookResults.value,
            [connection.id]: {
                status: 'failed',
                event: 'payment.test',
                delivery_id: null,
                consumer_name: null,
                attempts: 0,
                last_error: 'The test webhook could not be dispatched.',
            },
        };
    } finally {
        testingWebhookId.value = null;
    }
};

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

                    <p
                        v-if="connection.last_webhook"
                        class="text-muted-foreground mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs"
                    >
                        Last webhook
                        <span class="text-foreground font-mono">{{ connection.last_webhook.event }}</span>
                        <Badge
                            v-if="connection.last_webhook.status === 'delivered'"
                            variant="outline"
                            class="border-green-300 text-green-700 dark:text-green-300"
                        >
                            verified
                        </Badge>
                        <Badge
                            v-else-if="connection.last_webhook.status === 'failed'"
                            variant="outline"
                            class="border-red-300 text-red-700 dark:text-red-300"
                        >
                            {{ connection.last_webhook.status }}
                        </Badge>
                        <span>{{ connection.last_webhook.delivered_at }}</span>
                    </p>
                    <p v-else class="text-muted-foreground mt-2 text-xs">
                        No webhook delivered yet for this property.
                    </p>

                    <div v-if="webhookResults[connection.id]" class="mt-3 flex flex-wrap items-center gap-2">
                        <Badge
                            :variant="webhookResults[connection.id].status === 'delivered' ? 'default' : 'outline'"
                            :class="
                                webhookResults[connection.id].status === 'delivered'
                                    ? 'border-green-300 text-green-700 dark:text-green-300'
                                    : 'border-red-300 text-red-700 dark:text-red-300'
                            "
                        >
                            {{ webhookResults[connection.id].status }}
                        </Badge>
                        <span v-if="webhookResults[connection.id].delivery_id" class="text-muted-foreground text-xs">
                            delivery #{{ webhookResults[connection.id].delivery_id }}
                        </span>
                        <span v-if="webhookResults[connection.id].last_error" class="text-destructive text-xs">
                            {{ webhookResults[connection.id].last_error }}
                        </span>
                    </div>
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
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="testingWebhookId === connection.id"
                        @click="sendTestWebhook(connection)"
                    >
                        {{ testingWebhookId === connection.id ? 'Sending…' : 'Send test webhook' }}
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
