<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    ArrowLeft,
    CheckCircle2,
    Plus,
    Trash2,
    Loader2,
    Building2,
    BedDouble,
    Map,
    ClipboardCheck,
} from '@lucide/vue';
import { formatCurrency as formatCurrencyRaw } from '@/lib/format';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Branches', href: '/admin/branches' },
            { title: 'Create Branch', href: '/admin/branches/create' },
        ],
    },
});

const CURRENCY_MAP: Record<string, string> = {
    USD: '$',
    EUR: '€',
    GBP: '£',
    CAD: 'C$',
    AUD: 'A$',
    SGD: 'S$',
    INR: '₹',
    AED: 'د.إ',
    NGN: '₦',
};

const COUNTRIES = [
    { code: 'NG', name: 'Nigeria' },
    { code: 'US', name: 'United States' },
    { code: 'GB', name: 'United Kingdom' },
    { code: 'CA', name: 'Canada' },
    { code: 'AU', name: 'Australia' },
    { code: 'IN', name: 'India' },
    { code: 'SG', name: 'Singapore' },
    { code: 'AE', name: 'United Arab Emirates' },
];

const TIMEZONES = [
    'Africa/Lagos',
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'America/Anchorage',
    'Pacific/Honolulu',
    'America/Toronto',
    'America/Vancouver',
    'Europe/London',
    'Europe/Paris',
    'Europe/Berlin',
    'Europe/Moscow',
    'Asia/Dubai',
    'Asia/Kolkata',
    'Asia/Singapore',
    'Asia/Shanghai',
    'Asia/Tokyo',
    'Australia/Sydney',
    'Australia/Melbourne',
    'Pacific/Auckland',
];

const CURRENCIES = [
    'NGN',
    'USD',
    'EUR',
    'GBP',
    'CAD',
    'AUD',
    'SGD',
    'INR',
    'AED',
];

const BED_TYPES = ['single', 'queen', 'king', 'twin', 'sofa'];

const step = ref(1);
const isSubmitting = ref(false);

interface RoomTypeForm {
    name: string;
    code: string;
    base_rate: number;
    max_occupancy: number;
    bed_count: number;
    bed_type: string;
}

const form = reactive({
    name: '',
    code: '',
    address: '',
    city: '',
    state: '',
    country: 'NG',
    timezone: 'Africa/Lagos',
    locale: 'en',
    currency_code: 'NGN',
    currency_symbol: '₦',
    tax_rate: 0,
    tax_label: 'Tax',
    is_primary: false,
    room_types: [
        {
            name: 'Standard Room',
            code: 'STD',
            base_rate: 120,
            max_occupancy: 2,
            bed_count: 1,
            bed_type: 'queen',
        },
    ] as RoomTypeForm[],
    floors: 1,
    rooms_per_floor: 10,
    floor_room_type: 0,
    room_number_prefix: '',
    room_number_start: 1,
});

const stepLabels = ['Branch Info', 'Room Types', 'Floor Plan', 'Review'];

const stepIcons = [Building2, BedDouble, Map, ClipboardCheck];

// --- Auto-generate code from name ---
const generateCode = () => {
    return form.name
        .toLowerCase()
        .replace(/[^a-z0-9]/g, '')
        .substring(0, 10);
};

// Watch name changes to auto-fill code
const onNameInput = () => {
    if (!form.code || form.code === generateCode()) {
        form.code = generateCode();
    }
};

// Watch currency changes to auto-fill symbol
const onCurrencyChange = () => {
    form.currency_symbol = CURRENCY_MAP[form.currency_code] || '$';
};

const formatCurrency = (amount: number) =>
    formatCurrencyRaw(amount, form.currency_symbol);

// --- Room type management ---
const addRoomType = () => {
    form.room_types.push({
        name: '',
        code: '',
        base_rate: 0,
        max_occupancy: 2,
        bed_count: 1,
        bed_type: 'queen',
    });
};

const removeRoomType = (index: number) => {
    if (form.room_types.length > 1) {
        form.room_types.splice(index, 1);
    }
};

// --- Step validation ---
const canProceedStep1 = computed(() => {
    return (
        form.name.trim() !== '' &&
        form.code.trim() !== '' &&
        form.city.trim() !== '' &&
        form.country !== '' &&
        form.timezone !== '' &&
        form.currency_code !== ''
    );
});

