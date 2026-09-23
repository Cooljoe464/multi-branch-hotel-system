<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { formatCurrency as formatCurrencyRaw } from '@/lib/format';
import DatePicker from '@/components/ui/date-picker/DatePicker.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Card,
    CardHeader,
    CardTitle,
    CardDescription,
    CardContent,
    CardFooter,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import { t } from '@/lib/locale';
import { newIdempotencyKey } from '@/lib/idempotency';
import {
    ArrowLeft,
    CheckCircle2,
    BedDouble,
    Users,
    Loader2,
} from '@lucide/vue';

interface Branch {
    id: number;
    name: string;
    city: string;
    country: string;
    currency_symbol: string;
    room_types: RoomType[];
}

interface RoomType {
    room_type_id: number;
    id: number;
    name: string;
    code: string;
    description: string;
    base_rate: number;
    max_occupancy: number;
    bed_count: number;
    bed_type: string;
    amenities: string[];
    available_count?: number;
    total_rate?: number;
}

const props = defineProps<{
    branches: Branch[];
}>();

const step = ref(1);
const selectedBranch = ref<number | null>(null);
const checkIn = ref('');
const checkOut = ref('');
const adults = ref(2);
const children = ref(0);
const searchResults = ref<{ room_types: RoomType[]; nights: number } | null>(
    null,
);
const selectedRoomType = ref<RoomType | null>(null);
const termsAgreed = ref(false);
const guestInfo = ref({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    special_requests: [] as string[],
});
const isSubmitting = ref(false);
const bookingComplete = ref(false);
const confirmationNumber = ref('');

const today = new Date().toISOString().split('T')[0];

const selectedBranchObj = computed(
    () => props.branches.find((b) => b.id === selectedBranch.value) ?? null,
);

const canProceedStep1 = computed(() => {
    return (
        selectedBranch.value &&
        checkIn.value &&
        checkOut.value &&
        adults.value > 0
    );
});

const canProceedStep3 = computed(() => {
    return (
        guestInfo.value.first_name &&
        guestInfo.value.last_name &&
        guestInfo.value.email
    );
});

const searchAvailability = async () => {
    if (!selectedBranchObj.value) return;

    const response = await fetch('/book/search', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN':
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content') || '',
            'X-Idempotency-Key': newIdempotencyKey(),
        },
        body: JSON.stringify({
            branch_id: selectedBranchObj.value.id,
            check_in: checkIn.value,
            check_out: checkOut.value,
            adults: adults.value,
            children: children.value,
        }),
    });

    searchResults.value = await response.json();
    step.value = 2;
};

const selectRoomType = (roomType: RoomType) => {
    selectedRoomType.value = roomType;
    step.value = 3;
};

const submitBooking = async () => {
    if (!selectedBranchObj.value || !selectedRoomType.value) return;

    isSubmitting.value = true;

    try {
        const response = await fetch('/book', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content') || '',
                'X-Idempotency-Key': newIdempotencyKey(),
            },
            body: JSON.stringify({
                branch_id: selectedBranchObj.value.id,
                room_type_id:
                    selectedRoomType.value.room_type_id ??
                    selectedRoomType.value.id,
                check_in: checkIn.value,
                check_out: checkOut.value,
                adults: adults.value,
                children: children.value,
                ...guestInfo.value,
            }),
        });

        const data = await response.json();

        if (response.ok) {
            confirmationNumber.value = data.reservation.confirmation_number;
            bookingComplete.value = true;
        }
    } catch (error) {
        console.error('Booking submission failed:', error);
    } finally {
        isSubmitting.value = false;
    }
};

const goBack = () => {
    if (step.value > 1) {
        step.value--;
    }
};

const page = usePage();
const branch = computed(() => page.props.branch?.current);
const currencySymbol = computed(() => branch.value?.currency_symbol || '₦');

const formatCurrency = (
    amount: number,
    symbol: string = currencySymbol.value,
) => formatCurrencyRaw(amount, symbol);

