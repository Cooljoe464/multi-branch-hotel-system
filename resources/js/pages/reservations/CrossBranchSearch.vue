<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from '@lucide/vue';
import { formatCurrency as formatCurrencyRaw } from '@/lib/format';
import { formatDate } from '@/lib/dates';

interface RoomType {
    room_type_id: number;
    room_type_name: string;
    base_rate: number;
    available_count: number;
}

interface BranchResult {
    branch_id: number;
    branch_name: string;
    branch_city: string;
    room_types: RoomType[];
}

const props = defineProps<{
    results: BranchResult[];
    check_in: string;
    check_out: string;
    adults: number;
}>();

const page = usePage();
const currencySymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const formatCurrency = (amount: number) => formatCurrencyRaw(amount, currencySymbol.value, 0);

const goBack = () => {
    window.history.back();
};
</script>

<template>
    <Head title="Cross-Branch Availability" />

    <div class="min-h-screen bg-background">
        <header class="border-b border-border bg-card">
            <div class="max-w-7xl mx-auto px-4 py-6">
                <div class="flex items-center gap-3 mb-2">
                    <Button variant="ghost" size="sm" @click="goBack()" class="gap-1 text-muted-foreground hover:text-foreground">
                        <ArrowLeft class="size-4" /> Back
                    </Button>
                    <h1 class="text-2xl font-bold text-foreground">Cross-Branch Availability</h1>
                </div>
                <p class="text-muted-foreground">
                    {{ formatDate(check_in) }} - {{ formatDate(check_out) }} · {{ adults }} guest{{ adults > 1 ? 's' : '' }}
                </p>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 py-8">
            <div v-if="results.length === 0" class="text-center py-12">
                <p class="text-muted-foreground text-lg">No availability found across any properties for these dates.</p>
                <Link href="/reservations/create" class="mt-4 inline-block font-medium text-blue-600 hover:underline dark:text-blue-500">
                    Try different dates
                </Link>
            </div>

            <div v-else class="space-y-6">
                <div
                    v-for="branch in results"
                    :key="branch.branch_id"
                    class="rounded-lg border border-border bg-card overflow-hidden"
                >
                    <div class="px-6 py-4 bg-muted/50 border-b border-border">
                        <h2 class="text-lg font-semibold text-foreground">{{ branch.branch_name }}</h2>
                        <p class="text-sm text-muted-foreground">{{ branch.branch_city }}</p>
                    </div>
                    <div class="divide-y divide-border">
                        <div
                            v-for="roomType in branch.room_types"
                            :key="roomType.room_type_id"
                            class="px-6 py-4 flex items-center justify-between"
                        >
                            <div>
                                <p class="font-medium text-foreground">{{ roomType.room_type_name }}</p>
                                <p class="text-sm text-muted-foreground">{{ roomType.available_count }} rooms available</p>
                            </div>
                            <div class="flex items-center gap-4">
                                <div class="text-right">
                                    <p class="text-lg font-semibold text-foreground">{{ formatCurrency(roomType.base_rate) }}</p>
                                    <p class="text-xs text-muted-foreground">per night</p>
                                </div>
                                <Link
                                    :href="`/reservations/create?branch_id=${branch.branch_id}&room_type_id=${roomType.room_type_id}&check_in=${check_in}&check_out=${check_out}`"
                                >
                                    <Button>Book Now</Button>
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
