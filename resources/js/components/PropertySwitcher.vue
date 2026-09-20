<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Building2, Check, ChevronsUpDown, Loader2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { switchMethod } from '@/routes/branch';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuItem,
} from '@/components/ui/dropdown-menu';
import type { BranchContext } from '@/types/hms';

const page = usePage();
const branchContext = computed(() => page.props.branch as BranchContext | null);
const isLoading = ref(false);

const currentBranch = computed(() => branchContext.value?.current);
const availableBranches = computed(() => branchContext.value?.available ?? []);
const canSwitch = computed(() => branchContext.value?.can_switch ?? false);

function switchBranch(branchId: number) {
    if (branchId === currentBranch.value?.id) {
        return;
    }

    isLoading.value = true;

    router.post(
        switchMethod.url(),
        { branch_id: branchId },
        {
            preserveScroll: true,
            onFinish: () => {
                isLoading.value = false;
            },
        },
    );
}
</script>

<template>
    <DropdownMenu v-if="currentBranch && canSwitch">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-accent disabled:opacity-60 focus:outline-none"
                :disabled="isLoading"
                aria-haspopup="menu"
                aria-label="Switch property"
            >
                <Building2 class="h-4 w-4 shrink-0 text-muted-foreground" />
                <span class="hidden flex-col items-start text-left md:flex">
                    <span
                        class="text-sm leading-tight font-medium text-foreground"
                    >
                        {{ currentBranch.name }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ currentBranch.city }}, {{ currentBranch.country }}
                    </span>
                </span>
                <Loader2
                    v-if="isLoading"
                    class="h-4 w-4 shrink-0 animate-spin"
                />
                <ChevronsUpDown v-else class="h-4 w-4 shrink-0 text-muted-foreground" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-72">
            <DropdownMenuLabel class="text-xs uppercase tracking-wide text-muted-foreground">
                Switch Property
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-for="branch in availableBranches"
                :key="branch.id"
                class="flex items-center gap-2 cursor-pointer"
                @click="switchBranch(branch.id)"
            >
                <Building2 class="h-4 w-4 shrink-0 text-muted-foreground" />
                <span class="flex flex-1 flex-col">
                    <span class="font-medium">{{ branch.name }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{ branch.city }}, {{ branch.country }}
                        <span class="ml-1">{{ branch.currency_code }}</span>
                    </span>
                </span>
                <Check
                    v-if="branch.id === currentBranch.id"
                    class="h-4 w-4 shrink-0 text-primary"
                />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <div
        v-else-if="currentBranch"
        class="flex items-center gap-2 px-2 py-1.5"
    >
        <Building2 class="h-4 w-4 shrink-0 text-muted-foreground" />
        <div class="hidden flex-col items-start text-left md:flex">
            <span
                class="text-sm leading-tight font-medium text-foreground"
            >
                {{ currentBranch.name }}
            </span>
            <span class="text-xs text-muted-foreground">
                {{ currentBranch.city }}, {{ currentBranch.country }}
            </span>
        </div>
    </div>
</template>

