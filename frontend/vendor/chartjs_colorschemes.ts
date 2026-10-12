// eslint-disable-next-line @typescript-eslint/ban-ts-comment
// @ts-nocheck Typescript hates this file.
// Color Scheme Plugin

import colorLib from "@kurkle/color";
import { Chart } from "chart.js";
import { forEach } from "es-toolkit/compat";

// Acacia Radio palette: leaf, blossom, bark, sky, clay, then lighter tints of each.
const scheme: string[] = [
    "#2F6F4E",
    "#E9B23C",
    "#7B4B2A",
    "#3D7C98",
    "#C8553D",
    "#7FB08F",
    "#F3D58A",
    "#B48A68",
    "#86B4C7",
    "#E59A86",
    "#4F9A5F",
    "#D49A2A",
    "#9C6B48",
    "#2C5F75",
    "#A8443A",
    "#A9CDB4",
    "#F7E3B0",
    "#D1B39A",
    "#B3D2DE",
    "#F0BFB3",
];
const length: number = scheme.length;
const fillAlpha: number = 0.18;

const METADATA_KEY: string = "$colorschemes";

export default {
    id: "colorschemes",
    beforeUpdate: (chart: Chart) => {
        // Set scheme colors
        forEach(chart.config.data.datasets, (dataset, datasetIndex: number) => {
            const color = scheme[datasetIndex % length];

            // Object to store which color option is set
            dataset[METADATA_KEY] = {};

            switch (dataset.type || chart.config.type) {
                // For line, radar and scatter chart, borderColor and backgroundColor (50% transparent) are set
                case "line":
                case "radar":
                case "scatter":
                    if (typeof dataset.backgroundColor === "undefined") {
                        dataset[METADATA_KEY].backgroundColor =
                            dataset.backgroundColor;
                        dataset.backgroundColor = colorLib(color)
                            .alpha(fillAlpha)
                            .rgbString();
                    }
                    if (typeof dataset.borderColor === "undefined") {
                        dataset[METADATA_KEY].borderColor = dataset.borderColor;
                        dataset.borderColor = color;
                    }
                    if (typeof dataset.pointBackgroundColor === "undefined") {
                        dataset[METADATA_KEY].pointBackgroundColor =
                            dataset.pointBackgroundColor;
                        dataset.pointBackgroundColor = colorLib(color)
                            .alpha(fillAlpha)
                            .rgbString();
                    }
                    if (typeof dataset.pointBorderColor === "undefined") {
                        dataset[METADATA_KEY].pointBorderColor =
                            dataset.pointBorderColor;
                        dataset.pointBorderColor = color;
                    }
                    break;

                // For doughnut and pie chart, backgroundColor is set to an array of colors
                case "doughnut":
                case "pie":
                case "polarArea":
                    if (typeof dataset.backgroundColor === "undefined") {
                        dataset[METADATA_KEY].backgroundColor =
                            dataset.backgroundColor;
                        dataset.backgroundColor = dataset.data.map(
                            (_, dataIndex) => scheme[dataIndex % length],
                        );
                    }
                    break;
                // For bar chart backgroundColor (including fillAlpha) and borderColor are set
                case "bar":
                    if (typeof dataset.backgroundColor === "undefined") {
                        dataset[METADATA_KEY].backgroundColor =
                            dataset.backgroundColor;
                        dataset.backgroundColor = colorLib(color)
                            .alpha(fillAlpha)
                            .rgbString();
                    }
                    if (typeof dataset.borderColor === "undefined") {
                        dataset[METADATA_KEY].borderColor = dataset.borderColor;
                        dataset.borderColor = color;
                    }
                    break;
                // For the other chart, only backgroundColor is set
                default:
                    if (typeof dataset.backgroundColor === "undefined") {
                        dataset[METADATA_KEY].backgroundColor =
                            dataset.backgroundColor;
                        dataset.backgroundColor = color;
                    }
                    break;
            }
        });
    },

    afterUpdate: (chart: Chart) => {
        // Unset colors
        forEach(chart.config.data.datasets, (dataset) => {
            if (!dataset[METADATA_KEY]) {
                return;
            }

            if ("backgroundColor" in dataset[METADATA_KEY]) {
                dataset.backgroundColor = dataset[METADATA_KEY].backgroundColor;
            }
            if ("borderColor" in dataset[METADATA_KEY]) {
                dataset.borderColor = dataset[METADATA_KEY].borderColor;
            }
            if ("pointBackgroundColor" in dataset[METADATA_KEY]) {
                dataset.pointBackgroundColor =
                    dataset[METADATA_KEY].pointBackgroundColor;
            }
            if ("pointBorderColor" in dataset[METADATA_KEY]) {
                dataset.pointBorderColor =
                    dataset[METADATA_KEY].pointBorderColor;
            }

            delete dataset[METADATA_KEY];
        });
    },
};
