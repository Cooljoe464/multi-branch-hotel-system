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
    Shield,
    Plus,
    Trash2,
    Pencil,
    Loader2,
    ChevronDown,
    ChevronRight,
} from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Administration', href: '/admin/roles' },
            { title: 'Roles & Permissions', href: '/admin/roles' },
        ],
    },
});

interface Role {
    id: number;
    name: string;
    permissions: string[];
    users_count: number;
    is_system: boolean;
}

interface PermissionGroup {
    name: string;
    group: string;
    action: string;
}

const props = defineProps<{
    roles: Role[];
    permissions: Record<string, PermissionGroup[]>;
}>();

const createForm = ref({ name: '' });
const createOpen = ref(false);
const editRole = ref<Role | null>(null);
const editOpen = ref(false);
const editPermissions = ref<string[]>([]);
const expandedGroups = ref<Set<string>>(
    new Set(Object.keys(props.permissions)),
);
const creating = ref(false);
const saving = ref(false);
const deletingId = ref<number | null>(null);

const groupedPermissions = computed(() => {
    return Object.entries(props.permissions).map(([group, perms]) => ({
        group,
        permissions: perms,
    }));
});

function allGroupPermissions(group: string): string[] {
    return (props.permissions[group] ?? []).map((p) => p.name);
}

function isGroupFullyChecked(group: string): boolean {
    const perms = allGroupPermissions(group);
    return (
        perms.length > 0 &&
        perms.every((p) => editPermissions.value.includes(p))
    );
}

function isGroupPartiallyChecked(group: string): boolean {
    const perms = allGroupPermissions(group);
    const checked = perms.filter((p) => editPermissions.value.includes(p));
    return checked.length > 0 && checked.length < perms.length;
}

function toggleGroupAll(group: string) {
    const perms = allGroupPermissions(group);
    if (isGroupFullyChecked(group)) {
        editPermissions.value = editPermissions.value.filter(
            (p) => !perms.includes(p),
        );
    } else {
        const existing = new Set(editPermissions.value);
        perms.forEach((p) => existing.add(p));
        editPermissions.value = Array.from(existing);
    }
}

function togglePermission(perm: string) {
    const idx = editPermissions.value.indexOf(perm);
    if (idx >= 0) {
        editPermissions.value.splice(idx, 1);
    } else {
        editPermissions.value.push(perm);
    }
}

function toggleExpandGroup(group: string) {
    if (expandedGroups.value.has(group)) {
        expandedGroups.value.delete(group);
    } else {
        expandedGroups.value.add(group);
    }
}

function openCreate() {
    createForm.value = { name: '' };
    createOpen.value = true;
}

function submitCreate() {
    if (!createForm.value.name.trim()) return;
    creating.value = true;
    router.post(
        '/admin/roles',
        {
            name: createForm.value.name,
            permissions: [],
        },
        {
            onFinish: () => {
                creating.value = false;
                createOpen.value = false;
            },
        },
    );
}

function openEdit(role: Role) {
    editRole.value = role;
    editPermissions.value = [...role.permissions];
    editOpen.value = true;
}

function submitEdit() {
    if (!editRole.value) return;
    saving.value = true;
    router.put(
        `/admin/roles/${editRole.value.id}`,
        {
            permissions: editPermissions.value,
        },
        {
            onFinish: () => {
                saving.value = false;
                editOpen.value = false;
                editRole.value = null;
            },
        },
    );
}

function deleteRole(role: Role) {
    if (!confirm(`Are you sure you want to delete the "${role.name}" role?`))
        return;
    deletingId.value = role.id;
    router.delete(`/admin/roles/${role.id}`, {
        onFinish: () => {
            deletingId.value = null;
        },
    });
}
</script>

