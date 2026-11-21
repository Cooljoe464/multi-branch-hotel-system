<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ShieldCheck, CheckCircle2, XCircle, Loader2 } from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Tax & Compliance', href: '/settings/tax-compliance' },
        ],
    },
});

const props = defineProps<{
    preferred_provider: string;
    afri_invoice: {
        has_api_key: boolean;
        service_id: string;
        environment: string;
        is_ready: boolean;
    };
    firs: {
        has_api_key: boolean;
        is_ready: boolean;
    };
}>();

const preferredProvider = ref(props.preferred_provider);
const afriApiKey = ref('');
const afriServiceId = ref(props.afri_invoice.service_id);
const afriEnv = ref(props.afri_invoice.environment);

const saving = ref(false);
const testing = ref(false);
const testResult = ref<{ ok: boolean; message: string; merchant_name?: string } | null>(null);

const save = () => {
    saving.value = true;
    router.put(
        '/settings/tax-compliance',
        {
            preferred_provider: preferredProvider.value,
            afri_invoice_api_key: afriApiKey.value,
            afri_invoice_service_id: afriServiceId.value,
            afri_invoice_environment: afriEnv.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
            },
        }
    );
};

const runTest = async () => {
    testing.value = true;
    testResult.value = null;

    try {
        const response = await fetch('/settings/tax-compliance/test', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: JSON.stringify({
                api_key: afriApiKey.value || undefined,
                service_id: afriServiceId.value || undefined,
                environment: afriEnv.value || undefined,
            }),
        });

        const data = await response.json();
        testResult.value = data;
    } catch (e: any) {
        testResult.value = {
            ok: false,
            message: e.message || 'Network error executing diagnostic test.',
        };
    } finally {
        testing.value = false;
    }
};
</script>

<template>
    <div class="max-w-4xl space-y-6">
        <Head title="Tax & NRS E-Invoicing Compliance" />

        <div class="flex items-center justify-between border-b pb-5">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Tax & NRS E-Invoicing</h1>
                <p class="text-sm text-muted-foreground mt-1">
                    Manage accredited NRS System Integrator (Afri Invoice) credentials for B2B pre-clearance and B2C fiscal reporting.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Badge :variant="afri_invoice.is_ready ? 'default' : 'destructive'" class="flex items-center gap-1.5">
                    <ShieldCheck class="w-3.5 h-3.5" />
                    {{ afri_invoice.is_ready ? 'NRS Ready' : 'Configuration Required' }}
                </Badge>
            </div>
        </div>

        <div class="bg-card border rounded-lg p-6 space-y-6">
            <div class="space-y-4">
                <h2 class="text-lg font-semibold">Primary E-Invoicing Provider</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div
                        class="border rounded-lg p-4 cursor-pointer transition-colors"
                        :class="preferredProvider === 'afri_invoice' ? 'border-primary bg-primary/5' : 'hover:border-border'"
                        @click="preferredProvider = 'afri_invoice'"
                    >
                        <div class="flex items-center justify-between">
                            <span class="font-medium">Afri Invoice (Accredited SI + APP)</span>
                            <span v-if="preferredProvider === 'afri_invoice'" class="text-xs bg-primary text-primary-foreground px-2 py-0.5 rounded">Active</span>
                        </div>
                        <p class="text-xs text-muted-foreground mt-2">
                            Official NRS accredited access point provider. Supports real-time B2B pre-clearance and automated B2C reporting.
                        </p>
                    </div>

                    <div
                        class="border rounded-lg p-4 cursor-pointer transition-colors"
                        :class="preferredProvider === 'firs' ? 'border-primary bg-primary/5' : 'hover:border-border'"
                        @click="preferredProvider = 'firs'"
                    >
                        <div class="flex items-center justify-between">
                            <span class="font-medium">Direct FIRS API</span>
                            <span v-if="preferredProvider === 'firs'" class="text-xs bg-primary text-primary-foreground px-2 py-0.5 rounded">Active</span>
                        </div>
                        <p class="text-xs text-muted-foreground mt-2">
                            Direct legacy FIRS portal transmission endpoint.
                        </p>
                    </div>
                </div>
            </div>

            <div class="border-t pt-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="font-medium">Afri Invoice Tenant Credentials</h3>
                        <p class="text-xs text-muted-foreground">Self-service credentials issued from your Afri Invoice hotel dashboard.</p>
                    </div>
                    <Badge variant="outline">{{ afri_invoice.has_api_key ? 'Encrypted Secret Stored' : 'Not Configured' }}</Badge>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <Label for="afriServiceId">NRS Service ID</Label>
                        <Input
                            id="afriServiceId"
                            v-model="afriServiceId"
                            placeholder="e.g. SRV-NG-00912"
                        />
                        <p class="text-xs text-muted-foreground">Provided by Afri Invoice / NRS portal for this property.</p>
                    </div>

                    <div class="space-y-2">
                        <Label for="afriEnv">Environment</Label>
                        <select
                            id="afriEnv"
                            v-model="afriEnv"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        >
                            <option value="sandbox">Sandbox (Testing / Staging)</option>
                            <option value="live">Live (Production NRS Clearance)</option>
                        </select>
                        <p class="text-xs text-muted-foreground">Always verify sandbox transactions before switching to Live.</p>
                    </div>
                </div>

                <div class="space-y-2">
                    <Label for="afriApiKey">Afri Invoice API Secret Key</Label>
                    <Input
                        id="afriApiKey"
                        v-model="afriApiKey"
                        type="password"
                        placeholder="Paste new secret key to update..."
                    />
                    <p class="text-xs text-muted-foreground">Key is sealed with AES-256 GCM in branch secrets and never displayed back in plaintext.</p>
                </div>
            </div>

            <!-- Diagnostics -->
            <div class="border-t pt-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="font-medium">Gateway Diagnostics</h3>
                        <p class="text-xs text-muted-foreground">Verify live connectivity, certificate handshake, and merchant identification.</p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="testing"
                        @click="runTest"
                    >
                        <Loader2 v-if="testing" class="w-4 h-4 mr-2 animate-spin" />
                        Test Connection
                    </Button>
                </div>

                <div v-if="testResult" class="p-4 rounded-lg text-sm border" :class="testResult.ok ? 'bg-green-50 border-green-200 text-green-900 dark:bg-green-950 dark:border-green-800 dark:text-green-100' : 'bg-red-50 border-red-200 text-red-900 dark:bg-red-950 dark:border-red-800 dark:text-red-100'">
                    <div class="flex items-center gap-2 font-medium">
                        <CheckCircle2 v-if="testResult.ok" class="w-4 h-4 text-green-600 dark:text-green-400" />
                        <XCircle v-else class="w-4 h-4 text-red-600 dark:text-red-400" />
                        <span>{{ testResult.ok ? 'Connection Verified' : 'Connection Failed' }}</span>
                    </div>
                    <p class="mt-1 text-xs">{{ testResult.message }}</p>
                    <div v-if="testResult.merchant_name" class="mt-2 text-xs font-semibold">
                        Registered Merchant: {{ testResult.merchant_name }}
                    </div>
                </div>
            </div>

            <div class="border-t pt-6 flex justify-end gap-3">
                <Button :disabled="saving" @click="save">
                    <Loader2 v-if="saving" class="w-4 h-4 mr-2 animate-spin" />
                    Save Settings
                </Button>
            </div>
        </div>
    </div>
</template>
