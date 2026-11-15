<script setup lang="ts">
import type { ApexOptions } from 'apexcharts';
import { computed, defineAsyncComponent } from 'vue';

import { brandShade } from '@/lib/brandShade';
import { useTheme } from '@/lib/useTheme';

// ApexCharts only downloads on pages with a chart.
const VueApexCharts = defineAsyncComponent(() => import('vue3-apexcharts'));

const props = withDefaults(
    defineProps<{
        type: 'bar' | 'area' | 'line' | 'donut';
        series: ApexOptions['series'];
        categories?: string[];
        labels?: string[];
        height?: number;
        colors?: string[];
    }>(),
    { height: 280, colors: () => [brandShade('500', '#1570ef'), '#10b981', '#f59e0b', '#8b5cf6', '#f43f5e', brandShade('300', '#78acf7')] },
);

const { isDarkMode } = useTheme();

const options = computed<ApexOptions>(() => {
    const text = isDarkMode.value ? '#9ba3b8' : '#6c7590';

    return {
        colors: props.colors,
        labels: props.labels,
        chart: {
            type: props.type,
            fontFamily: 'var(--font-sans)',
            toolbar: { show: false },
            zoom: { enabled: false },
            background: 'transparent',
        },
        theme: { mode: isDarkMode.value ? 'dark' : 'light' },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: props.type === 'bar' || props.type === 'donut' ? 0 : 2 },
        fill: props.type === 'area' ? { type: 'gradient', gradient: { opacityFrom: 0.45, opacityTo: 0 } } : {},
        plotOptions: { bar: { columnWidth: '38%', borderRadius: 6, borderRadiusApplication: 'end' } },
        grid: { borderColor: isDarkMode.value ? '#232a3d' : '#eef0f5', strokeDashArray: 4, yaxis: { lines: { show: true } } },
        xaxis: { categories: props.categories, axisBorder: { show: false }, axisTicks: { show: false }, labels: { style: { colors: text } } },
        yaxis: { labels: { style: { colors: [text] } } },
        legend: { position: 'bottom', labels: { colors: text } },
    };
});
</script>

<template>
    <VueApexCharts :type="type" :height="height" :options="options" :series="series" />
</template>
