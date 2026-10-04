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
                class="hover:bg-accent flex items-center gap-2 rounded-lg px-2 py-1.5 focus:outline-none disabled:opacity-60"
                :disabled="isLoading"
                aria-haspopup="menu"
                aria-label="Switch property"
            >
                <Building2 class="text-muted-foreground h-4 w-4 shrink-0" />
                <span class="hidden flex-col items-start text-left md:flex">
                    <span
                        class="text-foreground text-sm leading-tight font-medium"
                    >
                        {{ currentBranch.name }}
                    </span>
                    <span class="text-muted-foreground text-xs">
                        {{ currentBranch.city }}, {{ currentBranch.country }}
                    </span>
                </span>
                <Loader2
                    v-if="isLoading"
                    class="h-4 w-4 shrink-0 animate-spin"
                />
                <ChevronsUpDown
                    v-else
                    class="text-muted-foreground h-4 w-4 shrink-0"
                />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-72">
            <DropdownMenuLabel
                class="text-muted-foreground text-xs tracking-wide uppercase"
            >
                Switch Property
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-for="branch in availableBranches"
                :key="branch.id"
                class="flex cursor-pointer items-center gap-2"
                @click="switchBranch(branch.id)"
            >
                <Building2 class="text-muted-foreground h-4 w-4 shrink-0" />
                <span class="flex flex-1 flex-col">
                    <span class="font-medium">{{ branch.name }}</span>
                    <span class="text-muted-foreground text-xs">
                        {{ branch.city }}, {{ branch.country }}
                        <span class="ml-1">{{ branch.currency_code }}</span>
                    </span>
                </span>
                <Check
                    v-if="branch.id === currentBranch.id"
                    class="text-primary h-4 w-4 shrink-0"
                />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <div v-else-if="currentBranch" class="flex items-center gap-2 px-2 py-1.5">
        <Building2 class="text-muted-foreground h-4 w-4 shrink-0" />
        <div class="hidden flex-col items-start text-left md:flex">
            <span class="text-foreground text-sm leading-tight font-medium">
                {{ currentBranch.name }}
            </span>
            <span class="text-muted-foreground text-xs">
                {{ currentBranch.city }}, {{ currentBranch.country }}
            </span>
        </div>
    </div>
</template>
