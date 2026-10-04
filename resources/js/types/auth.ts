import type { BranchContext } from './hms';

export type User = {
    id: number;
    branch_id: number | null;
    name: string;
    email: string;
    locale: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    is_global_admin: boolean;
    gdpr_consent_at: string | null;
    gdpr_consent_version: string | null;
    last_login_at: string | null;
    last_login_ip: string | null;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    roles: string[];
    permissions: string[];
};

export type PageProps = {
    name: string;
    auth: Auth;
    branch: BranchContext | null;
    sidebarOpen: boolean;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
