import type { Component } from 'vue';

import Banknote2BoldDuotoneIcon from '~icons/solar/banknote-2-bold-duotone';
import Book2BoldDuotoneIcon from '~icons/solar/book-2-bold-duotone';
import BoxBoldDuotoneIcon from '~icons/solar/box-bold-duotone';
import BuildingsBoldDuotoneIcon from '~icons/solar/buildings-bold-duotone';
import CalculatorBoldDuotoneIcon from '~icons/solar/calculator-bold-duotone';
import CalendarBoldDuotoneIcon from '~icons/solar/calendar-bold-duotone';
import CartLargeBoldDuotoneIcon from '~icons/solar/cart-large-bold-duotone';
import ChartSquareBoldDuotoneIcon from '~icons/solar/chart-square-bold-duotone';
import ClipboardListBoldDuotoneIcon from '~icons/solar/clipboard-list-bold-duotone';
import DocumentAddBoldDuotoneIcon from '~icons/solar/document-add-bold-duotone';
import DocumentTextBoldDuotoneIcon from '~icons/solar/document-text-bold-duotone';
import GlobalBoldDuotoneIcon from '~icons/solar/global-bold-duotone';
import GraphNewBoldDuotoneIcon from '~icons/solar/graph-new-bold-duotone';
import Home2BoldDuotoneIcon from '~icons/solar/home-2-bold-duotone';
import HourglassBoldDuotoneIcon from '~icons/solar/hourglass-bold-duotone';
import HourglassLineBoldDuotoneIcon from '~icons/solar/hourglass-line-bold-duotone';
import InboxInBoldDuotoneIcon from '~icons/solar/inbox-in-bold-duotone';
import InboxOutBoldDuotoneIcon from '~icons/solar/inbox-out-bold-duotone';
import LinkBoldDuotoneIcon from '~icons/solar/link-bold-duotone';
import LockBoldDuotoneIcon from '~icons/solar/lock-bold-duotone';
import LockKeyholeBoldDuotoneIcon from '~icons/solar/lock-keyhole-bold-duotone';
import PaperclipBoldDuotoneIcon from '~icons/solar/paperclip-bold-duotone';
import RefreshSquareBoldDuotoneIcon from '~icons/solar/refresh-square-bold-duotone';
import ScaleBoldDuotoneIcon from '~icons/solar/scale-bold-duotone';
import SettingsBoldDuotoneIcon from '~icons/solar/settings-bold-duotone';
import SettingsMinimalisticBoldDuotoneIcon from '~icons/solar/settings-minimalistic-bold-duotone';
import SledgehammerBoldDuotoneIcon from '~icons/solar/sledgehammer-bold-duotone';
import TagPriceBoldDuotoneIcon from '~icons/solar/tag-price-bold-duotone';
import UserIdBoldDuotoneIcon from '~icons/solar/user-id-bold-duotone';
import UsersGroupRoundedBoldDuotoneIcon from '~icons/solar/users-group-rounded-bold-duotone';
import UsersGroupTwoRoundedBoldDuotoneIcon from '~icons/solar/users-group-two-rounded-bold-duotone';
import VirusBoldDuotoneIcon from '~icons/solar/virus-bold-duotone';
import WalletMoneyBoldDuotoneIcon from '~icons/solar/wallet-money-bold-duotone';
import WidgetAddBoldDuotoneIcon from '~icons/solar/widget-add-bold-duotone';

/**
 * Sidebar providers (each module's Providers/SidebarServiceProvider.php) name their icon 'solar-<name>';
 * each one used there is imported here so the bundle only carries those. A new icon gets a line here;
 * an unknown name shows the fallback.
 */
export const navIcons: Record<string, Component> = {
    'solar-banknote-2-bold-duotone': Banknote2BoldDuotoneIcon,
    'solar-book-2-bold-duotone': Book2BoldDuotoneIcon,
    'solar-box-bold-duotone': BoxBoldDuotoneIcon,
    'solar-buildings-bold-duotone': BuildingsBoldDuotoneIcon,
    'solar-calculator-bold-duotone': CalculatorBoldDuotoneIcon,
    'solar-calendar-bold-duotone': CalendarBoldDuotoneIcon,
    'solar-cart-large-bold-duotone': CartLargeBoldDuotoneIcon,
    'solar-chart-square-bold-duotone': ChartSquareBoldDuotoneIcon,
    'solar-clipboard-list-bold-duotone': ClipboardListBoldDuotoneIcon,
    'solar-document-add-bold-duotone': DocumentAddBoldDuotoneIcon,
    'solar-document-text-bold-duotone': DocumentTextBoldDuotoneIcon,
    'solar-global-bold-duotone': GlobalBoldDuotoneIcon,
    'solar-graph-new-bold-duotone': GraphNewBoldDuotoneIcon,
    'solar-home-2-bold-duotone': Home2BoldDuotoneIcon,
    'solar-hourglass-bold-duotone': HourglassBoldDuotoneIcon,
    'solar-hourglass-line-bold-duotone': HourglassLineBoldDuotoneIcon,
    'solar-inbox-in-bold-duotone': InboxInBoldDuotoneIcon,
    'solar-inbox-out-bold-duotone': InboxOutBoldDuotoneIcon,
    'solar-link-bold-duotone': LinkBoldDuotoneIcon,
    'solar-lock-bold-duotone': LockBoldDuotoneIcon,
    'solar-lock-keyhole-bold-duotone': LockKeyholeBoldDuotoneIcon,
    'solar-paperclip-bold-duotone': PaperclipBoldDuotoneIcon,
    'solar-refresh-square-bold-duotone': RefreshSquareBoldDuotoneIcon,
    'solar-scale-bold-duotone': ScaleBoldDuotoneIcon,
    'solar-settings-bold-duotone': SettingsBoldDuotoneIcon,
    'solar-settings-minimalistic-bold-duotone': SettingsMinimalisticBoldDuotoneIcon,
    'solar-sledgehammer-bold-duotone': SledgehammerBoldDuotoneIcon,
    'solar-tag-price-bold-duotone': TagPriceBoldDuotoneIcon,
    'solar-user-id-bold-duotone': UserIdBoldDuotoneIcon,
    'solar-users-group-rounded-bold-duotone': UsersGroupRoundedBoldDuotoneIcon,
    'solar-users-group-two-rounded-bold-duotone': UsersGroupTwoRoundedBoldDuotoneIcon,
    'solar-virus-bold-duotone': VirusBoldDuotoneIcon,
    'solar-wallet-money-bold-duotone': WalletMoneyBoldDuotoneIcon,
    'solar-widget-add-bold-duotone': WidgetAddBoldDuotoneIcon,
};

export const fallbackNavIcon = BoxBoldDuotoneIcon;

export interface NavEntry {
    type: 'item' | 'group';
    title: string;
    icon: string;
    route?: string;
    children?: NavEntry[];
    /** The heading the entry sits under ("Sales"); entries of one section come together. */
    section?: string | null;
}
