<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Building2, Check, ChevronsUpDown, Loader2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
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
        route('branch.switch'),
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
            <Button
                variant="ghost"
                class="flex items-center gap-2 px-2 py-1.5 h-auto"
                :disabled="isLoading"
            >
                <Building2 class="h-4 w-4 shrink-0 text-muted-foreground" />
                <div class="flex flex-col items-start text-left">
                    <span class="text-sm font-medium leading-tight">
                        {{ currentBranch.name }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ currentBranch.city }}, {{ currentBranch.country }}
                    </span>
                </div>
                <Loader2
                    v-if="isLoading"
                    class="h-4 w-4 shrink-0 animate-spin"
                />
                <ChevronsUpDown
                    v-else
                    class="h-4 w-4 shrink-0 text-muted-foreground"
                />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-72">
            <DropdownMenuLabel>Switch Property</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-for="branch in availableBranches"
                :key="branch.id"
                class="flex items-center gap-2 cursor-pointer"
                @click="switchBranch(branch.id)"
            >
                <Building2 class="h-4 w-4 shrink-0 text-muted-foreground" />
                <div class="flex flex-1 flex-col">
                    <span class="text-sm font-medium">{{ branch.name }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{ branch.city }}, {{ branch.country }}
                        <span class="ml-1">{{ branch.currency_code }}</span>
                    </span>
                </div>
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
        <div class="flex flex-col items-start text-left">
            <span class="text-sm font-medium leading-tight">
                {{ currentBranch.name }}
            </span>
            <span class="text-xs text-muted-foreground">
                {{ currentBranch.city }}, {{ currentBranch.country }}
            </span>
        </div>
    </div>
</template>
