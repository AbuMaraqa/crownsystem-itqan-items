import type { Option } from '../types';

/**
 * What differs between the receipt and payment voucher pages. Texts are English keys, translated
 * where they are shown; `routes` is the route names' prefix and `entity` the permissions' one.
 */
export interface VoucherKind {
    routes: string;
    entity: string;
    title: string;
    list: string;
    add: string;
    details: string;
    account: string;
    empty: string;
    postConfirm: string;
    cancelConfirm: string;
}

export const paymentVoucher: VoucherKind = {
    routes: 'accounting.payment-vouchers',
    entity: 'Payment Voucher',
    title: 'Payment Voucher',
    list: 'Payment Vouchers',
    add: 'Add Payment Voucher',
    details: 'Payment Voucher Details',
    account: 'Paid From',
    empty: 'No payment vouchers found',
    postConfirm: 'Post this payment voucher? Its journal entry will be posted.',
    cancelConfirm: 'Cancel this payment voucher? Its allocations will be released and a reversal journal entry will be posted.',
};

export const receiptVoucher: VoucherKind = {
    routes: 'accounting.receipt-vouchers',
    entity: 'Receipt Voucher',
    title: 'Receipt Voucher',
    list: 'Receipt Vouchers',
    add: 'Add Receipt Voucher',
    details: 'Receipt Voucher Details',
    account: 'Received Into',
    empty: 'No receipt vouchers found',
    postConfirm: 'Post this receipt voucher? Its journal entry will be posted.',
    cancelConfirm: 'Cancel this receipt voucher? Its allocations will be released and a reversal journal entry will be posted.',
};

/** `Modules\Accounting\Support\StatusBadge::of()`. */
export interface StatusBadgeData {
    label: string;
    tone: 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'brand';
}

export interface VoucherForm {
    client_id: number | null;
    branch_id: number | null;
    account_id: number | null;
    voucher_date: string;
    currency_id: number | null;
    exchange_rate: string | number | null;
    payment_method: number;
    amount: string | number;
    cheque_number: string | null;
    cheque_bank_name: string | null;
    cheque_date: string | null;
    notes: string | null;
}

/** Form choices from VoucherController::formOptions. */
export interface VoucherFormOptions {
    accountOptions: Option[];
    currencyOptions: Option[];
    paymentMethods: Option[];
    chequeMethod: number;
}
