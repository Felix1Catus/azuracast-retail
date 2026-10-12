import {
    Chart,
    ChartConfiguration,
    ChartConfigurationCustomTypesPerDataset,
    ChartDataset,
    ChartType,
    DefaultDataPoint,
    registerables,
} from "chart.js";
import zoomPlugin from "chartjs-plugin-zoom";
import { defaultsDeep } from "es-toolkit/compat";
import {
    computed,
    MaybeRefOrGetter,
    onMounted,
    onUnmounted,
    Ref,
    toRaw,
    toValue,
    watch,
} from "vue";
import chartjsColorSchemes from "~/vendor/chartjs_colorschemes.ts";

import "chartjs-adapter-luxon";
import "~/vendor/luxon";

Chart.register(...registerables);

Chart.register(zoomPlugin);

Chart.register(chartjsColorSchemes);

// Acacia Radio chart style: the page's font and text colour, light gridlines, smooth lines without dots,
// rounded bars, compact legends and dark rounded tooltips.
const pageStyle = getComputedStyle(document.documentElement);
Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
Chart.defaults.font.size = 12;
Chart.defaults.color = pageStyle.getPropertyValue("--bs-secondary-color").trim() || "#66706a";
Chart.defaults.borderColor = "rgba(128, 128, 128, 0.14)";
Chart.defaults.elements.line.tension = 0.35;
Chart.defaults.elements.line.borderWidth = 2.5;
Chart.defaults.elements.point.radius = 0;
Chart.defaults.elements.point.hoverRadius = 5;
Chart.defaults.elements.point.hitRadius = 12;
Chart.defaults.elements.bar.borderRadius = 6;
Chart.defaults.elements.bar.borderSkipped = "start";
Chart.defaults.elements.arc.borderWidth = 2;
Chart.defaults.interaction.mode = "index";
Chart.defaults.interaction.intersect = false;
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.boxWidth = 8;
Chart.defaults.plugins.legend.labels.boxHeight = 8;
Chart.defaults.plugins.legend.labels.padding = 16;
Chart.defaults.plugins.tooltip.backgroundColor = "rgba(24, 33, 28, 0.94)";
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.plugins.tooltip.boxPadding = 4;
Chart.defaults.plugins.tooltip.usePointStyle = true;

interface ChartAltValue {
    label: string;
    type: string;
    original: string | number;
    value: string;
}

export interface ChartAltData {
    label: string;
    values: ChartAltValue[];
}

export interface ChartProps<
    TType extends ChartType = ChartType,
    TData = DefaultDataPoint<TType>,
    TLabel = unknown,
> {
    options?: Partial<
        | ChartConfiguration<TType, TData, TLabel>
        | ChartConfigurationCustomTypesPerDataset<TType, TData, TLabel>
    >;
    data?: ChartDataset<TType, TData>[];
    aspectRatio?: number;
    alt?: ChartAltData[];
    labels?: Array<any>;
}

export type ChartTemplateRef = HTMLCanvasElement | null;

export default function useChart<
    TType extends ChartType = ChartType,
    TData = DefaultDataPoint<TType>,
    TLabel = unknown,
>(
    props: ChartProps,
    $canvas: Ref<ChartTemplateRef>,
    defaultOptions: MaybeRefOrGetter<
        Partial<
            | ChartConfiguration<TType, TData, TLabel>
            | ChartConfigurationCustomTypesPerDataset<TType, TData, TLabel>
        >
    >,
): {
    $chart: Chart<TType, TData, TLabel> | null;
} {
    let $chart: Chart<TType, TData, TLabel> | null = null;

    const chartConfig = computed(() => {
        return defaultsDeep(
            {
                options: {
                    aspectRatio: props.aspectRatio ?? 2,
                },
                data: {
                    datasets: toRaw(props.data),
                    labels: toRaw(props.labels),
                },
            },
            toValue(defaultOptions),
            props.options,
        );
    });

    const rebuildChart = () => {
        $chart?.destroy();

        const chartContext = $canvas.value?.getContext("2d");
        if (!chartContext) {
            throw new Error("Cannot find chart context!");
        }

        $chart = new Chart(chartContext, chartConfig.value);
    };

    onMounted(rebuildChart);

    watch(
        () => chartConfig,
        () => {
            rebuildChart();
        },
        { deep: true },
    );

    onUnmounted(() => {
        $chart?.destroy();
    });

    return {
        $chart,
    };
}
