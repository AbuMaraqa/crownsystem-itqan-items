<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import { tableClasses as c, type ReportSection } from './types';

/**
 * A balance sheet or income statement section: each account opens its ledger for the
 * report's dates (`ledgerQuery`), then the section total from the service.
 */
defineProps<{ section: ReportSection; ledgerQuery: Record<string, string | null> }>();

const ledgerUrl = (accountId: number, query: Record<string, string | null>): string =>
    route('accounting.reports.ledger', { account_id: accountId, ...Object.fromEntries(Object.entries(query).filter(([, value]) => value)) });
</script>

<template>
    <Card :title="section.title">
        <div class="-m-5 overflow-x-auto">
            <table :class="c.table">
                <thead :class="c.head">
                    <tr class="border-b border-line">
                        <th :class="[c.th, 'text-start']">{{ t('Account') }}</th>
                        <th :class="[c.th, 'text-end']">{{ t('Balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in section.rows" :key="row.account_id" :class="c.row">
                        <td :class="c.td">
                            <Link :href="ledgerUrl(row.account_id, ledgerQuery)" :class="c.link"><bdi dir="ltr">{{ row.code }}</bdi> - {{ row.name }}</Link>
                        </td>
                        <td :class="c.number" dir="ltr">{{ row.amount }}</td>
                    </tr>
                    <tr v-for="extra in section.extra" :key="extra.label" :class="[c.row, 'italic']">
                        <td :class="c.td">{{ extra.label }}</td>
                        <td :class="c.number" dir="ltr">{{ extra.amount }}</td>
                    </tr>
                    <tr v-if="!section.rows.length && !section.extra.length">
                        <td colspan="2" :class="c.empty">{{ t('No records found') }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr :class="c.foot">
                        <td :class="c.td">{{ t('Total') }}</td>
                        <td :class="c.number" dir="ltr">{{ section.total }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </Card>
</template>