const canProceedStep2 = computed(() => {
    return (
        form.room_types.length > 0 &&
        form.room_types.every(
            (rt) =>
                rt.name.trim() !== '' &&
                rt.code.trim() !== '' &&
                rt.base_rate > 0,
        )
    );
});

const canProceedStep3 = computed(() => {
    return form.floors > 0 && form.rooms_per_floor > 0;
});

// --- Room preview ---
const roomPreview = computed(() => {
    const rooms: { number: string; floor: number; room_type: string }[] = [];
    const prefix = form.room_number_prefix || '';
    const start = form.room_number_start;

    for (let floor = 1; floor <= form.floors; floor++) {
        for (let r = 0; r < form.rooms_per_floor; r++) {
            const roomNum = String(start + r).padStart(2, '0');
            const roomNumber = `${prefix}${floor}${roomNum}`;
            const rt =
                form.room_types[form.floor_room_type] || form.room_types[0];
            rooms.push({
                number: roomNumber,
                floor,
                room_type: rt?.name || 'Unknown',
            });
        }
    }
    return rooms;
});

// --- Step navigation ---
const nextStep = () => {
    if (step.value < 4) {
        step.value++;
    }
};

const prevStep = () => {
    if (step.value > 1) {
        step.value--;
    }
};

const goToStep = (s: number) => {
    if (s >= 1 && s <= 4) {
        step.value = s;
    }
};

