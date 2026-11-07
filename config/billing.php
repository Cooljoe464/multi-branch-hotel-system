<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dunning schedule
    |--------------------------------------------------------------------------
    |
    | Spec v1.1 OD-12: a 30 day trial, 7 days past due, 14 days of grace, then
    | the subscription is restricted. All values are configurable per agreement.
    |
    */

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 30),
    'past_due_days' => (int) env('BILLING_PAST_DUE_DAYS', 7),
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 14),
    'invoice_due_days' => (int) env('BILLING_INVOICE_DUE_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Platform invoice tax
    |--------------------------------------------------------------------------
    |
    | VAT on the platform's own invoices, in basis points (750 = 7.5%). The rate
    | and the platform company's VAT status need tax-adviser sign-off (gate G-1)
    | before this is relied on for real invoices.
    |
    */

    'vat_basis_points' => (int) env('BILLING_VAT_BASIS_POINTS', 750),

    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'HOS'),

];