const getAmenityIcon = (amenity: string) => {
    const icons: Record<string, string> = {
        wifi: '📶',
        tv: '📺',
        minibar: '🍸',
        air_conditioning: '❄️',
        safe: '🔒',
        balcony: '🌅',
    };
    return icons[amenity] || '✓';
};
</script>

<template>
    <Head :title="t('booking.title')" />

    <div class="bg-muted/30 min-h-screen">
        <!-- Header -->
        <header class="bg-background border-border border-b shadow-xs">
            <div
                class="mx-auto flex max-w-6xl items-center justify-between px-4 py-6"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="bg-primary text-primary-foreground flex h-10 w-10 items-center justify-center rounded-lg"
                    >
                        <AppLogoIcon class="size-6 fill-current" />
                    </div>
                    <div>
                        <h1
                            class="text-foreground text-2xl font-bold tracking-tight"
                        >
                            {{ t('booking.heading') }}
                        </h1>
                        <p class="text-muted-foreground text-xs">
                            {{ t('booking.tagline') }}
                        </p>
                    </div>
                </div>
                <LocaleSwitcher />
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-8">
            <!-- Progress Steps -->
            <div class="mx-auto mb-10 max-w-xl">
                <div class="flex items-center justify-between">
                    <div v-for="s in 4" :key="s" class="flex items-center">
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-full text-sm font-semibold transition-all duration-200"
                            :class="
                                step >= s
                                    ? 'bg-primary text-primary-foreground shadow-md'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            <CheckCircle2 v-if="step > s" class="size-4" />
                            <span v-else>{{ s }}</span>
                        </div>
                        <div
                            v-if="s < 4"
                            class="mx-2 h-0.5 w-16 rounded-full transition-colors duration-300 sm:w-24"
                            :class="step > s ? 'bg-primary' : 'bg-border'"
                        ></div>
                    </div>
                </div>
                <div
                    class="text-muted-foreground mt-3 flex justify-between px-1 text-xs font-medium"
                >
                    <span
                        :class="
                            step >= 1 ? 'text-foreground font-semibold' : ''
                        "
                        >{{ t('booking.steps.dates') }}</span
                    >
                    <span
                        :class="
                            step >= 2 ? 'text-foreground font-semibold' : ''
                        "
                        >{{ t('booking.steps.room') }}</span
                    >
                    <span
                        :class="
                            step >= 3 ? 'text-foreground font-semibold' : ''
                        "
                        >{{ t('booking.steps.guest') }}</span
                    >
                    <span
                        :class="
                            step >= 4 ? 'text-foreground font-semibold' : ''
                        "
                        >{{ t('booking.steps.confirm') }}</span
                    >
                </div>
            </div>

            <!-- Booking Complete -->
            <Card
                v-if="bookingComplete"
                class="border-border mx-auto max-w-lg py-8 text-center shadow-md"
            >
                <CardContent class="pt-6">
                    <div
                        class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400"
                    >
                        <CheckCircle2 class="h-10 w-10" />
                    </div>
                    <h2 class="text-foreground mb-2 text-2xl font-bold">
                        {{ t('booking.confirmed_title') }}
                    </h2>
                    <p class="text-muted-foreground mb-4 text-sm">
                        {{ t('booking.confirmed_body') }}
                    </p>
                    <div
                        class="bg-muted border-border text-foreground mb-6 inline-block rounded-lg border px-4 py-2 font-mono text-2xl font-bold tracking-widest"
                    >
                        {{ confirmationNumber }}
                    </div>
                    <p class="text-muted-foreground mb-8 text-sm">
                        {{ t('booking.confirmed_saved') }}
                        <span class="text-foreground font-medium">{{
                            guestInfo.email
                        }}</span>
                    </p>
                    <Button as-child class="w-full">
                        <Link :href="`/guest/folio/${confirmationNumber}`">
                            {{ t('common.view_your_folio') }}
                        </Link>
                    </Button>
                </CardContent>
            </Card>

            <!-- Step 1: Select Branch & Dates -->
            <div v-else-if="step === 1" class="mx-auto max-w-2xl">
                <Card class="border-border shadow-sm">
                    <CardHeader>
                        <CardTitle class="text-xl"
                            >{{ t('booking.select_title') }}</CardTitle
                        >
                        <CardDescription
                            >{{ t('booking.select_description') }}</CardDescription
                        >
                    </CardHeader>

                    <CardContent class="space-y-5">
                        <div class="space-y-2">
                            <Label>{{ t('booking.property') }}</Label>
                            <Select
                                v-model="selectedBranch"
                                :aria-label="t('booking.property_aria')"
                            >
                                <SelectTrigger class="w-full">
                                    <SelectValue
                                        :placeholder="t('booking.property_placeholder')"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="branch in branches"
                                        :key="branch.id"
                                        :value="branch.id"
                                    >
                                        {{ branch.name }} ({{ branch.city }},
                                        {{ branch.country }})
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="check_in">{{ t('booking.check_in') }}</Label>
                                <DatePicker
                                    id="check_in"
                                    v-model="checkIn"
                                    :min-date="today"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="check_out">{{ t('booking.check_out') }}</Label>
                                <DatePicker
                                    id="check_out"
                                    v-model="checkOut"
                                    :min-date="checkIn || today"
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <Label>{{ t('booking.adults') }}</Label>
                                <Select
                                    v-model="adults"
                                    :aria-label="t('booking.adults_aria')"
                                >
                                    <SelectTrigger class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="n in 10"
                                            :key="n"
                                            :value="n"
                                            >{{ t('common.adult_count', { n }) }}</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="space-y-2">
                                <Label>{{ t('booking.children') }}</Label>
                                <Select
                                    v-model="children"
                                    :aria-label="t('booking.children_aria')"
                                >
                                    <SelectTrigger class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="n in 6"
                                            :key="n"
                                            :value="n - 1"
                                            >{{ t('common.children_count', { n: n - 1 }) }}</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardContent>

                    <CardFooter class="flex justify-end pt-2">
                        <Button
                            :disabled="!canProceedStep1"
                            class="w-full sm:w-auto"
                            @click="searchAvailability"
                        >
                            {{ t('booking.search') }}
                        </Button>
                    </CardFooter>
                </Card>
            </div>

            <!-- Step 2: Select Room Type -->
            <div v-else-if="step === 2" class="mx-auto max-w-4xl space-y-6">
                <div class="flex items-center justify-between">
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="goBack"
                        class="text-muted-foreground hover:text-foreground gap-1"
                    >
                        <ArrowLeft class="size-4" /> {{ t('common.back') }}
                    </Button>
                    <h2 class="text-foreground text-xl font-semibold">
                        {{ t('booking.rooms_title', { nights: searchResults?.nights ?? 0 }) }}
                    </h2>
                </div>

                <div class="grid gap-4">
                    <Card
                        v-for="roomType in searchResults?.room_types"
                        :key="roomType.id"
                        class="border-border cursor-pointer overflow-hidden shadow-xs transition-all hover:shadow-md"
                        @click="selectRoomType(roomType)"
                    >
                        <CardContent class="p-6">
                            <div
                                class="flex flex-col items-start justify-between gap-6 md:flex-row md:items-center"
                            >
                                <div class="flex-1 space-y-3">
                                    <div class="flex items-center gap-3">
                                        <h3
                                            class="text-foreground text-lg font-bold"
                                        >
                                            {{ roomType.name }}
                                        </h3>
                                        <Badge
                                            variant="secondary"
                                            class="border-emerald-200 bg-emerald-50 text-emerald-600 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-400"
                                        >
                                            {{ roomType.available_count }}
                                            {{ t('common.available') }}
                                        </Badge>
                                    </div>
                                    <p
                                        class="text-muted-foreground text-sm leading-relaxed"
                                    >
                                        {{ roomType.description }}
                                    </p>

                                    <div
                                        class="text-muted-foreground flex items-center gap-4 pt-1 text-xs"
                                    >
                                        <span
                                            class="inline-flex items-center gap-1"
                                        >
                                            <BedDouble class="size-3.5" />
                                            {{ t('common.beds', { count: roomType.bed_count, type: roomType.bed_type }) }}
                                        </span>
                                        <span
                                            class="inline-flex items-center gap-1"
                                        >
                                            <Users class="size-3.5" />
                                            {{ t('common.max_guests', { count: roomType.max_occupancy }) }}
                                        </span>
                                    </div>

                                    <div class="flex flex-wrap gap-1.5 pt-2">
                                        <Badge
                                            v-for="amenity in roomType.amenities"
                                            :key="amenity"
                                            variant="outline"
                                            class="text-xs font-normal"
                                        >
                                            {{ getAmenityIcon(amenity) }}
                                            {{ amenity.replace('_', ' ') }}
                                        </Badge>
                                    </div>
                                </div>

                                <div
                                    class="border-border flex flex-col items-start justify-between self-stretch border-t pt-4 md:items-end md:border-t-0 md:border-l md:pt-0 md:pl-6"
                                >
                                    <div class="text-left md:text-right">
                                        <div
                                            class="text-foreground text-2xl font-extrabold"
                                        >
                                            {{
                                                formatCurrency(
                                                    roomType.base_rate,
                                                )
                                            }}
                                        </div>
                                        <div
                                            class="text-muted-foreground text-xs"
                                        >
                                            {{ t('common.per_night') }}
                                        </div>
                                        <div
                                            class="text-foreground mt-2 text-sm font-semibold"
                                        >
                                            {{ t('booking.total_label') }}
                                            {{
                                                formatCurrency(
                                                    roomType.total_rate || 0,
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <Button
                                        class="mt-4 w-full md:w-auto"
                                        @click.stop="selectRoomType(roomType)"
                                    >
                                        {{ t('booking.select_room') }}
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- Step 3: Guest Information -->
            <div v-else-if="step === 3" class="mx-auto max-w-2xl space-y-6">
                <div class="flex items-center justify-between">
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="goBack"
                        class="text-muted-foreground hover:text-foreground gap-1"
                    >
                        <ArrowLeft class="size-4" /> {{ t('common.back') }}
                    </Button>
                    <h2 class="text-foreground text-xl font-semibold">
                        {{ t('booking.guest_title') }}
                    </h2>
                </div>

                <Card class="border-border shadow-sm">
                    <CardHeader>
                        <CardTitle class="text-lg"
                            >{{ t('booking.contact_title') }}</CardTitle
                        >
                        <CardDescription
                            >{{ t('booking.contact_description') }}</CardDescription
                        >
                    </CardHeader>

                    <CardContent class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="first_name">{{ t('booking.first_name') }}</Label>
                                <Input
                                    id="first_name"
                                    v-model="guestInfo.first_name"
                                    type="text"
                                    required
                                    placeholder="Jane"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="last_name">{{ t('booking.last_name') }}</Label>
                                <Input
                                    id="last_name"
                                    v-model="guestInfo.last_name"
                                    type="text"
                                    required
                                    placeholder="Doe"
                                />
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="email">{{ t('booking.email_label') }}</Label>
                            <Input
                                id="email"
                                v-model="guestInfo.email"
                                type="email"
                                required
                                placeholder="jane.doe@example.com"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label for="phone">{{ t('booking.phone_label') }}</Label>
                            <Input
                                id="phone"
                                v-model="guestInfo.phone"
                                type="tel"
                                placeholder="+234 800 000 0000"
                            />
                        </div>
                    </CardContent>

                    <CardFooter class="flex justify-end pt-2">
                        <Button
                            :disabled="!canProceedStep3"
                            class="w-full sm:w-auto"
                            @click="step = 4"
                        >
                            {{ t('booking.review') }}
                        </Button>
                    </CardFooter>
                </Card>
            </div>

            <!-- Step 4: Confirmation -->
            <div v-else-if="step === 4" class="mx-auto max-w-2xl space-y-6">
                <div class="flex items-center justify-between">
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="goBack"
                        class="text-muted-foreground hover:text-foreground gap-1"
                    >
                        <ArrowLeft class="size-4" /> {{ t('common.back') }}
                    </Button>
                    <h2 class="text-foreground text-xl font-semibold">
                        {{ t('booking.confirm_title') }}
                    </h2>
                </div>

                <Card class="border-border shadow-sm">
                    <CardHeader>
                        <CardTitle class="text-lg"
                            >{{ t('booking.summary_title') }}</CardTitle
                        >
                        <CardDescription
                            >{{ t('booking.summary_description') }}</CardDescription
                        >
                    </CardHeader>

                    <CardContent class="space-y-4">
                        <div class="divide-border divide-y text-sm">
                            <div class="flex justify-between py-3">
                                <span class="text-muted-foreground"
                                    >{{ t('booking.property_row') }}</span
                                >
                                <span class="text-foreground font-semibold">{{
                                    selectedBranchObj?.name
                                }}</span>
                            </div>
                            <div class="flex justify-between py-3">
                                <span class="text-muted-foreground"
                                    >{{ t('booking.room_type_row') }}</span
                                >
                                <span class="text-foreground font-semibold">{{
                                    selectedRoomType?.name
                                }}</span>
                            </div>
                            <div class="flex justify-between py-3">
                                <span class="text-muted-foreground"
                                    >{{ t('booking.check_in_row') }}</span
                                >
                                <span class="text-foreground font-semibold">{{
                                    checkIn
                                }}</span>
                            </div>
                            <div class="flex justify-between py-3">
                                <span class="text-muted-foreground"
                                    >{{ t('booking.check_out_row') }}</span
                                >
                                <span class="text-foreground font-semibold">{{
                                    checkOut
                                }}</span>
                            </div>
                            <div class="flex justify-between py-3">
                                <span class="text-muted-foreground"
                                    >{{ t('booking.guests_row') }}</span
                                >
                                <span class="text-foreground font-semibold"
                                    >{{ t('portal.guests_value', { adults, children }) }}</span
                                >
                            </div>
                            <div class="flex justify-between py-3">
                                <span class="text-muted-foreground"
                                    >{{ t('booking.guest_name_row') }}</span
                                >
                                <span class="text-foreground font-semibold"
                                    >{{ guestInfo.first_name }}
                                    {{ guestInfo.last_name }}</span
                                >
                            </div>
                            <div class="flex justify-between py-3">
                                <span class="text-muted-foreground">{{ t('booking.email_row') }}</span>
                                <span class="text-foreground font-semibold">{{
                                    guestInfo.email
                                }}</span>
                            </div>
                            <div
                                class="bg-muted/50 -mx-6 flex justify-between rounded-b-lg px-6 py-4 text-base"
                            >
                                <span class="text-foreground font-bold"
                                    >{{ t('booking.total_charge') }}</span
                                >
                                <span
                                    class="text-foreground text-lg font-extrabold"
                                    >{{
                                        formatCurrency(
                                            selectedRoomType?.total_rate || 0,
                                        )
                                    }}</span
                                >
                            </div>
                        </div>

                        <div class="flex items-start space-x-3 pt-4">
                            <input
                                id="terms"
                                v-model="termsAgreed"
                                type="checkbox"
                                name="terms"
                                class="border-input accent-primary mt-0.5 size-4 shrink-0 cursor-pointer rounded-[4px] border shadow-xs"
                            />
                            <Label
                                for="terms"
                                class="text-muted-foreground cursor-pointer text-xs leading-normal select-none"
                            >
                                {{ t('booking.terms') }}
                            </Label>
                        </div>
                    </CardContent>

                    <CardFooter class="flex justify-end pt-2">
                        <Button
                            :disabled="isSubmitting || !termsAgreed"
                            class="w-full sm:w-auto"
                            @click="submitBooking"
                        >
                            <Loader2
                                v-if="isSubmitting"
                                class="mr-2 h-4 w-4 animate-spin"
                            />
                            {{
                                isSubmitting
                                    ? t('booking.processing')
                                    : t('booking.complete')
                            }}
                        </Button>
                    </CardFooter>
                </Card>
            </div>
        </main>
    </div>
</template>
