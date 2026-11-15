<script setup lang="ts">
import UserCell from '@/Components/table/UserCell.vue';

import type { UserCellData } from '../../parts/types';

/** A user / party of a tab's record (`UserCellData::of()`), or a dash. */
defineProps<{ user: unknown }>();
</script>

<template>
    <UserCell v-if="user" :name="(user as UserCellData).name" :avatar="(user as UserCellData).avatar" :detail="(user as UserCellData).detail" />
    <span v-else class="text-muted">—</span>
</template>
