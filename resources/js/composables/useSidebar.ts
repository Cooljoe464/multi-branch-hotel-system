import { inject, type Ref } from 'vue';

export const SIDEBAR_STATE_KEY = 'sidebarOpen';

export type SidebarState = {
    sidebarOpen: Ref<boolean>;
    mobileSidebarOpen: Ref<boolean>;
    toggleSidebar: () => void;
    openMobileSidebar: () => void;
    closeMobileSidebar: () => void;
};

export function useSidebar(): SidebarState {
    const state = inject<SidebarState>(SIDEBAR_STATE_KEY);

    if (!state) {
        throw new Error('useSidebar must be used inside AppShell');
    }

    return state;
}
