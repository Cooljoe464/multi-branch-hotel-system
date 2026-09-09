<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface Guest {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    phone: string | null;
    vip_status: string;
    total_stays: number;
    total_nights: number;
    total_spent: number;
    last_stayed_at: string | null;
}

interface GuestPreference {
    id: number;
    category: string;
    key: string;
    value: string;
    notes: string | null;
}

interface Reservation {
    id: number;
    confirmation_number: string;
    status: string;
    check_in_date: string;
    check_out_date: string;
    total_amount: number;
    branch: { name: string; city: string };
    roomType: { name: string };
}

const props = defineProps<{
    guest: Guest;
    preferences: GuestPreference[];
    reservations: Reservation[];
}>();

const formatCurrency = (amount: number) => {
    return `$${(amount / 100).toFixed(2)}`;
};

const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const getVipBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        none: '',
        silver: 'bg-gray-100 text-gray-800',
        gold: 'bg-yellow-100 text-yellow-800',
        platinum: 'bg-purple-100 text-purple-800',
        diamond: 'bg-blue-100 text-blue-800',
    };
    return classes[status] || '';
};

const groupedPreferences = props.preferences.reduce((acc, pref) => {
    if (! acc[pref.category]) {
        acc[pref.category] = [];
    }
    acc[pref.category].push(pref);
    return acc;
}, {} as Record<string, GuestPreference[]>);
</script>

<template>
    <Head :title="`${guest.full_name ?? guest.first_name + ' ' + guest.last_name} - Guest Profile`" />

    <div class="min-h-screen bg-gray-50">
        <header class="bg-white shadow">
            <div class="max-w-4xl mx-auto px-4 py-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">{{ guest.first_name }} {{ guest.last_name }}</h1>
                        <p class="text-gray-600">{{ guest.email }}</p>
                    </div>
                    <span
                        v-if="guest.vip_status !== 'none'"
                        class="px-4 py-2 text-sm font-medium rounded-full"
                        :class="getVipBadgeClass(guest.vip_status)"
                    >
                        {{ guest.vip_status.toUpperCase() }} VIP
                    </span>
                </div>
            </div>
        </header>

        <main class="max-w-4xl mx-auto px-4 py-8">
            <!-- Stats -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-6 text-center">
                    <p class="text-3xl font-bold text-gray-900">{{ guest.total_stays }}</p>
                    <p class="text-gray-500">Total Stays</p>
                </div>
                <div class="bg-white rounded-lg shadow p-6 text-center">
                    <p class="text-3xl font-bold text-gray-900">{{ guest.total_nights }}</p>
                    <p class="text-gray-500">Total Nights</p>
                </div>
                <div class="bg-white rounded-lg shadow p-6 text-center">
                    <p class="text-3xl font-bold text-gray-900">{{ formatCurrency(guest.total_spent) }}</p>
                    <p class="text-gray-500">Total Spent</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Preferences -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Preferences</h2>
                    <div v-if="Object.keys(groupedPreferences).length === 0" class="text-gray-500">
                        No preferences recorded yet.
                    </div>
                    <div v-else class="space-y-4">
                        <div v-for="(prefs, category) in groupedPreferences" :key="category">
                            <h3 class="text-sm font-medium text-gray-500 uppercase mb-2">{{ category }}</h3>
                            <div class="space-y-1">
                                <div v-for="pref in prefs" :key="pref.id" class="flex justify-between text-sm">
                                    <span class="text-gray-600">{{ pref.key.replace('_', ' ') }}</span>
                                    <span class="font-medium">{{ pref.value }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stay History -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">Stay History</h2>
                    <div v-if="reservations.length === 0" class="text-gray-500">
                        No stays recorded yet.
                    </div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="res in reservations.slice(0, 10)"
                            :key="res.id"
                            class="flex justify-between items-center py-2 border-b last:border-0"
                        >
                            <div>
                                <p class="font-medium">{{ res.branch.name }}</p>
                                <p class="text-sm text-gray-500">
                                    {{ formatDate(res.check_in_date) }} - {{ formatDate(res.check_out_date) }}
                                </p>
                            </div>
                            <div class="text-right">
                                <span
                                    class="px-2 py-1 text-xs rounded-full"
                                    :class="{
                                        'bg-green-100 text-green-800': res.status === 'checked_in',
                                        'bg-blue-100 text-blue-800': res.status === 'confirmed',
                                        'bg-gray-100 text-gray-800': res.status === 'checked_out',
                                    }"
                                >
                                    {{ res.status.replace('_', ' ') }}
                                </span>
                                <p class="text-sm text-gray-500 mt-1">{{ formatCurrency(res.total_amount) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
