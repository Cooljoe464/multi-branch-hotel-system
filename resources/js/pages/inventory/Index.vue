<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import Pagination from '@/components/ui/pagination/Pagination.vue';

interface InventoryItem {
    id: number;
    name: string;
    category: string;
    unit: string;
    current_quantity: number;
    reorder_point: number;
    cost_per_unit: number;
    supplier: string | null;
}

const props = defineProps<{
    inventoryItems: {
        data: InventoryItem[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { category?: string; search?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Inventory', href: '/inventory' },
        ],
    },
});

const searchQuery = ref(props.filters.search ?? '');
const selectedCategory = ref(props.filters.category ?? 'all');

let searchTimeout: ReturnType<typeof setTimeout> | null = null;

const applyFilters = () => {
    const params: Record<string, string> = {};
    if (searchQuery.value) params.search = searchQuery.value;
    if (selectedCategory.value && selectedCategory.value !== 'all')
        params.category = selectedCategory.value;
    router.get('/inventory', params, { preserveState: true, replace: true });
};

const onSearchInput = () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 300);
};

const clearFilters = () => {
    searchQuery.value = '';
    selectedCategory.value = 'all';
    router.get('/inventory', {}, { preserveState: true, replace: true });
};

const showModal = ref(false);
const showRestockModal = ref(false);
const selectedItem = ref<InventoryItem | null>(null);
const form = ref({
    name: '',
    category: 'food',
    unit: 'kg',
    current_quantity: '0',
    reorder_point: '10',
    cost_per_unit: '1000',
    supplier: '',
});
const restockForm = ref({ quantity: '', notes: '' });

const categoryBadge: Record<string, string> = {
    food: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
    beverage: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    linen: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
    amenity:
        'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    equipment: 'bg-muted text-muted-foreground',
};

import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(
    () => (page.props.branch?.current as any)?.currency_symbol || '$',
);
const resolveSymbol = (code?: string) =>
    getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatMoney = (cents: number, currencyCode?: string) =>
    formatCurrency(cents, resolveSymbol(currencyCode));

const submit = () => {
    router.post(
        '/inventory',
        {
            ...form.value,
            current_quantity: parseFloat(form.value.current_quantity) || 0,
            reorder_point: parseFloat(form.value.reorder_point) || 0,
            cost_per_unit: parseInt(form.value.cost_per_unit) || 0,
        },
        {
            onSuccess: () => {
                showModal.value = false;
                form.value = {
                    name: '',
                    category: 'food',
                    unit: 'kg',
                    current_quantity: '0',
                    reorder_point: '10',
                    cost_per_unit: '1000',
                    supplier: '',
                };
            },
        },
    );
};

const openRestock = (item: InventoryItem) => {
    selectedItem.value = item;
    restockForm.value = { quantity: '', notes: '' };
    showRestockModal.value = true;
};

const submitRestock = () => {
    if (!selectedItem.value) return;
    router.post(
        `/inventory/${selectedItem.value.id}/restock`,
        {
            quantity: parseFloat(restockForm.value.quantity) || 0,
            notes: restockForm.value.notes,
        },
        {
            onSuccess: () => {
                showRestockModal.value = false;
                selectedItem.value = null;
            },
        },
    );
};

