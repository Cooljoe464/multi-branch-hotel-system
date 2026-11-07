<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

interface HeldDocument {
    id: number;
    folio_id: number | null;
    last_error: string | null;
    created_at: string;
    folio: { id: number; folio_number: string } | null;
}

const props = defineProps<{
    branch: { id: number; name: string };
    heldDocuments: HeldDocument[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Held Fiscal Invoices', href: '#' },
        ],
    },
});

function release(documentId: number): void {
    router.post(
        `/branches/${props.branch.id}/fiscal-documents/${documentId}/release`,
    );
}
</script>

<template>
    <Head title="Held Fiscal Invoices" />
    <div class="space-y-6 p-6">
        <header>
            <h1 class="text-2xl font-semibold">Held fiscal invoices</h1>
            <p class="text-muted-foreground mt-1 text-sm">
                {{ branch.name }} · Complete the corporate buyer’s billing
                identity, then release the invoice for submission.
            </p>
        </header>

        <div v-if="heldDocuments.length === 0" class="rounded-lg border p-6">
            No fiscal invoices are waiting for buyer information.
        </div>

        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="text-muted-foreground border-b">
                    <tr>
                        <th class="p-3">Folio</th>
                        <th class="p-3">Reason held</th>
                        <th class="p-3">Created</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="document in heldDocuments"
                        :key="document.id"
                        class="border-b last:border-0"
                    >
                        <td class="p-3 font-mono">
                            {{
                                document.folio?.folio_number ??
                                `#${document.folio_id}`
                            }}
                        </td>
                        <td class="p-3">{{ document.last_error }}</td>
                        <td class="p-3">{{ document.created_at }}</td>
                        <td class="p-3 text-right">
                            <Button size="sm" @click="release(document.id)">
                                Recheck and release
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
