<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from '@lucide/vue';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import { t } from '@/lib/locale';

interface Reservation {
    id: number;
    confirmation_number: string;
    guest_name: string;
    branch_name: string;
}

interface Outlet {
    id: number;
    name: string;
    code: string;
    type: string;
}

const props = defineProps<{
    reservation: Reservation;
    outlets: Outlet[];
}>();

const typeIcon: Record<string, string> = {
    restaurant: '\uD83C\uDF7D\uFE0F',
    bar: '\uD83C\uDF78',
    spa: '\uD83D\uDC86',
    gift_shop: '\uD83C\uDF81',
    laundry: '\uD83D\uDC54',
};
const typeDescription = computed<Record<string, string>>(() => ({
    restaurant: t('portal.outlet_restaurant'),
    bar: t('portal.outlet_bar'),
    spa: t('portal.outlet_spa'),
    gift_shop: t('portal.outlet_gift_shop'),
    laundry: t('portal.outlet_laundry'),
}));

const selectOutlet = (outlet: Outlet) => {
    router.get(
        `/guest/order/${props.reservation.confirmation_number}/${outlet.code}`,
    );
};

const goBack = () => {
    window.history.back();
};
</script>

<template>
    <Head :title="`${t('portal.order_title')} - ${reservation.branch_name}`" />

    <div class="bg-background min-h-screen">
        <div class="bg-card border-border border-b px-4 py-4 sm:px-6">
            <div class="mx-auto flex max-w-2xl items-center gap-3">
                <Button
                    variant="ghost"
                    size="sm"
                    @click="goBack()"
                    class="text-muted-foreground hover:text-foreground gap-1"
                >
                    <ArrowLeft class="size-4" /> {{ t('common.back') }}
                </Button>
                <div class="flex-1">
                    <h1 class="text-foreground text-xl font-bold">
                        {{ reservation.branch_name }}
                    </h1>
                    <p class="text-muted-foreground text-sm">
                        {{
                            t('portal.welcome', {
                                name: reservation.guest_name,
                            })
                        }}
                    </p>
                </div>
                <LocaleSwitcher />
            </div>
        </div>

        <div class="mx-auto max-w-2xl px-4 py-6 sm:px-6">
            <h2 class="text-foreground mb-4 text-lg font-semibold">
                {{ t('portal.choose_outlet') }}
            </h2>

            <div
                v-if="outlets.length === 0"
                class="border-border bg-card rounded-lg border p-8 text-center"
            >
                <p class="text-muted-foreground">
                    {{ t('portal.no_outlets') }}
                </p>
            </div>

            <div v-else class="space-y-3">
                <button
                    v-for="outlet in outlets"
                    :key="outlet.id"
                    @click="selectOutlet(outlet)"
                    class="border-border bg-card hover:bg-muted/50 w-full rounded-lg border p-4 text-left transition-colors"
                >
                    <div class="flex items-center gap-4">
                        <div class="text-3xl">
                            {{ typeIcon[outlet.type] || '\uD83D\uDCE6' }}
                        </div>
                        <div class="flex-1">
                            <div class="text-foreground font-semibold">
                                {{ outlet.name }}
                            </div>
                            <div class="text-muted-foreground text-sm">
                                {{
                                    typeDescription[outlet.type] ||
                                    outlet.type.replace('_', ' ')
                                }}
                            </div>
                        </div>
                        <div class="text-muted-foreground">&rsaquo;</div>
                    </div>
                </button>
            </div>

            <div class="mt-6 text-center">
                <Link
                    :href="`/guest/folio/${reservation.confirmation_number}`"
                    class="text-muted-foreground hover:text-foreground text-sm"
                >
                    {{ t('common.view_folio') }}
                </Link>
            </div>
        </div>
    </div>
</template>
