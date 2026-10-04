<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Plus, Pencil, Trash2, Loader2, Users, Search } from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Administration', href: '/admin/users' },
            { title: 'Users', href: '/admin/users' },
        ],
    },
});

interface User {
    id: number;
    name: string;
    email: string;
    is_global_admin: boolean;
    roles: string[];
    current_branch: { id: number; name: string } | null;
    branch_ids: number[];
    default_branch_id: number | null;
    last_login_at: string | null;
}

const props = defineProps<{
    users: User[];
    roles: Record<number, string>;
    branches: Array<{ id: number; name: string }>;
}>();

const search = ref('');
const dialogOpen = ref(false);
const editingUser = ref<User | null>(null);
const saving = ref(false);
const deletingId = ref<number | null>(null);

const form = ref({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    is_global_admin: false,
    selectedRoles: [] as string[],
    branchIds: [] as number[],
    defaultBranchId: null as number | null,
});

const filteredUsers = computed(() => {
    if (!search.value) return props.users;
    const q = search.value.toLowerCase();
    return props.users.filter(
        (u) =>
            u.name.toLowerCase().includes(q) ||
            u.email.toLowerCase().includes(q),
    );
});

function openCreate() {
    editingUser.value = null;
    form.value = {
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        is_global_admin: false,
        selectedRoles: [],
        branchIds: [],
        defaultBranchId: null,
    };
    dialogOpen.value = true;
}

function openEdit(user: User) {
    editingUser.value = user;
    form.value = {
        name: user.name,
        email: user.email,
        password: '',
        password_confirmation: '',
        is_global_admin: user.is_global_admin,
        selectedRoles: [...user.roles],
        branchIds: [...user.branch_ids],
        defaultBranchId: user.default_branch_id,
    };
    dialogOpen.value = true;
}

function toggleRole(roleName: string) {
    const idx = form.value.selectedRoles.indexOf(roleName);
    if (idx >= 0) {
        form.value.selectedRoles.splice(idx, 1);
    } else {
        form.value.selectedRoles.push(roleName);
    }
}

function toggleBranch(branchId: number) {
    const idx = form.value.branchIds.indexOf(branchId);
    if (idx >= 0) {
        form.value.branchIds.splice(idx, 1);
        if (form.value.defaultBranchId === branchId) {
            form.value.defaultBranchId = form.value.branchIds[0] ?? null;
        }
    } else {
        form.value.branchIds.push(branchId);
        if (!form.value.defaultBranchId) {
            form.value.defaultBranchId = branchId;
        }
    }
}

function submitForm() {
    saving.value = true;
    const data = {
        ...form.value,
        password: form.value.password || undefined,
        password_confirmation: form.value.password || undefined,
    };

    if (editingUser.value) {
        router.put(`/admin/users/${editingUser.value.id}`, data, {
            onFinish: () => {
                saving.value = false;
                dialogOpen.value = false;
            },
        });
    } else {
        router.post('/admin/users', data, {
            onFinish: () => {
                saving.value = false;
                dialogOpen.value = false;
            },
        });
    }
}

function deleteUser(user: User) {
    if (!confirm(`Are you sure you want to delete "${user.name}"?`)) return;
    deletingId.value = user.id;
    router.delete(`/admin/users/${user.id}`, {
        onFinish: () => {
            deletingId.value = null;
        },
    });
}

function formatDate(dateStr: string | null): string {
    if (!dateStr) return 'Never';
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}
</script>

