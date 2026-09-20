<script setup lang="ts">
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from '@lucide/vue';
import DatePicker from '@/components/ui/date-picker/DatePicker.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatCurrency as formatCurrencyRaw } from '@/lib/format';

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Reservations', href: '/reservations' }, { title: 'New Reservation', href: '/reservations/create' }] } });

const page = usePage();
const currencySymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const formatCurrency = (amount: number) => formatCurrencyRaw(amount, currencySymbol.value);

const props = defineProps<{
    roomTypes: Array<{ id: number; name: string; code: string; base_rate: number }>;
    availableRooms: Array<{ id: number; number: string; floor: string; room_type: { name: string } }>;
    prefilledDate: string | null;
}>();

const titleOptions = ['Mr.', 'Mrs.', 'Miss', 'Prof.', ''];
const guestTitle = ref('');

const form = reactive({
    room_type_id: '',
    room_id: '',
    guest_name: '',
    guest_email: '',
    guest_phone: '',
    guest_notes: '',
    adults: 1,
    children: 0,
    check_in_date: props.prefilledDate || new Date().toISOString().split('T')[0],
    check_out_date: '',
    special_requests: [],
    is_group_booking: false,
    group_id: '',
});

const calculateTotal = () => {
    if (! form.check_in_date || ! form.check_out_date) return 0;
    const type = props.roomTypes.find(t => t.id === Number(form.room_type_id));
    if (! type) return 0;
    const nights = Math.ceil(
        (new Date(form.check_out_date).getTime() - new Date(form.check_in_date).getTime()) / (1000 * 60 * 60 * 24)
    );
    return type.base_rate * Math.max(nights, 1);
};

const filteredRooms = computed(() => {
    if (! form.room_type_id) return props.availableRooms;
    return props.availableRooms.filter(r => r.room_type.id === Number(form.room_type_id));
});

const submit = () => {
    const data = { ...form };
    data.guest_name = guestTitle.value ? `${guestTitle.value} ${data.guest_name}`.trim() : data.guest_name;
    router.post('/reservations', data);
};

const goBack = () => {
    window.history.back();
};
</script>

<template>
        <div class="p-6 max-w-4xl mx-auto">
            <div class="mb-6 flex items-center gap-3">
                <Button variant="ghost" size="sm" @click="goBack()" class="gap-1 text-muted-foreground hover:text-foreground">
                    <ArrowLeft class="size-4" /> Back
                </Button>
                <h1 class="text-2xl font-bold text-foreground">New Reservation</h1>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Guest Information -->
                <div class="rounded-lg border border-border bg-card p-6">
                    <h2 class="text-lg font-semibold mb-4 text-foreground">Guest Information</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="guest_name">Guest Name *</Label>
                            <div class="flex gap-2">
                                <Select v-model="guestTitle">
                                    <SelectTrigger class="w-[110px] shrink-0">
                                        <SelectValue placeholder="Title" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="t in titleOptions" :key="t" :value="t">
                                            {{ t || 'None' }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Input id="guest_name" v-model="form.guest_name" type="text" placeholder="Full name" class="flex-1" required />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label for="guest_email">Email</Label>
                            <Input id="guest_email" v-model="form.guest_email" type="email" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="guest_phone">Phone</Label>
                            <Input id="guest_phone" v-model="form.guest_phone" type="tel" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="adults">Adults *</Label>
                            <Input id="adults" v-model.number="form.adults" type="number" min="1" max="10" required />
                        </div>
                        <div class="grid gap-2">
                            <Label for="children">Children</Label>
                            <Input id="children" v-model.number="form.children" type="number" min="0" max="10" />
                        </div>
                    </div>
                    <div class="mt-4 grid gap-2">
                        <Label for="guest_notes">Guest Notes</Label>
                        <textarea
                            id="guest_notes"
                            v-model="form.guest_notes"
                            :rows="2"
                            class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        />
                    </div>
                </div>

                <!-- Stay Details -->
                <div class="rounded-lg border border-border bg-card p-6">
                    <h2 class="text-lg font-semibold mb-4 text-foreground">Stay Details</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label>Room Type *</Label>
                            <Select v-model="form.room_type_id" required>
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="Select room type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="type in roomTypes" :key="type.id" :value="String(type.id)">
                                        {{ type.name }} - {{ formatCurrency(type.base_rate) }}/night
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label>Room *</Label>
                            <Select v-model="form.room_id" required>
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="Select a room" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="room in filteredRooms" :key="room.id" :value="String(room.id)">
                                        Room {{ room.number }} ({{ room.room_type.name }})
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="check_in_date">Check-in Date *</Label>
                            <DatePicker id="check_in_date" v-model="form.check_in_date" required />
                        </div>
                        <div class="grid gap-2">
                            <Label for="check_out_date">Check-out Date *</Label>
                            <DatePicker id="check_out_date" v-model="form.check_out_date" required />
                        </div>
                    </div>
                </div>

                <!-- Total -->
                <div class="rounded-lg border border-border bg-muted/40 p-6">
                    <div class="flex justify-between items-center">
                        <span class="text-lg font-medium text-foreground">Total Amount:</span>
                        <span class="text-2xl font-bold text-foreground">{{ formatCurrency(calculateTotal()) }}</span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-2">
                    <Link href="/reservations">
                        <Button variant="outline">Cancel</Button>
                    </Link>
                    <Button type="submit">
                        Create Reservation
                    </Button>
                </div>
            </form>
        </div>
</template>
