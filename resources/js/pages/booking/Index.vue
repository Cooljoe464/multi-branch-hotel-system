<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';

interface Branch {
    id: number;
    name: string;
    city: string;
    country: string;
    currency_symbol: string;
    room_types: RoomType[];
}

interface RoomType {
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
const selectedBranch = ref<Branch | null>(null);
const checkIn = ref('');
const checkOut = ref('');
const adults = ref(2);
const children = ref(0);
const searchResults = ref<{ room_types: RoomType[]; nights: number } | null>(null);
const selectedRoomType = ref<RoomType | null>(null);
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

const canProceedStep1 = computed(() => {
    return selectedBranch.value && checkIn.value && checkOut.value && adults.value > 0;
});

const canProceedStep3 = computed(() => {
    return guestInfo.value.first_name && guestInfo.value.last_name && guestInfo.value.email;
});

const searchAvailability = async () => {
    if (! selectedBranch.value) return;

    const response = await fetch('/book/search', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({
            branch_id: selectedBranch.value.id,
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
    if (! selectedBranch.value || ! selectedRoomType.value) return;

    isSubmitting.value = true;

    const response = await fetch('/book', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({
            branch_id: selectedBranch.value.id,
            room_type_id: selectedRoomType.value.id,
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

    isSubmitting.value = false;
};

const goBack = () => {
    if (step.value > 1) {
        step.value--;
    }
};

const formatCurrency = (amount: number, symbol: string = '$') => {
    return `${symbol}${(amount / 100).toFixed(2)}`;
};

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

    <div class="min-h-screen bg-gray-50">
        <!-- Header -->
        <header class="bg-white shadow">
            <div class="max-w-7xl mx-auto px-4 py-6">
                <h1 class="text-3xl font-bold text-gray-900">Book Your Stay</h1>
                <p class="mt-2 text-gray-600">Find and book rooms across our properties</p>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 py-8">
            <!-- Progress Steps -->
            <div class="mb-8">
                <div class="flex items-center justify-center space-x-8">
                    <div v-for="s in 4" :key="s" class="flex items-center">
                        <div
                            class="w-10 h-10 rounded-full flex items-center justify-center font-medium"
                            :class="step >= s ? 'bg-gray-900 text-white' : 'bg-gray-200 text-gray-500'"
                        >
                            {{ s }}
                        </div>
                        <span v-if="s < 4" class="w-12 h-0.5 mx-2" :class="step > s ? 'bg-gray-900' : 'bg-gray-200'"></span>
                    </div>
                </div>
                <div class="flex justify-center mt-4 space-x-16 text-sm">
                    <span :class="step >= 1 ? 'text-gray-900 font-medium' : 'text-gray-500'">Select Dates</span>
                    <span :class="step >= 2 ? 'text-gray-900 font-medium' : 'text-gray-500'">Choose Room</span>
                    <span :class="step >= 3 ? 'text-gray-900 font-medium' : 'text-gray-500'">Guest Info</span>
                    <span :class="step >= 4 ? 'text-gray-900 font-medium' : 'text-gray-500'">Confirm</span>
                </div>
            </div>

            <!-- Booking Complete -->
            <div v-if="bookingComplete" class="max-w-lg mx-auto text-center py-12">
                <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <h2 class="text-2xl font-bold text-gray-900 mb-2">Booking Confirmed!</h2>
                <p class="text-gray-600 mb-4">Your confirmation number is:</p>
                <p class="text-3xl font-mono font-bold text-gray-900 mb-6">{{ confirmationNumber }}</p>
                <p class="text-gray-600 mb-8">A confirmation email has been sent to {{ guestInfo.email }}</p>
                <a
                    :href="`/guest/folio/${confirmationNumber}`"
                    class="inline-block px-6 py-3 bg-gray-900 text-white rounded-md hover:bg-gray-800"
                >
                    View Your Folio
                </a>
            </div>

            <!-- Step 1: Select Branch & Dates -->
            <div v-else-if="step === 1" class="max-w-2xl mx-auto">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-semibold mb-6">Select Property & Dates</h2>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Property</label>
                            <select
                                v-model="selectedBranch"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                            >
                                <option :value="null">Select a property</option>
                                <option v-for="branch in branches" :key="branch.id" :value="branch">
                                    {{ branch.name }} - {{ branch.city }}
                                </option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Check-in Date</label>
                                <input
                                    v-model="checkIn"
                                    type="date"
                                    :min="today"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                                >
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Check-out Date</label>
                                <input
                                    v-model="checkOut"
                                    type="date"
                                    :min="checkIn || today"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                                >
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Adults</label>
                                <select v-model="adults" class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                                    <option v-for="n in 10" :key="n" :value="n">{{ n }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Children</label>
                                <select v-model="children" class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                                    <option v-for="n in 6" :key="n" :value="n - 1">{{ n - 1 }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <button
                            :disabled="!canProceedStep1"
                            class="px-6 py-3 bg-gray-900 text-white rounded-md hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed"
                            @click="searchAvailability"
                        >
                            Search Availability
                        </button>
                    </div>
                </div>
            </div>

            <!-- Step 2: Select Room Type -->
            <div v-else-if="step === 2" class="max-w-4xl mx-auto">
                <div class="flex justify-between items-center mb-6">
                    <button class="text-gray-600 hover:text-gray-900" @click="goBack">
                        ← Back
                    </button>
                    <h2 class="text-xl font-semibold">Available Rooms ({{ searchResults?.nights }} nights)</h2>
                </div>

                <div class="grid gap-6">
                    <div
                        v-for="roomType in searchResults?.room_types"
                        :key="roomType.id"
                        class="bg-white rounded-lg shadow p-6 hover:shadow-md transition-shadow cursor-pointer"
                        @click="selectRoomType(roomType)"
                    >
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">{{ roomType.name }}</h3>
                                <p class="text-sm text-gray-500 mb-2">{{ roomType.description }}</p>
                                <div class="flex items-center gap-4 text-sm text-gray-600">
                                    <span>{{ roomType.bed_count }} {{ roomType.bed_type }} bed{{ roomType.bed_count > 1 ? 's' : '' }}</span>
                                    <span>Max {{ roomType.max_occupancy }} guests</span>
                                    <span class="text-green-600">{{ roomType.available_count }} available</span>
                                </div>
                                <div class="flex flex-wrap gap-2 mt-3">
                                    <span
                                        v-for="amenity in roomType.amenities"
                                        :key="amenity"
                                        class="text-xs bg-gray-100 px-2 py-1 rounded"
                                    >
                                        {{ getAmenityIcon(amenity) }} {{ amenity.replace('_', ' ') }}
                                    </span>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold text-gray-900">
                                    {{ formatCurrency(roomType.base_rate) }}
                                </div>
                                <div class="text-sm text-gray-500">per night</div>
                                <div class="text-lg font-semibold text-gray-900 mt-2">
                                    {{ formatCurrency(roomType.total_rate || 0) }}
                                </div>
                                <div class="text-sm text-gray-500">total</div>
                            </div>
                        </div>
                        <div class="mt-4 flex justify-end">
                            <button class="px-4 py-2 bg-gray-900 text-white rounded-md hover:bg-gray-800">
                                Select This Room
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 3: Guest Information -->
            <div v-else-if="step === 3" class="max-w-2xl mx-auto">
                <div class="flex justify-between items-center mb-6">
                    <button class="text-gray-600 hover:text-gray-900" @click="goBack">
                        ← Back
                    </button>
                    <h2 class="text-xl font-semibold">Guest Information</h2>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">First Name *</label>
                                <input
                                    v-model="guestInfo.first_name"
                                    type="text"
                                    required
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                                >
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Last Name *</label>
                                <input
                                    v-model="guestInfo.last_name"
                                    type="text"
                                    required
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                                >
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Email *</label>
                            <input
                                v-model="guestInfo.email"
                                type="email"
                                required
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Phone</label>
                            <input
                                v-model="guestInfo.phone"
                                type="tel"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                            >
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <button
                            :disabled="!canProceedStep3"
                            class="px-6 py-3 bg-gray-900 text-white rounded-md hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed"
                            @click="step = 4"
                        >
                            Review Booking
                        </button>
                    </div>
                </div>
            </div>

            <!-- Step 4: Confirmation -->
            <div v-else-if="step === 4" class="max-w-2xl mx-auto">
                <div class="flex justify-between items-center mb-6">
                    <button class="text-gray-600 hover:text-gray-900" @click="goBack">
                        ← Back
                    </button>
                    <h2 class="text-xl font-semibold">Confirm Your Booking</h2>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="space-y-4">
                        <div class="flex justify-between py-2 border-b">
                            <span class="text-gray-600">Property</span>
                            <span class="font-medium">{{ selectedBranch?.name }}</span>
                        </div>
                        <div class="flex justify-between py-2 border-b">
                            <span class="text-gray-600">Room Type</span>
                            <span class="font-medium">{{ selectedRoomType?.name }}</span>
                        </div>
                        <div class="flex justify-between py-2 border-b">
                            <span class="text-gray-600">Check-in</span>
                            <span class="font-medium">{{ checkIn }}</span>
                        </div>
                        <div class="flex justify-between py-2 border-b">
                            <span class="text-gray-600">Check-out</span>
                            <span class="font-medium">{{ checkOut }}</span>
                        </div>
                        <div class="flex justify-between py-2 border-b">
                            <span class="text-gray-600">Guests</span>
                            <span class="font-medium">{{ adults }} adults, {{ children }} children</span>
                        </div>
                        <div class="flex justify-between py-2 border-b">
                            <span class="text-gray-600">Guest Name</span>
                            <span class="font-medium">{{ guestInfo.first_name }} {{ guestInfo.last_name }}</span>
                        </div>
                        <div class="flex justify-between py-2 border-b">
                            <span class="text-gray-600">Email</span>
                            <span class="font-medium">{{ guestInfo.email }}</span>
                        </div>
                        <div class="flex justify-between py-3 text-lg">
                            <span class="font-semibold">Total</span>
                            <span class="font-bold">{{ formatCurrency(selectedRoomType?.total_rate || 0) }}</span>
                        </div>
                    </div>

                    <div class="mt-6">
                        <label class="flex items-start gap-3">
                            <input type="checkbox" class="mt-1 rounded border-gray-300">
                            <span class="text-sm text-gray-600">
                                I agree to the booking terms and conditions. I understand that payment will be processed at check-in.
                            </span>
                        </label>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <button
                            :disabled="isSubmitting"
                            class="px-6 py-3 bg-gray-900 text-white rounded-md hover:bg-gray-800 disabled:opacity-50"
                            @click="submitBooking"
                        >
                            {{ isSubmitting ? 'Processing...' : 'Complete Booking' }}
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
