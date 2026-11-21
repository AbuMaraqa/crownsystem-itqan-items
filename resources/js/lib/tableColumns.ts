import { t } from '@/lib/i18n';
import type { TableColumn } from '@/types/table';

/**
 * Columns every table shares. The server fills them with one call, so a column looks and behaves
 * the same in every list.
 */

/** «Created By»: the user card of whoever added the row. Server: `AdminTable::...->withCreator()`. */
export function createdByColumn(): TableColumn {
    return { key: 'created_by', label: t('Created By'), type: 'user', toggleable: true };
}