<template>
    <Head title="Roles & Permissions" />

    <div class="mx-auto max-w-6xl space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-foreground text-2xl font-bold">
                    Roles & Permissions
                </h1>
                <p class="text-muted-foreground mt-1">
                    Manage roles and their permissions
                </p>
            </div>
            <Button @click="openCreate">
                <Plus class="mr-2 h-4 w-4" />
                Create Role
            </Button>
        </div>

        <!-- Role Cards -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Card v-for="role in roles" :key="role.id" class="relative">
                <CardHeader class="pb-3">
                    <div class="flex items-center justify-between">
                        <CardTitle class="flex items-center gap-2 text-lg">
                            <Shield class="text-muted-foreground h-5 w-5" />
                            {{ role.name }}
                        </CardTitle>
                        <Badge v-if="role.is_system" variant="secondary"
                            >System</Badge
                        >
                    </div>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-muted-foreground">Permissions</span>
                        <span class="text-foreground font-medium">
                            {{
                                role.is_system ? 'All' : role.permissions.length
                            }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-muted-foreground">Users</span>
                        <span class="text-foreground font-medium">{{
                            role.users_count
                        }}</span>
                    </div>
                    <div
                        class="border-border flex items-center gap-2 border-t pt-2"
                    >
                        <Button
                            v-if="!role.is_system"
                            variant="outline"
                            size="sm"
                            class="flex-1"
                            @click="openEdit(role)"
                        >
                            <Pencil class="mr-1 h-3.5 w-3.5" />
                            Edit Permissions
                        </Button>
                        <Button
                            v-if="role.is_system"
                            variant="outline"
                            size="sm"
                            class="flex-1"
                            disabled
                        >
                            All Permissions
                        </Button>
                        <Button
                            v-if="!role.is_system && role.users_count === 0"
                            variant="outline"
                            size="sm"
                            :disabled="deletingId === role.id"
                            @click="deleteRole(role)"
                        >
                            <Loader2
                                v-if="deletingId === role.id"
                                class="h-3.5 w-3.5 animate-spin"
                            />
                            <Trash2 v-else class="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Edit Permissions Dialog -->
        <Dialog :open="editOpen" @update:open="editOpen = $event">
            <DialogContent class="max-h-[85vh] max-w-3xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        Edit Permissions — {{ editRole?.name }}
                    </DialogTitle>
                </DialogHeader>

                <div class="space-y-4 py-2">
                    <div
                        v-for="{
                            group,
                            permissions: perms,
                        } in groupedPermissions"
                        :key="group"
                        class="border-border rounded-lg border p-4"
                    >
                        <div class="mb-3 flex items-center gap-3">
                            <Checkbox
                                :checked="isGroupFullyChecked(group)"
                                :model-value="
                                    isGroupPartiallyChecked(group)
                                        ? 'indeterminate'
                                        : undefined
                                "
                                @update:checked="toggleGroupAll(group)"
                            />
                            <button
                                class="text-foreground hover:text-primary flex items-center gap-1 text-sm font-semibold capitalize transition-colors"
                                @click="toggleExpandGroup(group)"
                            >
                                <ChevronRight
                                    class="h-4 w-4 transition-transform"
                                    :class="{
                                        'rotate-90': expandedGroups.has(group),
                                    }"
                                />
                                {{ group.replace('_', ' ') }}
                            </button>
                            <Badge variant="outline" class="text-xs">
                                {{
                                    perms.filter((p) =>
                                        editPermissions.includes(p.name),
                                    ).length
                                }}/{{ perms.length }}
                            </Badge>
                        </div>
                        <div
                            v-if="expandedGroups.has(group)"
                            class="grid grid-cols-2 gap-2 pl-7 sm:grid-cols-3 md:grid-cols-4"
                        >
                            <label
                                v-for="perm in perms"
                                :key="perm.name"
                                class="text-muted-foreground hover:text-foreground flex cursor-pointer items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    :checked="
                                        editPermissions.includes(perm.name)
                                    "
                                    @update:checked="
                                        togglePermission(perm.name)
                                    "
                                />
                                {{ perm.action }}
                            </label>
                        </div>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="editOpen = false"
                        >Cancel</Button
                    >
                    <Button :disabled="saving" @click="submitEdit">
                        <Loader2
                            v-if="saving"
                            class="mr-2 h-4 w-4 animate-spin"
                        />
                        Save Permissions
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Create Role Dialog -->
        <Dialog :open="createOpen" @update:open="createOpen = $event">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Create Role</DialogTitle>
                </DialogHeader>
                <div class="space-y-4">
                    <div class="grid gap-2">
                        <Label>Role Name</Label>
                        <Input
                            v-model="createForm.name"
                            placeholder="e.g. Night Manager"
                            @keyup.enter="submitCreate"
                        />
                    </div>
                    <p class="text-muted-foreground text-sm">
                        You can assign permissions after creating the role.
                    </p>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="createOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        :disabled="creating || !createForm.name.trim()"
                        @click="submitCreate"
                    >
                        <Loader2
                            v-if="creating"
                            class="mr-2 h-4 w-4 animate-spin"
                        />
                        Create Role
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
