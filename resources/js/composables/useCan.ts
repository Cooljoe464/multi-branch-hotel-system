import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Auth } from '@/types';

export function useCan() {
    const page = usePage();

    const permissions = computed<string[]>(
        () => (page.props.auth as Auth)?.permissions ?? [],
    );
    const roles = computed<string[]>(
        () => (page.props.auth as Auth)?.roles ?? [],
    );

    function can(permission: string): boolean {
        return permissions.value.includes(permission);
    }

    function hasRole(role: string): boolean {
        return roles.value.includes(role);
    }

    function canAny(list: string[]): boolean {
        return list.some((permission) => can(permission));
    }

    return { can, canAny, hasRole, permissions, roles };
}
