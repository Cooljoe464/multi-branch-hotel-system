<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { formatCurrency as formatCurrencyRaw } from '@/lib/format';
import DatePicker from '@/components/ui/date-picker/DatePicker.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { ArrowLeft, CheckCircle2, BedDouble, Users, Loader2 } from '@lucide/vue';

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
const searchResults = ref<{ room_types: RoomType[]; nights: number } | null>(null);
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

const selectedBranchObj = computed(() =>
    props.branches.find((b) => b.id === selectedBranch.value) ?? null
);

const canProceedStep1 = computed(() => {
    return selectedBranch.value && checkIn.value && checkOut.value && adults.value > 0;
});

const canProceedStep3 = computed(() => {
    return guestInfo.value.first_name && guestInfo.value.last_name && guestInfo.value.email;
});

const searchAvailability = async () => {
    if (! selectedBranchObj.value) return;

    const response = await fetch('/book/search', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
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
    if (! selectedBranchObj.value || ! selectedRoomType.value) return;

    isSubmitting.value = true;

    try {
        const response = await fetch('/book', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                branch_id: selectedBranchObj.value.id,
                room_type_id: selectedRoomType.value.room_type_id ?? selectedRoomType.value.id,
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

const formatCurrency = (amount: number, symbol: string = currencySymbol.value) => formatCurrencyRaw(amount, symbol);

const getAmenityIcon = (amenity: string) => {
    const icons: Record<string, string> = {
        wifi: '📶',
        tv: '📺',
        minibar: '🍸',
        'air_conditioning': '❄️',
        safe: '🔒',
        balcony: '🌅',
    };
    return icons[amenity] || '✓';
};
</script>

<template>
    <Head title="Book a Room" />

    <div class="min-h-screen bg-muted/30">
        <!-- Header -->
        <header class="bg-background border-b border-border shadow-xs">
            <div class="max-w-6xl mx-auto px-4 py-6 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                        <AppLogoIcon class="size-6 fill-current" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-foreground">Book Your Stay</h1>
                        <p class="text-xs text-muted-foreground">Find and reserve luxury rooms across our properties</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-6xl mx-auto px-4 py-8">
            <!-- Progress Steps -->
            <div class="mb-10 max-w-xl mx-auto">
                <div class="flex items-center justify-between">
                    <div v-for="s in 4" :key="s" class="flex items-center">
                        <div
                            class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold transition-all duration-200"
                            :class="step >= s ? 'bg-primary text-primary-foreground shadow-md' : 'bg-muted text-muted-foreground'"
                        >
                            <CheckCircle2 v-if="step > s" class="size-4" />
                            <span v-else>{{ s }}</span>
                        </div>
                        <div
                            v-if="s < 4"
                            class="w-16 sm:w-24 h-0.5 mx-2 rounded-full transition-colors duration-300"
                            :class="step > s ? 'bg-primary' : 'bg-border'"
                        ></div>
                    </div>
                </div>
                <div class="flex justify-between mt-3 text-xs font-medium text-muted-foreground px-1">
                    <span :class="step >= 1 ? 'text-foreground font-semibold' : ''">Dates</span>
                    <span :class="step >= 2 ? 'text-foreground font-semibold' : ''">Room</span>
                    <span :class="step >= 3 ? 'text-foreground font-semibold' : ''">Guest</span>
                    <span :class="step >= 4 ? 'text-foreground font-semibold' : ''">Confirm</span>
                </div>
            </div>

            <!-- Booking Complete -->
            <Card v-if="bookingComplete" class="max-w-lg mx-auto text-center py-8 border-border shadow-md">
                <CardContent class="pt-6">
                    <div class="w-16 h-16 bg-emerald-100 dark:bg-emerald-950/50 rounded-full flex items-center justify-center mx-auto mb-6 text-emerald-600 dark:text-emerald-400">
                        <CheckCircle2 class="w-10 h-10" />
                    </div>
                    <h2 class="text-2xl font-bold text-foreground mb-2">Booking Confirmed!</h2>
                    <p class="text-sm text-muted-foreground mb-4">Your confirmation number is:</p>
                    <div class="inline-block px-4 py-2 bg-muted rounded-lg border border-border text-2xl font-mono font-bold text-foreground mb-6 tracking-widest">
                        {{ confirmationNumber }}
                    </div>
                    <p class="text-sm text-muted-foreground mb-8">
                        A confirmation details summary has been saved for <span class="font-medium text-foreground">{{ guestInfo.email }}</span>
                    </p>
                    <Button as-child class="w-full">
                        <Link :href="`/guest/folio/${confirmationNumber}`">
                            View Your Folio
                        </Link>
                    </Button>
                </CardContent>
            </Card>

            <!-- Step 1: Select Branch & Dates -->
            <div v-else-if="step === 1" class="max-w-2xl mx-auto">
                <Card class="border-border shadow-sm">
                    <CardHeader>
                        <CardTitle class="text-xl">Select Property & Dates</CardTitle>
                        <CardDescription>Choose your destination hotel and check-in duration</CardDescription>
                    </CardHeader>

                    <CardContent class="space-y-5">
                        <div class="space-y-2">
                            <Label>Property</Label>
                            <Select v-model="selectedBranch" aria-label="Select a property">
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="Select a property" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="branch in branches" :key="branch.id" :value="branch.id">
                                        {{ branch.name }} ({{ branch.city }}, {{ branch.country }})
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <Label for="check_in">Check-in Date</Label>
                                <DatePicker
                                    id="check_in"
                                    v-model="checkIn"
                                    :min-date="today"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="check_out">Check-out Date</Label>
                                <DatePicker
                                    id="check_out"
                                    v-model="checkOut"
                                    :min-date="checkIn || today"
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <Label>Adults</Label>
                                <Select v-model="adults" aria-label="Number of adults">
                                    <SelectTrigger class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="n in 10" :key="n" :value="n">{{ n }} Adult{{ n > 1 ? 's' : '' }}</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="space-y-2">
                                <Label>Children</Label>
                                <Select v-model="children" aria-label="Number of children">
                                    <SelectTrigger class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="n in 6" :key="n" :value="n - 1">{{ n - 1 }} Children</SelectItem>
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
                            Search Availability
                        </Button>
                    </CardFooter>
                </Card>
            </div>

            <!-- Step 2: Select Room Type -->
            <div v-else-if="step === 2" class="max-w-4xl mx-auto space-y-6">
                <div class="flex justify-between items-center">
                    <Button variant="ghost" size="sm" @click="goBack" class="gap-1 text-muted-foreground hover:text-foreground">
                        <ArrowLeft class="size-4" /> Back
                    </Button>
                    <h2 class="text-xl font-semibold text-foreground">Available Rooms ({{ searchResults?.nights }} nights)</h2>
                </div>

                <div class="grid gap-4">
                    <Card
                        v-for="roomType in searchResults?.room_types"
                        :key="roomType.id"
                        class="border-border shadow-xs hover:shadow-md transition-all cursor-pointer overflow-hidden"
                        @click="selectRoomType(roomType)"
                    >
                        <CardContent class="p-6">
                            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                                <div class="space-y-3 flex-1">
                                    <div class="flex items-center gap-3">
                                        <h3 class="text-lg font-bold text-foreground">{{ roomType.name }}</h3>
                                        <Badge variant="secondary" class="bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 border-emerald-200 dark:border-emerald-800">
                                            {{ roomType.available_count }} available
                                        </Badge>
                                    </div>
                                    <p class="text-sm text-muted-foreground leading-relaxed">{{ roomType.description }}</p>

                                    <div class="flex items-center gap-4 text-xs text-muted-foreground pt-1">
                                        <span class="inline-flex items-center gap-1">
                                            <BedDouble class="size-3.5" />
                                            {{ roomType.bed_count }} {{ roomType.bed_type }} bed{{ roomType.bed_count > 1 ? 's' : '' }}
                                        </span>
                                        <span class="inline-flex items-center gap-1">
                                            <Users class="size-3.5" />
                                            Max {{ roomType.max_occupancy }} guests
                                        </span>
                                    </div>

                                    <div class="flex flex-wrap gap-1.5 pt-2">
                                        <Badge
                                            v-for="amenity in roomType.amenities"
                                            :key="amenity"
                                            variant="outline"
                                            class="text-xs font-normal"
                                        >
                                            {{ getAmenityIcon(amenity) }} {{ amenity.replace('_', ' ') }}
                                        </Badge>
                                    </div>
                                </div>

                                <div class="flex flex-col items-start md:items-end justify-between self-stretch border-t md:border-t-0 md:border-l border-border pt-4 md:pt-0 md:pl-6">
                                    <div class="text-left md:text-right">
                                        <div class="text-2xl font-extrabold text-foreground">
                                            {{ formatCurrency(roomType.base_rate) }}
                                        </div>
                                        <div class="text-xs text-muted-foreground">per night</div>
                                        <div class="text-sm font-semibold text-foreground mt-2">
                                            Total: {{ formatCurrency(roomType.total_rate || 0) }}
                                        </div>
                                    </div>
                                    <Button class="mt-4 w-full md:w-auto" @click.stop="selectRoomType(roomType)">
                                        Select Room
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- Step 3: Guest Information -->
            <div v-else-if="step === 3" class="max-w-2xl mx-auto space-y-6">
                <div class="flex justify-between items-center">
                    <Button variant="ghost" size="sm" @click="goBack" class="gap-1 text-muted-foreground hover:text-foreground">
                        <ArrowLeft class="size-4" /> Back
                    </Button>
                    <h2 class="text-xl font-semibold text-foreground">Guest Details</h2>
                </div>

                <Card class="border-border shadow-sm">
                    <CardHeader>
                        <CardTitle class="text-lg">Contact Information</CardTitle>
                        <CardDescription>Enter primary guest details for reservation processing</CardDescription>
                    </CardHeader>

                    <CardContent class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <Label for="first_name">First Name *</Label>
                                <Input
                                    id="first_name"
                                    v-model="guestInfo.first_name"
                                    type="text"
                                    required
                                    placeholder="Jane"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="last_name">Last Name *</Label>
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
                            <Label for="email">Email Address *</Label>
                            <Input
                                id="email"
                                v-model="guestInfo.email"
                                type="email"
                                required
                                placeholder="jane.doe@example.com"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label for="phone">Phone Number</Label>
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
                            Review Booking
                        </Button>
                    </CardFooter>
                </Card>
            </div>

            <!-- Step 4: Confirmation -->
            <div v-else-if="step === 4" class="max-w-2xl mx-auto space-y-6">
                <div class="flex justify-between items-center">
                    <Button variant="ghost" size="sm" @click="goBack" class="gap-1 text-muted-foreground hover:text-foreground">
                        <ArrowLeft class="size-4" /> Back
                    </Button>
                    <h2 class="text-xl font-semibold text-foreground">Confirm Your Reservation</h2>
                </div>

                <Card class="border-border shadow-sm">
                    <CardHeader>
                        <CardTitle class="text-lg">Reservation Summary</CardTitle>
                        <CardDescription>Please double check your details before finalizing</CardDescription>
                    </CardHeader>

                    <CardContent class="space-y-4">
                        <div class="divide-y divide-border text-sm">
                            <div class="flex justify-between py-3">
                                <span class="text-muted-foreground">Property</span>
                                <span class="font-semibold text-foreground">{{ selectedBranchObj?.name }}</span>
                            </div>
                            <div class="flex justify-between py-3">
                                <span class="text-muted-foreground">Room Type</span>
                                <span class="font-semibold text-foreground">{{ selectedRoomType?.name }}</span>
                            </div>
                            <div class="flex justify-between py-3">
                                <span class="text-muted-foreground">Check-in</span>
                                <span class="font-semibold text-foreground">{{ checkIn }}</span>
                            </div>
                            <div class="flex justify-between py-3">
                                <span class="text-muted-foreground">Check-out</span>
                                <span class="font-semibold text-foreground">{{ checkOut }}</span>
                            </div>
                            <div class="flex justify-between py-3">
                                <span class="text-muted-foreground">Guests</span>
                                <span class="font-semibold text-foreground">{{ adults }} adults, {{ children }} children</span>
                            </div>
                            <div class="flex justify-between py-3">
                                <span class="text-muted-foreground">Guest Name</span>
                                <span class="font-semibold text-foreground">{{ guestInfo.first_name }} {{ guestInfo.last_name }}</span>
                            </div>
                            <div class="flex justify-between py-3">
                                <span class="text-muted-foreground">Email</span>
                                <span class="font-semibold text-foreground">{{ guestInfo.email }}</span>
                            </div>
                            <div class="flex justify-between py-4 text-base bg-muted/50 -mx-6 px-6 rounded-b-lg">
                                <span class="font-bold text-foreground">Total Charge</span>
                                <span class="font-extrabold text-foreground text-lg">{{ formatCurrency(selectedRoomType?.total_rate || 0) }}</span>
                            </div>
                        </div>

                        <div class="pt-4 flex items-start space-x-3">
                            <input
                                id="terms"
                                v-model="termsAgreed"
                                type="checkbox"
                                name="terms"
                                class="mt-0.5 size-4 shrink-0 rounded-[4px] border border-input shadow-xs accent-primary cursor-pointer"
                            />
                            <Label for="terms" class="text-xs text-muted-foreground leading-normal cursor-pointer select-none">
                                I agree to the booking terms and conditions. I understand that payment will be processed at check-in.
                            </Label>
                        </div>
                    </CardContent>

                    <CardFooter class="flex justify-end pt-2">
                        <Button
                            :disabled="isSubmitting || !termsAgreed"
                            class="w-full sm:w-auto"
                            @click="submitBooking"
                        >
                            <Loader2 v-if="isSubmitting" class="mr-2 h-4 w-4 animate-spin" />
                            {{ isSubmitting ? 'Processing...' : 'Complete Booking' }}
                        </Button>
                    </CardFooter>
                </Card>
            </div>
        </main>
    </div>
</template>