const deleteItem = (item: InventoryItem) => {
    if (confirm(`Delete ${item.name}?`)) router.delete(`/inventory/${item.id}`);
};
const goToPage = (page: number) =>
    router.get('/inventory', { page }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Inventory" />
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-foreground text-2xl font-bold">Inventory</h1>
            <Button @click="showModal = true">Add Item</Button>
        </div>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <Input
                v-model="searchQuery"
                placeholder="Search items or suppliers..."
                class="w-full sm:w-64"
                @input="onSearchInput"
            />
            <Select
                v-model="selectedCategory"
                @update:model-value="applyFilters"
            >
                <SelectTrigger class="w-full sm:w-[180px]">
                    <SelectValue placeholder="All Categories" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All Categories</SelectItem>
                    <SelectItem value="food">Food</SelectItem>
                    <SelectItem value="beverage">Beverage</SelectItem>
                    <SelectItem value="linen">Linen</SelectItem>
                    <SelectItem value="amenity">Amenity</SelectItem>
                    <SelectItem value="equipment">Equipment</SelectItem>
                </SelectContent>
            </Select>
            <Button
                v-if="searchQuery || selectedCategory"
                variant="ghost"
                size="sm"
                @click="clearFilters"
                >Clear</Button
            >
        </div>
        <div class="border-border rounded-lg border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Name
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Category
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Quantity
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Reorder
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-left font-medium"
                        >
                            Cost
                        </th>
                        <th
                            class="text-muted-foreground h-12 px-4 text-right font-medium"
                        >
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in inventoryItems.data"
                        :key="item.id"
                        class="border-border hover:bg-muted/50 border-t"
                    >
                        <td class="text-foreground p-4 font-medium">
                            {{ item.name }}
                        </td>
                        <td class="p-4">
                            <Badge
                                :class="categoryBadge[item.category]"
                                variant="outline"
                                class="capitalize"
                                >{{ item.category }}</Badge
                            >
                        </td>
                        <td
                            class="text-foreground p-4"
                            :class="{
                                'text-red-600 dark:text-red-400':
                                    item.current_quantity <= item.reorder_point,
                            }"
                        >
                            {{ item.current_quantity }} {{ item.unit }}
                        </td>
                        <td class="text-muted-foreground p-4">
                            {{ item.reorder_point }} {{ item.unit }}
                        </td>
                        <td class="text-foreground p-4">
                            {{ formatMoney(item.cost_per_unit) }}
                        </td>
                        <td class="p-4 text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="openRestock(item)"
                                    >Restock</Button
                                ><Button
                                    variant="destructive"
                                    size="sm"
                                    @click="deleteItem(item)"
                                    >×</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="inventoryItems.data.length === 0">
                        <td
                            colspan="6"
                            class="text-muted-foreground p-4 text-center"
                        >
                            No items found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            <Pagination
                :data="inventoryItems"
                label="items"
                @page-change="goToPage"
            />
        </div>

        <Dialog :open="showModal" @update:open="showModal = $event">
            <DialogContent class="max-w-lg">
                <DialogHeader
                    ><DialogTitle>Add Inventory Item</DialogTitle></DialogHeader
                >
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid gap-2">
                        <Label>Name</Label
                        ><Input v-model="form.name" required />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label>Category</Label
                            ><Select v-model="form.category"
                                ><SelectTrigger><SelectValue /></SelectTrigger
                                ><SelectContent
                                    ><SelectItem value="food">Food</SelectItem
                                    ><SelectItem value="beverage"
                                        >Beverage</SelectItem
                                    ><SelectItem value="linen">Linen</SelectItem
                                    ><SelectItem value="amenity"
                                        >Amenity</SelectItem
                                    ><SelectItem value="equipment"
                                        >Equipment</SelectItem
                                    ></SelectContent
                                ></Select
                            >
                        </div>
                        <div class="grid gap-2">
                            <Label>Unit</Label
                            ><Select v-model="form.unit"
                                ><SelectTrigger><SelectValue /></SelectTrigger
                                ><SelectContent
                                    ><SelectItem value="kg">Kg</SelectItem
                                    ><SelectItem value="litre">Litre</SelectItem
                                    ><SelectItem value="piece"
                                        >Piece</SelectItem
                                    ></SelectContent
                                ></Select
                            >
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div class="grid gap-2">
                            <Label>Quantity</Label
                            ><Input
                                v-model="form.current_quantity"
                                type="number"
                                step="0.01"
                                min="0"
                                required
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>Reorder Point</Label
                            ><Input
                                v-model="form.reorder_point"
                                type="number"
                                step="0.01"
                                min="0"
                                required
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>Cost (cents)</Label
                            ><Input
                                v-model="form.cost_per_unit"
                                type="number"
                                min="0"
                                required
                            />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label>Supplier</Label><Input v-model="form.supplier" />
                    </div>
                    <DialogFooter
                        ><div class="flex justify-end gap-2">
                            <Button
                                variant="outline"
                                type="button"
                                @click="showModal = false"
                                >Cancel</Button
                            ><Button type="submit">Create</Button>
                        </div></DialogFooter
                    >
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="showRestockModal"
            @update:open="showRestockModal = $event"
        >
            <DialogContent>
                <DialogHeader
                    ><DialogTitle
                        >Restock {{ selectedItem?.name }}</DialogTitle
                    ></DialogHeader
                >
                <form @submit.prevent="submitRestock" class="space-y-4">
                    <div class="grid gap-2">
                        <Label>Quantity</Label
                        ><Input
                            v-model="restockForm.quantity"
                            type="number"
                            step="0.01"
                            min="0.01"
                            required
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Notes</Label
                        ><Input v-model="restockForm.notes" />
                    </div>
                    <DialogFooter
                        ><div class="flex justify-end gap-2">
                            <Button
                                variant="outline"
                                type="button"
                                @click="showRestockModal = false"
                                >Cancel</Button
                            ><Button type="submit">Restock</Button>
                        </div></DialogFooter
                    >
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
