<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

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

const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};

const formatCurrency = (amount: number) => {
    return `$${(amount / 100).toFixed(0)}`;
};
</script>

<template>
    <Head title="Cross-Branch Availability" />

    <div class="min-h-screen bg-gray-50">
        <header class="bg-white shadow">
            <div class="max-w-7xl mx-auto px-4 py-6">
                <h1 class="text-2xl font-bold text-gray-900">Cross-Branch Availability</h1>
                <p class="text-gray-600">
                    {{ formatDate(check_in) }} - {{ formatDate(check_out) }} · {{ adults }} guest{{ adults > 1 ? 's' : '' }}
                </p>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 py-8">
            <div v-if="results.length === 0" class="text-center py-12">
                <p class="text-gray-500 text-lg">No availability found across any properties for these dates.</p>
                <Link href="/reservations/create" class="mt-4 inline-block text-gray-900 underline">
                    Try different dates
                </Link>
            </div>

            <div v-else class="space-y-6">
                <div
                    v-for="branch in results"
                    :key="branch.branch_id"
                    class="bg-white rounded-lg shadow overflow-hidden"
                >
                    <div class="px-6 py-4 bg-gray-50 border-b">
                        <h2 class="text-lg font-semibold text-gray-900">{{ branch.branch_name }}</h2>
                        <p class="text-sm text-gray-500">{{ branch.branch_city }}</p>
                    </div>
                    <div class="divide-y">
                        <div
                            v-for="roomType in branch.room_types"
                            :key="roomType.room_type_id"
                            class="px-6 py-4 flex items-center justify-between"
                        >
                            <div>
                                <p class="font-medium text-gray-900">{{ roomType.room_type_name }}</p>
                                <p class="text-sm text-gray-500">{{ roomType.available_count }} rooms available</p>
                            </div>
                            <div class="flex items-center gap-4">
                                <div class="text-right">
                                    <p class="text-lg font-semibold text-gray-900">{{ formatCurrency(roomType.base_rate) }}</p>
                                    <p class="text-xs text-gray-500">per night</p>
                                </div>
                                <Link
                                    :href="`/reservations/create?branch_id=${branch.branch_id}&room_type_id=${roomType.room_type_id}&check_in=${check_in}&check_out=${check_out}`"
                                    class="px-4 py-2 bg-gray-900 text-white text-sm rounded-md hover:bg-gray-800"
                                >
                                    Book Now
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
