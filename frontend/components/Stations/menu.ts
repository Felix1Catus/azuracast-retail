import { shallowRef, watch } from "vue";
import { StationPermissions } from "~/entities/ApiInterfaces.ts";
import {
    filterMenu,
    MenuCategory,
    RawMenuCategory,
} from "~/functions/filterMenu.ts";
import { useStationData } from "~/functions/useStationQuery.ts";
import { useUserAllowedForStation } from "~/functions/useUserallowedForStation.ts";
import { useTranslate } from "~/vendor/gettext.ts";
import IconBiBroadcast from "~icons/bi/broadcast";
import IconIcAssignment from "~icons/ic/baseline-assignment";
import IconIcCloudDownload from "~icons/ic/baseline-cloud-download";
import IconIcImage from "~icons/ic/baseline-image";
import IconIcInsertChart from "~icons/ic/baseline-insert-chart";
import IconIcLibraryMusic from "~icons/ic/baseline-library-music";
import IconIcPublic from "~icons/ic/baseline-public";
import IconIcQueueMusic from "~icons/ic/baseline-queue-music";
import IconIcSettingsApplication from "~icons/ic/baseline-settings-applications";
import IconIcStorefront from "~icons/ic/baseline-storefront";

export function useStationsMenu() {
    const { $gettext } = useTranslate();

    const station = useStationData();
    const { userAllowedForStation } = useUserAllowedForStation();

    const fullMenu: RawMenuCategory[] = [
        {
            key: "profile",
            label: $gettext("Overview"),
            icon: () => IconIcImage,
            url: {
                name: "stations:index",
            },
        },
        {
            key: "edit_profile",
            label: $gettext("Edit Station Settings"),
            icon: () => IconIcSettingsApplication,
            url: {
                name: "stations:settings:index",
            },
            visible: () => userAllowedForStation(StationPermissions.Profile),
        },
        {
            key: "public_page",
            label: $gettext("Public Pages"),
            icon: () => IconIcPublic,
            items: [
                {
                    key: "branding",
                    label: $gettext("Branding"),
                    url: {
                        name: "stations:branding",
                    },
                    visible: () =>
                        userAllowedForStation(StationPermissions.Profile),
                },
                {
                    key: "public_player",
                    label: $gettext("Public Player"),
                    url: station.value.publicPageUrl,
                    external: true,
                    visible: () => station.value.enablePublicPages,
                },
                {
                    key: "public_schedule",
                    label: $gettext("Schedule"),
                    url: station.value.publicScheduleUrl,
                    external: true,
                    visible: () => station.value.enablePublicPages,
                },
            ],
        },
        {
            key: "media",
            label: $gettext("Media"),
            icon: () => IconIcLibraryMusic,
            visible: () => station.value.features.media,
            items: [
                {
                    key: "music_files",
                    label: $gettext("Music Files"),
                    url: {
                        name: "stations:files:index",
                    },
                    visible: () =>
                        userAllowedForStation(StationPermissions.Media),
                },
                {
                    key: "duplicate_songs",
                    label: $gettext("Duplicate Songs"),
                    url: {
                        name: "stations:files:index",
                        params: {
                            path: "special:duplicates",
                        },
                    },
                    visible: () =>
                        userAllowedForStation(StationPermissions.Media),
                },
                {
                    key: "unprocessable",
                    label: $gettext("Unprocessable Files"),
                    url: {
                        name: "stations:files:index",
                        params: {
                            path: "special:unprocessable",
                        },
                    },
                    visible: () =>
                        userAllowedForStation(StationPermissions.Media),
                },
                {
                    key: "unassigned",
                    label: $gettext("Unassigned Files"),
                    url: {
                        name: "stations:files:index",
                        params: {
                            path: "special:unassigned",
                        },
                    },
                    visible: () =>
                        userAllowedForStation(StationPermissions.Media),
                },
                {
                    key: "bulk_media",
                    label: $gettext("Bulk Media Import/Export"),
                    url: {
                        name: "stations:bulk-media",
                    },
                    visible: () =>
                        userAllowedForStation(StationPermissions.Media),
                },
            ],
        },
        {
            key: "playlists",
            label: $gettext("Playlists"),
            icon: () => IconIcQueueMusic,
            url: {
                name: "stations:playlists:index",
            },
            visible: () =>
                userAllowedForStation(StationPermissions.Media) &&
                station.value.features.media,
        },
        {
            // Built-in Tracker module (plugins/branch-tracker): its own page, permission "view branches".
            key: "branch_board",
            label: $gettext("Branch Board"),
            icon: () => IconIcStorefront,
            url: `/branch-tracker/${station.value.id}`,
            visible: () =>
                station.value.features.branchBoard &&
                userAllowedForStation("view branches" as StationPermissions),
        },
        {
            // Built-in Music Downloader module (plugins/music-downloader): its own page, permission "download music".
            key: "music_downloader",
            label: $gettext("Music Downloader"),
            icon: () => IconIcCloudDownload,
            url: `/music-downloader/${station.value.id}`,
            visible: () =>
                station.value.features.musicDownloader &&
                userAllowedForStation("download music" as StationPermissions),
        },
        {
            key: "reports",
            label: $gettext("Reports"),
            icon: () => IconIcInsertChart,
            visible: () => userAllowedForStation(StationPermissions.Reports),
            items: [
                {
                    key: "reports_overview",
                    label: $gettext("Station Statistics"),
                    url: {
                        name: "stations:reports:overview",
                    },
                },
                {
                    key: "reports_listeners",
                    label: $gettext("Listeners"),
                    url: {
                        name: "stations:reports:listeners",
                    },
                },
                {
                    key: "reports_timeline",
                    label: $gettext("Song Playback Timeline"),
                    url: {
                        name: "stations:reports:timeline",
                    },
                },
            ],
        },
        {
            key: "broadcasting",
            label: $gettext("Broadcasting"),
            icon: () => IconBiBroadcast,
            items: [
                {
                    key: "mounts",
                    label: $gettext("Mount Points"),
                    url: {
                        name: "stations:mounts:index",
                    },
                    visible: () =>
                        userAllowedForStation(StationPermissions.MountPoints) &&
                        station.value.features.mountPoints,
                },
                {
                    key: "fallback",
                    label: $gettext("Custom Fallback File"),
                    url: {
                        name: "stations:fallback",
                    },
                    visible: () =>
                        userAllowedForStation(
                            StationPermissions.Broadcasting,
                        ) && station.value.features.media,
                },
                {
                    key: "ls_config",
                    label: $gettext("Edit Liquidsoap Configuration"),
                    url: {
                        name: "stations:util:ls_config",
                    },
                    visible: () =>
                        userAllowedForStation(
                            StationPermissions.Broadcasting,
                        ) && station.value.features.customLiquidsoapConfig,
                },
                {
                    key: "queue",
                    label: $gettext("Upcoming Song Queue"),
                    url: {
                        name: "stations:queue:index",
                    },
                    visible: () =>
                        userAllowedForStation(
                            StationPermissions.Broadcasting,
                        ) && station.value.features.autoDjQueue,
                },
                {
                    key: "restart",
                    label: $gettext("Restart Broadcasting"),
                    url: {
                        name: "stations:restart:index",
                    },
                    visible: () =>
                        userAllowedForStation(StationPermissions.Broadcasting),
                },
            ],
        },
        {
            key: "logs",
            label: $gettext("Logs"),
            icon: () => IconIcAssignment,
            url: {
                name: "stations:logs",
            },
            visible: () => userAllowedForStation(StationPermissions.Logs),
        },
    ];

    const menu = shallowRef<MenuCategory[]>([]);

    watch(
        station,
        () => {
            menu.value = filterMenu(fullMenu);
        },
        {
            immediate: true,
        },
    );

    return menu;
}
