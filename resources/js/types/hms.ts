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

export type DoorLockGateway = {
    id: number;
    branch_id: number;
    provider: string;
    api_base_url: string;
    is_active: boolean;
    settings: Record<string, unknown> | null;
    created_at: string;
    updated_at: string;
};

export type DoorLockAuditLog = {
    id: number;
    branch_id: number;
    reservation_id: number | null;
    room_id: number;
    gateway_id: number;
    action: string;
    credential_id: string | null;
    valid_from: string;
    valid_until: string;
    status: string;
    payload: Record<string, unknown> | null;
    created_at: string;
    updated_at: string;
};

export interface RoomType {
    id: number;
    name: string;
}

export type RateOverride = {
    id: number;
    branch_id: number;
    room_type_id: number | null;
    start_date: string;
    end_date: string;
    rate_override: number | null;
    mlos: number | null;
    cta: boolean;
    ctd: boolean;
    is_active: boolean;
    notes: string | null;
    created_at: string;
    updated_at: string;
};

export type YieldRule = {
    id: number;
    branch_id: number;
    room_type_id: number | null;
    min_occupancy_pct: number;
    max_occupancy_pct: number;
    rate_multiplier: number;
    mlos_override: number | null;
    cta_override: boolean | null;
    is_active: boolean;
    priority: number;
    created_at: string;
    updated_at: string;
};

export type KpiSummary = {
    date: string;
    occupancy_pct: number;
    adr: number;
    revpar: number;
    revenue_7d: RevenueSummary;
    revenue_30d: RevenueSummary;
    occupancy_trend_30d: OccupancyTrend[];
    room_type_performance: RoomTypePerformance[];
};

export type RevenueSummary = {
    period_days: number;
    total_room_revenue: number;
    total_tax: number;
    total_other_charges: number;
    total_payments: number;
    net_revenue: number;
    daily: DailyRevenue[];
};

export type DailyRevenue = {
    date: string;
    room_revenue: number;
    tax: number;
    other_charges: number;
    payments: number;
    net_revenue: number;
};

export type OccupancyTrend = {
    date: string;
    occupancy_pct: number;
    occupied_rooms: number;
    total_rooms: number;
};

export type RoomTypePerformance = {
    room_type_name: string;
    total_revenue: number;
    rooms_sold: number;
    adr: number;
};
