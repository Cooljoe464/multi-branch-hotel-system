export type Branch = {
    id: number;
    name: string;
    code: string;
    slug: string;
    address: string | null;
    city: string;
    state: string | null;
    country: string;
    postal_code: string | null;
    phone: string | null;
    email: string | null;
    timezone: string;
    currency_code: string;
    currency_symbol: string;
    tax_rate: number;
    tax_label: string;
    is_active: boolean;
    is_primary: boolean;
    settings: Record<string, unknown> | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
    updated_at: string;
};

export type BranchContext = {
    current: Branch;
    available: Branch[];
    can_switch: boolean;
};
