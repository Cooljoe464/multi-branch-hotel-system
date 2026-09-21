<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from '@lucide/vue';
import DatePicker from '@/components/ui/date-picker/DatePicker.vue';
import ConflictDialog from '@/components/ConflictDialog.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Room {
    id: number;
    number: string;
    floor: string;
    room_type: { name: string };
}

interface RoomType {
    id: number;
    name: string;
    code: string;
    base_rate: number;
}

interface Reservation {
    id: number;
    confirmation_number: string;
    guest_name: string;
    guest_email: string | null;
    guest_phone: string | null;
    guest_notes: string | null;
    adults: number;
    children: number;
    check_in_date: string;
    check_out_date: string;
    room_type_id: number;
    room_id: number | null;
    special_requests: string[] | null;
    version: number;
    room: Room | null;
    room_type: RoomType;
}

const props = defineProps<{
    reservation: Reservation;
    roomTypes: RoomType[];
    availableRooms: Room[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Reservations', href: '/reservations' }, { title: 'Edit', href: '/reservations' }] } });

const titleOptions = ['Mr.', 'Mrs.', 'Miss', 'Prof.', ''];

function extractTitle(name: string): { title: string; fullName: string } {
    for (const t of ['Mr.', 'Mrs.', 'Miss', 'Prof.']) {
        if (name.startsWith(t + ' ') || name === t) {
            return { title: t, fullName: name.slice(t.length).trim() };
        }
    }
    return { title: '', fullName: name };
}

const extracted = extractTitle(props.reservation.guest_name);
const guestTitle = ref(extracted.title);

const form = reactive({
    room_type_id: props.reservation.room_type_id,
    room_id: props.reservation.room_id || '',
    guest_name: extracted.fullName,
    guest_email: props.reservation.guest_email || '',
    guest_phone: props.reservation.guest_phone || '',
    guest_notes: props.reservation.guest_notes || '',
    adults: props.reservation.adults,
    children: props.reservation.children,
    check_in_date: props.reservation.check_in_date,
    check_out_date: props.reservation.check_out_date,
    version: props.reservation.version,
});

const filteredRooms = computed(() => {
    if (! form.room_type_id) return props.availableRooms;
    return props.availableRooms.filter(r => r.room_type.id === Number(form.room_type_id));
});

const submit = () => {
    const data = { ...form };
    data.guest_name = guestTitle.value ? `${guestTitle.value} ${data.guest_name}`.trim() : data.guest_name;
    router.put(`/reservations/${props.reservation.id}`, data);
};

const page = usePage();
const conflictOpen = computed(() => Boolean((page.props.errors as Record<string, string>).version));
const conflictMessage = computed(() => (page.props.errors as Record<string, string>).version ?? '');

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
                <h1 class="text-2xl font-bold text-foreground">
                    Edit Reservation {{ reservation.confirmation_number }}
                </h1>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <div class="rounded-lg border border-border bg-card p-6">
                    <h2 class="text-lg font-semibold mb-4 text-foreground">Guest Information</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="guest_name">Guest Name</Label>
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
                            <Label for="adults">Adults</Label>
                            <Input id="adults" v-model.number="form.adults" type="number" min="1" max="10" required />
                        </div>
                        <div class="grid gap-2">
                            <Label for="children">Children</Label>
                            <Input id="children" v-model.number="form.children" type="number" min="0" max="10" />
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-card p-6">
                    <h2 class="text-lg font-semibold mb-4 text-foreground">Stay Details</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label>Room Type</Label>
                            <Select :model-value="String(form.room_type_id)" @update:model-value="form.room_type_id = Number($event)" required>
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="Select room type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="type in roomTypes" :key="type.id" :value="String(type.id)">
                                        {{ type.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label>Room</Label>
                            <Select :model-value="form.room_id === '' ? '' : String(form.room_id)" @update:model-value="form.room_id = $event === '' ? '' : Number($event)">
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="No room" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="room in filteredRooms" :key="room.id" :value="String(room.id)">
                                        Room {{ room.number }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="check_in_date">Check-in Date</Label>
                            <DatePicker id="check_in_date" v-model="form.check_in_date" required />
                        </div>
                        <div class="grid gap-2">
                            <Label for="check_out_date">Check-out Date</Label>
                            <DatePicker id="check_out_date" v-model="form.check_out_date" required />
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <Link :href="`/reservations/${reservation.id}`">
                        <Button variant="outline">Cancel</Button>
                    </Link>
                    <Button type="submit">
                        Save Changes
                    </Button>
                </div>
            </form>

            <ConflictDialog
                :open="conflictOpen"
                :message="conflictMessage"
                @reload="router.reload()"
                @close="conflictOpen = false"
            />
        </div>
</template>