// --- Submit ---
const submitBranch = () => {
    isSubmitting.value = true;
    router.post('/admin/branches', form, {
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};
</script>

<template>
    <Head title="Create Branch" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <!-- Page Header -->
        <div
            class="border-border flex items-center justify-between border-b pb-2"
        >
            <div>
                <h1 class="text-foreground text-2xl font-bold tracking-tight">
                    Create New Branch
                </h1>
                <p class="text-muted-foreground mt-0.5 text-sm">
                    Set up a new property with room types and floor plan
                </p>
            </div>
        </div>

        <!-- Step Indicator -->
        <div class="mx-auto w-full max-w-2xl">
            <div class="flex items-center justify-between">
                <div
                    v-for="(label, idx) in stepLabels"
                    :key="idx"
                    class="flex items-center"
                >
                    <button
                        class="flex h-10 w-10 cursor-pointer items-center justify-center rounded-full text-sm font-semibold transition-all duration-200"
                        :class="
                            step > idx + 1
                                ? 'bg-primary text-primary-foreground shadow-md'
                                : step === idx + 1
                                  ? 'bg-primary text-primary-foreground ring-primary/20 shadow-md ring-4'
                                  : 'bg-muted text-muted-foreground hover:bg-muted/80'
                        "
                        @click="goToStep(idx + 1)"
                    >
                        <CheckCircle2 v-if="step > idx + 1" class="size-4" />
                        <component v-else :is="stepIcons[idx]" class="size-4" />
                    </button>
                    <div
                        v-if="idx < stepLabels.length - 1"
                        class="mx-2 h-0.5 w-12 rounded-full transition-colors duration-300 sm:w-20"
                        :class="step > idx + 1 ? 'bg-primary' : 'bg-border'"
                    />
                </div>
            </div>
            <div
                class="text-muted-foreground mt-3 flex justify-between px-1 text-xs font-medium"
            >
                <span
                    v-for="(label, idx) in stepLabels"
                    :key="idx"
                    :class="
                        step >= idx + 1 ? 'text-foreground font-semibold' : ''
                    "
                >
                    {{ label }}
                </span>
            </div>
        </div>

        <!-- Step 1: Branch Information -->
        <div v-if="step === 1" class="mx-auto w-full max-w-3xl">
            <Card class="border-border shadow-sm">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-xl">
                        <Building2 class="text-primary size-5" />
                        Branch Information
                    </CardTitle>
                    <CardDescription>
                        Configure the basic details for this property
                    </CardDescription>
                </CardHeader>

                <CardContent class="space-y-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="name">Branch Name *</Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                type="text"
                                placeholder="Grand Hotel Downtown"
                                @input="onNameInput"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="code">Branch Code *</Label>
                            <Input
                                id="code"
                                v-model="form.code"
                                type="text"
                                placeholder="granddt"
                                maxlength="10"
                            />
                            <p class="text-muted-foreground text-xs">
                                Auto-generated from name. Max 10 characters.
                            </p>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <Label for="address">Address</Label>
                        <textarea
                            id="address"
                            v-model="form.address"
                            placeholder="123 Main Street, Suite 100"
                            rows="2"
                            class="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[60px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="space-y-2">
                            <Label for="city">City *</Label>
                            <Input
                                id="city"
                                v-model="form.city"
                                type="text"
                                placeholder="New York"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="state">State / Province</Label>
                            <Input
                                id="state"
                                v-model="form.state"
                                type="text"
                                placeholder="NY"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label>Country *</Label>
                            <Select v-model="form.country">
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="Select country" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="country in COUNTRIES"
                                        :key="country.code"
                                        :value="country.code"
                                    >
                                        {{ country.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label>Timezone *</Label>
                            <Select v-model="form.timezone">
                                <SelectTrigger class="w-full">
                                    <SelectValue
                                        placeholder="Select timezone"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="tz in TIMEZONES"
                                        :key="tz"
                                        :value="tz"
                                    >
                                        {{ tz }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="space-y-2">
                            <Label>Currency *</Label>
                            <Select
                                v-model="form.currency_code"
                                @update:model-value="onCurrencyChange"
                            >
                                <SelectTrigger class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="currency in CURRENCIES"
                                        :key="currency"
                                        :value="currency"
                                    >
                                        {{ currency }} ({{
                                            CURRENCY_MAP[currency]
                                        }})
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="space-y-2">
                            <Label>Default Language</Label>
                            <Select v-model="form.locale">
                                <SelectTrigger class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="en">English</SelectItem>
                                    <SelectItem value="fr">Français</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="space-y-2">
                            <Label for="currency_symbol">Currency Symbol</Label>
                            <Input
                                id="currency_symbol"
                                v-model="form.currency_symbol"
                                type="text"
                                readonly
                                class="bg-muted"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="tax_rate">Tax Rate (%)</Label>
                            <Input
                                id="tax_rate"
                                v-model.number="form.tax_rate"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                placeholder="0"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="tax_label">Tax Label</Label>
                            <Input
                                id="tax_label"
                                v-model="form.tax_label"
                                type="text"
                                placeholder="Tax"
                            />
                        </div>
                    </div>

                    <div class="flex items-center space-x-2 pt-2">
                        <Checkbox
                            id="is_primary"
                            v-model:checked="form.is_primary"
                        />
                        <Label
                            for="is_primary"
                            class="cursor-pointer text-sm leading-none font-medium"
                        >
                            Set as primary branch
                        </Label>
                    </div>
                </CardContent>

                <CardFooter class="flex justify-end pt-2">
                    <Button :disabled="!canProceedStep1" @click="nextStep">
                        Next: Room Types
                    </Button>
                </CardFooter>
            </Card>
        </div>

        <!-- Step 2: Room Types -->
        <div v-else-if="step === 2" class="mx-auto w-full max-w-4xl space-y-6">
            <div class="flex items-center justify-between">
                <Button
                    variant="ghost"
                    size="sm"
                    class="text-muted-foreground hover:text-foreground gap-1"
                    @click="prevStep"
                >
                    <ArrowLeft class="size-4" /> Back
                </Button>
                <h2 class="text-foreground text-lg font-semibold">
                    Room Types
                </h2>
            </div>

            <div class="space-y-4">
                <Card
                    v-for="(roomType, index) in form.room_types"
                    :key="index"
                    class="border-border shadow-xs"
                >
                    <CardHeader class="pb-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <Badge variant="secondary" class="font-mono">
                                    RT{{ index + 1 }}
                                </Badge>
                                <CardTitle class="text-base">
                                    {{
                                        roomType.name ||
                                        `Room Type ${index + 1}`
                                    }}
                                </CardTitle>
                            </div>
                            <Button
                                v-if="form.room_types.length > 1"
                                variant="ghost"
                                size="sm"
                                class="text-destructive hover:text-destructive"
                                @click="removeRoomType(index)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </CardHeader>

                    <CardContent class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label>Name *</Label>
                                <Input
                                    v-model="roomType.name"
                                    type="text"
                                    placeholder="Standard Room"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label>Code *</Label>
                                <Input
                                    v-model="roomType.code"
                                    type="text"
                                    placeholder="STD"
                                    maxlength="5"
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <div class="space-y-2">
                                <Label>Base Rate *</Label>
                                <Input
                                    v-model.number="roomType.base_rate"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    placeholder="120"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label>Max Occupancy</Label>
                                <Input
                                    v-model.number="roomType.max_occupancy"
                                    type="number"
                                    min="1"
                                    max="10"
                                    placeholder="2"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label>Bed Count</Label>
                                <Input
                                    v-model.number="roomType.bed_count"
                                    type="number"
                                    min="0"
                                    max="5"
                                    placeholder="1"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label>Bed Type</Label>
                                <Select v-model="roomType.bed_type">
                                    <SelectTrigger class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="bt in BED_TYPES"
                                            :key="bt"
                                            :value="bt"
                                        >
                                            {{
                                                bt.charAt(0).toUpperCase() +
                                                bt.slice(1)
                                            }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Button
                    variant="outline"
                    class="w-full border-dashed"
                    @click="addRoomType"
                >
                    <Plus class="mr-2 size-4" />
                    Add Room Type
                </Button>
            </div>

            <div class="flex justify-end">
                <Button :disabled="!canProceedStep2" @click="nextStep">
                    Next: Floor Plan
                </Button>
            </div>
        </div>

        <!-- Step 3: Floor Plan & Rooms -->
        <div v-else-if="step === 3" class="mx-auto w-full max-w-4xl space-y-6">
            <div class="flex items-center justify-between">
                <Button
                    variant="ghost"
                    size="sm"
                    class="text-muted-foreground hover:text-foreground gap-1"
                    @click="prevStep"
                >
                    <ArrowLeft class="size-4" /> Back
                </Button>
                <h2 class="text-foreground text-lg font-semibold">
                    Floor Plan & Rooms
                </h2>
            </div>

            <Card class="border-border shadow-sm">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-xl">
                        <Map class="text-primary size-5" />
                        Configure Floors & Rooms
                    </CardTitle>
                    <CardDescription>
                        Define how many floors and rooms your branch will have
                    </CardDescription>
                </CardHeader>

                <CardContent class="space-y-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="space-y-2">
                            <Label for="floors">Number of Floors *</Label>
                            <Input
                                id="floors"
                                v-model.number="form.floors"
                                type="number"
                                min="1"
                                max="50"
                                placeholder="1"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="rooms_per_floor"
                                >Rooms Per Floor *</Label
                            >
                            <Input
                                id="rooms_per_floor"
                                v-model.number="form.rooms_per_floor"
                                type="number"
                                min="1"
                                max="100"
                                placeholder="10"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label>Room Type Assignment</Label>
                            <Select v-model.number="form.floor_room_type">
                                <SelectTrigger class="w-full">
                                    <SelectValue
                                        placeholder="Select room type"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="(rt, idx) in form.room_types"
                                        :key="idx"
                                        :value="idx"
                                    >
                                        {{ rt.name }} ({{ rt.code }})
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="room_prefix">Room Number Prefix</Label>
                            <Input
                                id="room_prefix"
                                v-model="form.room_number_prefix"
                                type="text"
                                placeholder="e.g., 1, A, S (optional)"
                                maxlength="3"
                            />
                            <p class="text-muted-foreground text-xs">
                                Prefix + floor number + room number. Example:
                                prefix "1" → 1101, 1102...
                            </p>
                        </div>
                        <div class="space-y-2">
                            <Label for="room_start">Starting Room Number</Label>
                            <Input
                                id="room_start"
                                v-model.number="form.room_number_start"
                                type="number"
                                min="0"
                                max="99"
                                placeholder="1"
                            />
                        </div>
                    </div>

                    <!-- Room Preview Table -->
                    <div v-if="roomPreview.length > 0" class="pt-2">
                        <h3 class="text-foreground mb-3 text-sm font-semibold">
                            Room Preview ({{ roomPreview.length }} rooms will be
                            created)
                        </h3>
                        <div
                            class="border-border max-h-64 overflow-y-auto rounded-lg border"
                        >
                            <table class="w-full text-sm">
                                <thead
                                    class="bg-muted/80 sticky top-0 backdrop-blur-sm"
                                >
                                    <tr class="border-border border-b">
                                        <th
                                            class="text-muted-foreground px-3 py-2 text-left font-medium"
                                        >
                                            Room #
                                        </th>
                                        <th
                                            class="text-muted-foreground px-3 py-2 text-left font-medium"
                                        >
                                            Floor
                                        </th>
                                        <th
                                            class="text-muted-foreground px-3 py-2 text-left font-medium"
                                        >
                                            Room Type
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(room, idx) in roomPreview"
                                        :key="idx"
                                        class="border-border/50 border-b last:border-0"
                                    >
                                        <td
                                            class="px-3 py-1.5 font-mono text-xs"
                                        >
                                            {{ room.number }}
                                        </td>
                                        <td class="px-3 py-1.5">
                                            {{ room.floor }}
                                        </td>
                                        <td class="px-3 py-1.5">
                                            {{ room.room_type }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </CardContent>

                <CardFooter class="flex justify-end pt-2">
                    <Button :disabled="!canProceedStep3" @click="nextStep">
                        Next: Review
                    </Button>
                </CardFooter>
            </Card>
        </div>

        <!-- Step 4: Review & Create -->
        <div v-else-if="step === 4" class="mx-auto w-full max-w-4xl space-y-6">
            <div class="flex items-center justify-between">
                <Button
                    variant="ghost"
                    size="sm"
                    class="text-muted-foreground hover:text-foreground gap-1"
                    @click="prevStep"
                >
                    <ArrowLeft class="size-4" /> Back
                </Button>
                <h2 class="text-foreground text-lg font-semibold">
                    Review & Create
                </h2>
            </div>

            <!-- Branch Info Card -->
            <Card class="border-border shadow-sm">
                <CardHeader class="pb-3">
                    <div class="flex items-center justify-between">
                        <CardTitle class="flex items-center gap-2 text-base">
                            <Building2 class="text-primary size-4" />
                            Branch Information
                        </CardTitle>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-xs"
                            @click="goToStep(1)"
                        >
                            Edit
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2"
                    >
                        <div class="flex justify-between sm:block">
                            <span class="text-muted-foreground block sm:mb-0.5"
                                >Name</span
                            >
                            <span class="text-foreground font-semibold">{{
                                form.name
                            }}</span>
                        </div>
                        <div class="flex justify-between sm:block">
                            <span class="text-muted-foreground block sm:mb-0.5"
                                >Code</span
                            >
                            <span
                                class="text-foreground font-mono font-semibold"
                                >{{ form.code }}</span
                            >
                        </div>
                        <div class="flex justify-between sm:block">
                            <span class="text-muted-foreground block sm:mb-0.5"
                                >City</span
                            >
                            <span class="text-foreground font-semibold"
                                >{{ form.city
                                }}{{
                                    form.state ? `, ${form.state}` : ''
                                }}</span
                            >
                        </div>
                        <div class="flex justify-between sm:block">
                            <span class="text-muted-foreground block sm:mb-0.5"
                                >Country</span
                            >
                            <span class="text-foreground font-semibold">{{
                                COUNTRIES.find((c) => c.code === form.country)
                                    ?.name || form.country
                            }}</span>
                        </div>
                        <div class="flex justify-between sm:block">
                            <span class="text-muted-foreground block sm:mb-0.5"
                                >Timezone</span
                            >
                            <span class="text-foreground font-semibold">{{
                                form.timezone
                            }}</span>
                        </div>
                        <div class="flex justify-between sm:block">
                            <span class="text-muted-foreground block sm:mb-0.5"
                                >Currency</span
                            >
                            <span class="text-foreground font-semibold"
                                >{{ form.currency_code }} ({{
                                    form.currency_symbol
                                }})</span
                            >
                        </div>
                        <div class="flex justify-between sm:block">
                            <span class="text-muted-foreground block sm:mb-0.5"
                                >Tax Rate</span
                            >
                            <span class="text-foreground font-semibold"
                                >{{ form.tax_rate }}% ({{
                                    form.tax_label
                                }})</span
                            >
                        </div>
                        <div class="flex justify-between sm:block">
                            <span class="text-muted-foreground block sm:mb-0.5"
                                >Primary Branch</span
                            >
                            <Badge
                                :variant="
                                    form.is_primary ? 'default' : 'secondary'
                                "
                            >
                                {{ form.is_primary ? 'Yes' : 'No' }}
                            </Badge>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Room Types Table -->
            <Card class="border-border shadow-sm">
                <CardHeader class="pb-3">
                    <div class="flex items-center justify-between">
                        <CardTitle class="flex items-center gap-2 text-base">
                            <BedDouble class="text-primary size-4" />
                            Room Types ({{ form.room_types.length }})
                        </CardTitle>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-xs"
                            @click="goToStep(2)"
                        >
                            Edit
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        class="border-border overflow-x-auto rounded-lg border"
                    >
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50">
                                <tr class="border-border border-b">
                                    <th
                                        class="text-muted-foreground px-3 py-2 text-left font-medium"
                                    >
                                        Name
                                    </th>
                                    <th
                                        class="text-muted-foreground px-3 py-2 text-left font-medium"
                                    >
                                        Code
                                    </th>
                                    <th
                                        class="text-muted-foreground px-3 py-2 text-right font-medium"
                                    >
                                        Rate
                                    </th>
                                    <th
                                        class="text-muted-foreground px-3 py-2 text-center font-medium"
                                    >
                                        Max Guests
                                    </th>
                                    <th
                                        class="text-muted-foreground px-3 py-2 text-center font-medium"
                                    >
                                        Beds
                                    </th>
                                    <th
                                        class="text-muted-foreground px-3 py-2 text-left font-medium"
                                    >
                                        Bed Type
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(rt, idx) in form.room_types"
                                    :key="idx"
                                    class="border-border/50 border-b last:border-0"
                                >
                                    <td
                                        class="text-foreground px-3 py-2 font-semibold"
                                    >
                                        {{ rt.name }}
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs">
                                        {{ rt.code }}
                                    </td>
                                    <td
                                        class="text-foreground px-3 py-2 text-right font-semibold"
                                    >
                                        {{ formatCurrency(rt.base_rate) }}
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        {{ rt.max_occupancy }}
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        {{ rt.bed_count }}
                                    </td>
                                    <td class="px-3 py-2 capitalize">
                                        {{ rt.bed_type }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <!-- Rooms Table -->
            <Card class="border-border shadow-sm">
                <CardHeader class="pb-3">
                    <div class="flex items-center justify-between">
                        <CardTitle class="flex items-center gap-2 text-base">
                            <Map class="text-primary size-4" />
                            Rooms ({{ roomPreview.length }})
                        </CardTitle>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-xs"
                            @click="goToStep(3)"
                        >
                            Edit
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        class="border-border max-h-60 overflow-y-auto rounded-lg border"
                    >
                        <table class="w-full text-sm">
                            <thead
                                class="bg-muted/80 sticky top-0 backdrop-blur-sm"
                            >
                                <tr class="border-border border-b">
                                    <th
                                        class="text-muted-foreground px-3 py-2 text-left font-medium"
                                    >
                                        Room #
                                    </th>
                                    <th
                                        class="text-muted-foreground px-3 py-2 text-left font-medium"
                                    >
                                        Floor
                                    </th>
                                    <th
                                        class="text-muted-foreground px-3 py-2 text-left font-medium"
                                    >
                                        Room Type
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(room, idx) in roomPreview"
                                    :key="idx"
                                    class="border-border/50 border-b last:border-0"
                                >
                                    <td class="px-3 py-1.5 font-mono text-xs">
                                        {{ room.number }}
                                    </td>
                                    <td class="px-3 py-1.5">
                                        {{ room.floor }}
                                    </td>
                                    <td class="px-3 py-1.5">
                                        {{ room.room_type }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <!-- Submit -->
            <div class="flex justify-end">
                <Button
                    :disabled="isSubmitting"
                    size="lg"
                    class="min-w-[160px]"
                    @click="submitBranch"
                >
                    <Loader2
                        v-if="isSubmitting"
                        class="mr-2 h-4 w-4 animate-spin"
                    />
                    {{ isSubmitting ? 'Creating...' : 'Create Branch' }}
                </Button>
            </div>
        </div>
    </div>
</template>
