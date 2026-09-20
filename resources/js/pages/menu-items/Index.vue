<script setup lang="ts">
import { ref, computed } from 'vue';
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

interface MenuItem {
    id: number;
    category: string;
    name: string;
    description: string | null;
    price: number;
    is_available: boolean;
    is_active: boolean;
    dietary_flags: string[] | null;
    sort_order: number;
}

const props = defineProps<{
    menuItems: {
        data: MenuItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        category?: string;
        available?: string;
    };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Menu Items', href: '/menu-items' }] } });

const showCreateModal = ref(false);
const selectedItem = ref<MenuItem | null>(null);
const showEditModal = ref(false);

const form = ref({
    category: '',
    name: '',
    description: '',
    price: '',
    dietary_flags: [] as string[],
    sort_order: '0',
});

const editForm = ref({
    name: '',
    description: '',
    price: '',
    is_available: true,
    is_active: true,
    dietary_flags: [] as string[],
    sort_order: '0',
});

const filterForm = ref({
    category: props.filters.category || '',
    available: props.filters.available || '',
});

const categoryBadge: Record<string, string> = {
    food: 'bg-orange-100 text-orange-800 border-orange-300 dark:bg-orange-900 dark:text-orange-300 dark:border-orange-700',
    drink: 'bg-blue-100 text-blue-800 border-blue-300 dark:bg-blue-900 dark:text-blue-300 dark:border-blue-700',
    laundry: 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900 dark:text-purple-300 dark:border-purple-700',
    service: 'bg-teal-100 text-teal-800 border-teal-300 dark:bg-teal-900 dark:text-teal-300 dark:border-teal-700',
};

import { formatCurrency, getCurrencySymbol } from '@/lib/format';
const page = usePage();
const branchSymbol = computed(() => (page.props.branch?.current as any)?.currency_symbol || '$');
const resolveSymbol = (code?: string) => getCurrencySymbol(code || 'NGN') || branchSymbol.value;
const formatPrice = (cents: number, currencyCode?: string) => formatCurrency(cents, resolveSymbol(currencyCode));

const submitCreate = () => {
    router.post('/menu-items', {
        ...form.value,
        price: parseInt(form.value.price) || 0,
        sort_order: parseInt(form.value.sort_order) || 0,
    }, {
        onSuccess: () => {
            showCreateModal.value = false;
            form.value = { category: '', name: '', description: '', price: '', dietary_flags: [], sort_order: '0' };
        },
    });
};

const openEdit = (item: MenuItem) => {
    selectedItem.value = item;
    editForm.value = {
        name: item.name,
        description: item.description || '',
        price: String(item.price),
        is_available: item.is_available,
        is_active: item.is_active,
        dietary_flags: item.dietary_flags || [],
        sort_order: String(item.sort_order),
    };
    showEditModal.value = true;
};

const submitEdit = () => {
    if (!selectedItem.value) return;
    router.put(`/menu-items/${selectedItem.value.id}`, {
        ...editForm.value,
        price: parseInt(editForm.value.price) || 0,
        sort_order: parseInt(editForm.value.sort_order) || 0,
    }, {
        onSuccess: () => {
            showEditModal.value = false;
            selectedItem.value = null;
        },
    });
};

const deleteItem = (item: MenuItem) => {
    if (confirm(`Delete ${item.name}?`)) {
        router.delete(`/menu-items/${item.id}`);
    }
};

const applyFilters = () => {
    router.get('/menu-items', filterForm.value, { preserveState: true, replace: true });
};

const goToPage = (page: number) => {
    router.get('/menu-items', { ...filterForm.value, page }, { preserveState: true, replace: true });
};
</script>

<template>
    <Head title="Menu Items" />
    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-bold text-foreground">Menu Items</h1>
            <Button @click="showCreateModal = true">Add Menu Item</Button>
        </div>

        <div class="mb-4 flex flex-col gap-4 sm:flex-row">
            <Select v-model="filterForm.category" class="sm:max-w-xs" aria-label="Filter by category" @update:model-value="applyFilters">
                <SelectTrigger>
                    <SelectValue placeholder="All Categories" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="food">Food</SelectItem>
                    <SelectItem value="drink">Drink</SelectItem>
                    <SelectItem value="laundry">Laundry</SelectItem>
                    <SelectItem value="service">Service</SelectItem>
                </SelectContent>
            </Select>
            <Select v-model="filterForm.available" class="sm:max-w-xs" aria-label="Filter by availability" @update:model-value="applyFilters">
                <SelectTrigger>
                    <SelectValue placeholder="All Availability" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="1">Available</SelectItem>
                    <SelectItem value="0">Out of Stock</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="rounded-lg border border-border">
            <table class="w-full caption-bottom text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="h-12 px-4 text-left font-medium text-muted-foreground">Name</th>
                        <th class="h-12 px-4 text-left font-medium text-muted-foreground">Category</th>
                        <th class="h-12 px-4 text-left font-medium text-muted-foreground">Price</th>
                        <th class="h-12 px-4 text-left font-medium text-muted-foreground">Available</th>
                        <th class="h-12 px-4 text-left font-medium text-muted-foreground">Active</th>
                        <th class="h-12 px-4 text-right font-medium text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in menuItems.data" :key="item.id" class="border-t border-border hover:bg-muted/50">
                        <td class="p-4 font-medium text-foreground">{{ item.name }}</td>
                        <td class="p-4">
                            <Badge :class="categoryBadge[item.category]" variant="outline" class="capitalize">{{ item.category }}</Badge>
                        </td>
                        <td class="p-4 text-foreground">{{ formatPrice(item.price) }}</td>
                        <td class="p-4">
                            <Badge :class="item.is_available ? 'bg-green-100 text-green-800 border-green-300 dark:bg-green-900 dark:text-green-300 dark:border-green-700' : 'bg-red-100 text-red-800 border-red-300 dark:bg-red-900 dark:text-red-300 dark:border-red-700'" variant="outline">
                                {{ item.is_available ? 'In Stock' : 'Out of Stock' }}
                            </Badge>
                        </td>
                        <td class="p-4">
                            <Badge :class="item.is_active ? 'bg-green-100 text-green-800 border-green-300 dark:bg-green-900 dark:text-green-300 dark:border-green-700' : 'bg-muted text-muted-foreground border-border'" variant="outline">
                                {{ item.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </td>
                        <td class="p-4 text-right">
                            <div class="flex justify-end gap-1">
                                <Button variant="outline" size="sm" @click="openEdit(item)">Edit</Button>
                                <Button variant="destructive" size="sm" aria-label="Delete menu item" @click="deleteItem(item)">×</Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="menuItems.data.length === 0">
                        <td colspan="6" class="p-4 text-center text-muted-foreground">No menu items found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <Pagination :data="menuItems" label="menu items" @page-change="goToPage" />
        </div>

        <!-- Create Modal -->
        <Dialog :open="showCreateModal" @update:open="showCreateModal = $event">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add Menu Item</DialogTitle>
                </DialogHeader>
                <form id="create-menu-form" @submit.prevent="submitCreate">
                    <div class="space-y-4">
                        <div class="grid gap-2">
                            <Label>Category</Label>
                            <Select v-model="form.category" aria-label="Category">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select category" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="food">Food</SelectItem>
                                    <SelectItem value="drink">Drink</SelectItem>
                                    <SelectItem value="laundry">Laundry</SelectItem>
                                    <SelectItem value="service">Service</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="item-name">Name</Label>
                            <Input id="item-name" v-model="form.name" type="text" required />
                        </div>
                        <div class="grid gap-2">
                            <Label for="item-desc">Description</Label>
                            <Input id="item-desc" v-model="form.description" type="text" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="grid gap-2">
                                <Label for="item-price">Price (cents)</Label>
                                <Input id="item-price" v-model="form.price" type="number" min="0" required />
                            </div>
                            <div class="grid gap-2">
                                <Label for="item-sort">Sort Order</Label>
                                <Input id="item-sort" v-model="form.sort_order" type="number" min="0" />
                            </div>
                        </div>
                    </div>
                </form>
                <DialogFooter>
                    <div class="flex justify-end gap-2">
                        <Button variant="outline" @click="showCreateModal = false">Cancel</Button>
                        <Button type="submit" form="create-menu-form">Create</Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Edit Modal -->
        <Dialog :open="showEditModal" @update:open="showEditModal = $event">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Edit {{ selectedItem?.name }}</DialogTitle>
                </DialogHeader>
                <form id="edit-menu-form" @submit.prevent="submitEdit">
                    <div class="space-y-4">
                        <div class="grid gap-2">
                            <Label for="edit-name">Name</Label>
                            <Input id="edit-name" v-model="editForm.name" type="text" required />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-desc">Description</Label>
                            <Input id="edit-desc" v-model="editForm.description" type="text" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="grid gap-2">
                                <Label for="edit-price">Price (cents)</Label>
                                <Input id="edit-price" v-model="editForm.price" type="number" min="0" required />
                            </div>
                            <div class="grid gap-2">
                                <Label for="edit-sort">Sort Order</Label>
                                <Input id="edit-sort" v-model="editForm.sort_order" type="number" min="0" />
                            </div>
                        </div>
                        <div class="flex gap-4">
                            <label class="flex items-center gap-2">
                                <input v-model="editForm.is_available" type="checkbox" class="rounded" />
                                <span class="text-sm text-foreground">Available</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input v-model="editForm.is_active" type="checkbox" class="rounded" />
                                <span class="text-sm text-foreground">Active</span>
                            </label>
                        </div>
                    </div>
                </form>
                <DialogFooter>
                    <div class="flex justify-end gap-2">
                        <Button variant="outline" @click="showEditModal = false">Cancel</Button>
                        <Button type="submit" form="edit-menu-form">Save</Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