<template>
    <Head title="User Management" />

    <div class="mx-auto max-w-6xl space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-foreground text-2xl font-bold">
                    User Management
                </h1>
                <p class="text-muted-foreground mt-1">
                    Manage staff accounts and their roles
                </p>
            </div>
            <Button @click="openCreate">
                <Plus class="mr-2 h-4 w-4" />
                Create User
            </Button>
        </div>

        <!-- Search -->
        <div class="relative max-w-md">
            <Search
                class="text-muted-foreground absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2"
            />
            <Input
                v-model="search"
                placeholder="Search users..."
                class="pl-9"
            />
        </div>

        <!-- User Table -->
        <Card>
            <CardContent class="p-0">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-muted/50">
                            <tr class="border-border border-b">
                                <th
                                    class="text-muted-foreground px-4 py-3 text-left text-xs font-medium uppercase"
                                >
                                    User
                                </th>
                                <th
                                    class="text-muted-foreground px-4 py-3 text-left text-xs font-medium uppercase"
                                >
                                    Role(s)
                                </th>
                                <th
                                    class="text-muted-foreground px-4 py-3 text-left text-xs font-medium uppercase"
                                >
                                    Branch
                                </th>
                                <th
                                    class="text-muted-foreground px-4 py-3 text-left text-xs font-medium uppercase"
                                >
                                    Last Login
                                </th>
                                <th
                                    class="text-muted-foreground px-4 py-3 text-right text-xs font-medium uppercase"
                                >
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-border divide-y">
                            <tr
                                v-for="user in filteredUsers"
                                :key="user.id"
                                class="hover:bg-muted/50 transition-colors"
                            >
                                <td class="px-4 py-3">
                                    <div>
                                        <div
                                            class="text-foreground font-medium"
                                        >
                                            {{ user.name }}
                                        </div>
                                        <div
                                            class="text-muted-foreground text-sm"
                                        >
                                            {{ user.email }}
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <Badge
                                            v-for="role in user.roles"
                                            :key="role"
                                            variant="secondary"
                                            class="text-xs"
                                        >
                                            {{ role }}
                                        </Badge>
                                        <Badge
                                            v-if="user.is_global_admin"
                                            variant="default"
                                            class="text-xs"
                                        >
                                            Global Admin
                                        </Badge>
                                    </div>
                                </td>
                                <td
                                    class="text-muted-foreground px-4 py-3 text-sm"
                                >
                                    {{ user.current_branch?.name ?? '—' }}
                                </td>
                                <td
                                    class="text-muted-foreground px-4 py-3 text-sm"
                                >
                                    {{ formatDate(user.last_login_at) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            @click="openEdit(user)"
                                        >
                                            <Pencil class="h-3.5 w-3.5" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            :disabled="deletingId === user.id"
                                            @click="deleteUser(user)"
                                        >
                                            <Loader2
                                                v-if="deletingId === user.id"
                                                class="h-3.5 w-3.5 animate-spin"
                                            />
                                            <Trash2
                                                v-else
                                                class="text-destructive h-3.5 w-3.5"
                                            />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="filteredUsers.length === 0">
                                <td
                                    colspan="5"
                                    class="text-muted-foreground px-4 py-12 text-center"
                                >
                                    No users found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <!-- Create/Edit Dialog -->
        <Dialog :open="dialogOpen" @update:open="dialogOpen = $event">
            <DialogContent class="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{
                        editingUser ? 'Edit User' : 'Create User'
                    }}</DialogTitle>
                </DialogHeader>

                <div class="space-y-6">
                    <!-- Basic Info -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label>Name *</Label>
                            <Input
                                v-model="form.name"
                                placeholder="Full name"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>Email *</Label>
                            <Input
                                v-model="form.email"
                                type="email"
                                placeholder="email@example.com"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{
                                editingUser
                                    ? 'New Password (leave blank to keep)'
                                    : 'Password *'
                            }}</Label>
                            <Input
                                v-model="form.password"
                                type="password"
                                :required="!editingUser"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>Confirm Password</Label>
                            <Input
                                v-model="form.password_confirmation"
                                type="password"
                            />
                        </div>
                    </div>

                    <!-- Roles -->
                    <div class="space-y-3">
                        <Label>Role(s) *</Label>
                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="(roleName, roleId) in roles"
                                :key="roleId"
                                class="border-border hover:bg-accent flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors"
                                :class="{
                                    'bg-primary text-primary-foreground border-primary':
                                        form.selectedRoles.includes(roleName),
                                }"
                            >
                                <Checkbox
                                    :checked="
                                        form.selectedRoles.includes(roleName)
                                    "
                                    @update:checked="toggleRole(roleName)"
                                />
                                {{ roleName }}
                            </label>
                        </div>
                    </div>

                    <!-- Branch Access -->
                    <div class="space-y-3">
                        <Label>Branch Access *</Label>
                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="branch in branches"
                                :key="branch.id"
                                class="border-border hover:bg-accent flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors"
                                :class="{
                                    'bg-primary text-primary-foreground border-primary':
                                        form.branchIds.includes(branch.id),
                                }"
                            >
                                <Checkbox
                                    :checked="
                                        form.branchIds.includes(branch.id)
                                    "
                                    @update:checked="toggleBranch(branch.id)"
                                />
                                {{ branch.name }}
                            </label>
                        </div>
                    </div>

                    <!-- Default Branch -->
                    <div v-if="form.branchIds.length > 0" class="grid gap-2">
                        <Label>Default Branch *</Label>
                        <Select v-model="form.defaultBranchId">
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    placeholder="Select default branch"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="branchId in form.branchIds"
                                    :key="branchId"
                                    :value="String(branchId)"
                                >
                                    {{
                                        branches.find((b) => b.id === branchId)
                                            ?.name
                                    }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <!-- Global Admin Toggle -->
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model:checked="form.is_global_admin" />
                        <span class="text-muted-foreground"
                            >Global Admin (full system access)</span
                        >
                    </label>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="dialogOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        :disabled="
                            saving ||
                            !form.name ||
                            !form.email ||
                            form.selectedRoles.length === 0 ||
                            form.branchIds.length === 0
                        "
                        @click="submitForm"
                    >
                        <Loader2
                            v-if="saving"
                            class="mr-2 h-4 w-4 animate-spin"
                        />
                        {{ editingUser ? 'Save Changes' : 'Create User' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
