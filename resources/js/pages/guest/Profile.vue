<script setup lang="ts">
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft } from '@lucide/vue';
import { formatDate } from '@/lib/dates';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import { t } from '@/lib/locale';

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

import { formatCurrency as formatCurrencyRaw, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatCurrency = (amount: number, currencyCode?: string) => formatCurrencyRaw(amount, resolveSymbol(currencyCode));

const getVipBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        none: '',
        silver: 'bg-muted text-muted-foreground',
        gold: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        platinum: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        diamond: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
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

const goBack = () => {
    window.history.back();
};
</script>

<template>
    <Head :title="`${guest.full_name ?? guest.first_name + ' ' + guest.last_name} - ${t('portal.profile_title')}`" />

    <div class="min-h-screen bg-background">
        <header class="bg-card shadow">
            <div class="max-w-4xl mx-auto px-4 py-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <Button variant="ghost" size="sm" @click="goBack()" class="gap-1 text-muted-foreground hover:text-foreground">
                            <ArrowLeft class="size-4" /> {{ t('common.back') }}
                        </Button>
                        <div>
                            <h1 class="text-2xl font-bold text-foreground">{{ guest.first_name }} {{ guest.last_name }}</h1>
                            <p class="text-muted-foreground">{{ guest.email }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <LocaleSwitcher />
                        <Badge
                            v-if="guest.vip_status !== 'none'"
                            class="text-sm px-4 py-2"
                            :class="getVipBadgeClass(guest.vip_status)"
                        >
                            {{ guest.vip_status.toUpperCase() }} {{ t('portal.vip_suffix') }}
                        </Badge>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-4xl mx-auto px-4 py-8">
            <!-- Stats -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-card rounded-lg shadow p-6 text-center">
                    <p class="text-3xl font-bold text-foreground">{{ guest.total_stays }}</p>
                    <p class="text-muted-foreground">{{ t('portal.total_stays') }}</p>
                </div>
                <div class="bg-card rounded-lg shadow p-6 text-center">
                    <p class="text-3xl font-bold text-foreground">{{ guest.total_nights }}</p>
                    <p class="text-muted-foreground">{{ t('portal.total_nights_stat') }}</p>
                </div>
                <div class="bg-card rounded-lg shadow p-6 text-center">
                    <p class="text-3xl font-bold text-foreground">{{ formatCurrency(guest.total_spent) }}</p>
                    <p class="text-muted-foreground">{{ t('portal.total_spent') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Preferences -->
                <div class="bg-card rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">{{ t('portal.preferences') }}</h2>
                    <div v-if="Object.keys(groupedPreferences).length === 0" class="text-muted-foreground">
                        {{ t('portal.no_preferences') }}
                    </div>
                    <div v-else class="space-y-4">
                        <div v-for="(prefs, category) in groupedPreferences" :key="category">
                            <h3 class="text-sm font-medium text-muted-foreground uppercase mb-2">{{ category }}</h3>
                            <div class="space-y-1">
                                <div v-for="pref in prefs" :key="pref.id" class="flex justify-between text-sm">
                                    <span class="text-muted-foreground">{{ pref.key.replace('_', ' ') }}</span>
                                    <span class="font-medium">{{ pref.value }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stay History -->
                <div class="bg-card rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4">{{ t('portal.stay_history') }}</h2>
                    <div v-if="reservations.length === 0" class="text-muted-foreground">
                        {{ t('portal.no_stays') }}
                    </div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="res in reservations.slice(0, 10)"
                            :key="res.id"
                            class="flex justify-between items-center py-2 border-b last:border-0"
                        >
                            <div>
                                <p class="font-medium">{{ res.branch.name }}</p>
                                <p class="text-sm text-muted-foreground">
                                    {{ formatDate(res.check_in_date) }} - {{ formatDate(res.check_out_date) }}
                                </p>
                            </div>
                            <div class="text-right">
                                <Badge
                                    :class="{
                                        'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200': res.status === 'checked_in',
                                        'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200': res.status === 'confirmed',
                                        'bg-muted text-muted-foreground': res.status === 'checked_out',
                                    }"
                                >
                                    {{ res.status.replace('_', ' ') }}
                                </Badge>
                                <p class="text-sm text-muted-foreground mt-1">{{ formatCurrency(res.total_amount) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
