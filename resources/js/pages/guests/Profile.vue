<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Guest {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    phone: string | null;
    total_stays: number;
    total_nights: number;
    total_spent: number;
    vip_status: string;
}

interface Document {
    id: number;
    doc_type: string;
    masked_number: string;
    ocr_status: string;
    has_scan: boolean;
    created_at: string;
}

interface Candidate {
    id: number;
    name: string;
    email: string;
    stays: number;
}

interface Dnr {
    id: number;
    reason: string;
    branch_id: number | null;
}

const props = defineProps<{
    guest: Guest;
    documents: Document[];
    candidates: Candidate[];
    dnr: Dnr[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Guests', href: '#' },
            { title: 'Profile', href: '#' },
        ],
    },
});

const mergeForm = useForm({
    surviving_guest_id: props.guest.id,
    retired_guest_id: null as number | null,
    field_choices: {} as Record<string, string>,
});
const idForm = useForm({
    doc_type: 'passport',
    doc_number: '',
    scan: null as File | null,
});
const revealed = ref<Record<number, string>>({});

function reveal(id: number) {
    fetch(`/identity-documents/${id}/reveal`).then(async (r) => {
        if (r.ok) {
            const data = (await r.json()) as { number: string };
            revealed.value = { ...revealed.value, [id]: data.number };
        }
    });
}
</script>

<template>
    <Head :title="`Guest — ${guest.email}`" />
    <div class="space-y-8 p-6">
        <div>
            <h1 class="text-2xl font-semibold">
                {{ guest.first_name }} {{ guest.last_name }}
            </h1>
            <p class="text-muted-foreground text-sm">
                {{ guest.email }} · {{ guest.total_stays }} stays ·
                {{ guest.vip_status }}
            </p>
        </div>

        <div
            v-if="dnr.length"
            class="rounded-lg border border-red-400 bg-red-50 p-4 text-sm"
        >
            <strong>Do-not-rent listed:</strong> {{ dnr[0]?.reason }}
        </div>

        <section v-if="candidates.length" class="space-y-3">
            <h2 class="text-lg font-medium">
                Possible duplicates ({{ candidates.length }})
            </h2>
            <form
                @submit.prevent="mergeForm.post('/guests/merges')"
                class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
            >
                <div class="grid gap-2">
                    <Label>Retire into this profile</Label>
                    <select
                        v-model="mergeForm.retired_guest_id"
                        class="rounded-md border p-2 text-sm"
                        required
                    >
                        <option :value="null" disabled>Select duplicate</option>
                        <option
                            v-for="c in candidates"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ c.name }} — {{ c.email }} ({{ c.stays }} stays)
                        </option>
                    </select>
                </div>
                <Button type="submit">Merge profiles</Button>
            </form>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Identity documents</h2>
            <form
                @submit.prevent="
                    idForm.post(`/guests/${guest.id}/identity-documents`, {
                        onSuccess: () => idForm.reset(),
                    })
                "
                class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
            >
                <div class="grid gap-2">
                    <Label>Type</Label>
                    <select
                        v-model="idForm.doc_type"
                        class="rounded-md border p-2 text-sm"
                    >
                        <option value="passport">Passport</option>
                        <option value="nin">NIN</option>
                        <option value="drivers">Driver's licence</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>Number</Label><Input v-model="idForm.doc_number" />
                </div>
                <div class="grid gap-2">
                    <Label>Scan</Label
                    ><Input
                        type="file"
                        @change="
                            (e: Event) => {
                                idForm.scan = ((e.target as HTMLInputElement)
                                    .files?.[0] ?? null) as File | null;
                            }
                        "
                    />
                </div>
                <Button type="submit">Capture</Button>
            </form>
            <div class="rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground border-b text-left">
                            <th class="p-3">Type</th>
                            <th class="p-3">Number</th>
                            <th class="p-3">OCR</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="doc in documents"
                            :key="doc.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ doc.doc_type }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ revealed[doc.id] ?? doc.masked_number }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ doc.ocr_status }}
                            </td>
                            <td class="p-3 text-right">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="reveal(doc.id)"
                                        >Reveal</Button
                                    >
                                    <a
                                        v-if="doc.has_scan"
                                        :href="`/identity-documents/${doc.id}/download`"
                                        ><Button variant="outline" size="sm"
                                            >Scan</Button
                                        ></a
                                    >
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
