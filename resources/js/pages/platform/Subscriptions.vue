<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

interface TenantRow {
    id: number;
    name: string;
    billing_email: string | null;
    subscription: {
        id: number;
        status: string;
        plan_key: string;
        plan_name: string;
    } | null;
}

interface OpenInvoice {
    id: number;
    tenant_id: number;
    number: string;
    currency: string;
    total_minor: number;
    due_at: string;
}

defineProps<{
    tenants: TenantRow[];
    plans: { id: number; key: string; name: string }[];
    openInvoices: OpenInvoice[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Subscriptions', href: '#' },
        ],
    },
});

function assignPlan(tenantId: number, event: Event): void {
    const planId = (event.target as HTMLSelectElement).value;

    if (planId !== '') {
        router.post(`/platform/tenants/${tenantId}/plan`, {
            plan_id: Number(planId),
        });
    }
}

function recordPayment(invoice: OpenInvoice): void {
    const reference = window.prompt(`Payment reference for ${invoice.number}`);

    if (reference) {
        router.post(`/platform/invoices/${invoice.id}/payments`, {
            payment_reference: reference,
            amount_minor: invoice.total_minor,
            currency: invoice.currency,
        });
    }
}

function formatMinor(amountMinor: number, currency: string): string {
    return `${currency} ${(amountMinor / 100).toLocaleString(undefined, {
        minimumFractionDigits: 2,
    })}`;
}
</script>

<template>
    <Head title="Subscriptions" />
    <div class="space-y-8 p-6">
        <header>
            <h1 class="text-2xl font-semibold">Subscriptions</h1>
            <p class="text-muted-foreground mt-1 text-sm">
                Plans, status and unpaid invoices for every tenant.
            </p>
        </header>

        <section>
            <h2 class="mb-3 text-lg font-medium">Tenants</h2>
            <table class="w-full text-left text-sm">
                <thead class="text-muted-foreground">
                    <tr>
                        <th class="py-2">Tenant</th>
                        <th>Status</th>
                        <th>Plan</th>
                        <th>Change plan</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="tenant in tenants"
                        :key="tenant.id"
                        class="border-t"
                    >
                        <td class="py-2">{{ tenant.name }}</td>
                        <td>{{ tenant.subscription?.status ?? 'not metered' }}</td>
                        <td>{{ tenant.subscription?.plan_name ?? '—' }}</td>
                        <td>
                            <select
                                class="rounded-md border px-2 py-1"
                                :aria-label="`Plan for ${tenant.name}`"
                                @change="assignPlan(tenant.id, $event)"
                            >
                                <option value="">Select…</option>
                                <option
                                    v-for="plan in plans"
                                    :key="plan.id"
                                    :value="plan.id"
                                >
                                    {{ plan.name }}
                                </option>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section>
            <h2 class="mb-3 text-lg font-medium">Unpaid invoices</h2>
            <p
                v-if="openInvoices.length === 0"
                class="text-muted-foreground text-sm"
            >
                Nothing outstanding.
            </p>
            <ul v-else class="divide-y">
                <li
                    v-for="invoice in openInvoices"
                    :key="invoice.id"
                    class="flex items-center justify-between py-2 text-sm"
                >
                    <span>
                        {{ invoice.number }} ·
                        {{ formatMinor(invoice.total_minor, invoice.currency) }}
                    </span>
                    <Button size="sm" @click="recordPayment(invoice)">
                        Record payment
                    </Button>
                </li>
            </ul>
        </section>
    </div>
</template>
