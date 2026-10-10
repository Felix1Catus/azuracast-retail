import { RouteRecordRaw } from "vue-router";

export default function useStationsRoutes(): RouteRecordRaw[] {
    return [
        {
            path: "/station/:station_id",
            component: () => import("~/components/Stations/StationsLayout.vue"),
            children: [
                {
                    path: "",
                    component: () =>
                        import("~/components/Stations/Profile.vue"),
                    name: "stations:index",
                },
                {
                    path: "branding",
                    component: () =>
                        import("~/components/Stations/Branding.vue"),
                    name: "stations:branding",
                },
                {
                    path: "bulk-media",
                    component: () =>
                        import("~/components/Stations/BulkMedia.vue"),
                    name: "stations:bulk-media",
                },
                {
                    path: "fallback",
                    component: () =>
                        import("~/components/Stations/Fallback.vue"),
                    name: "stations:fallback",
                },
                {
                    path: "files/:path?",
                    component: () =>
                        import("~/components/Stations/MediaWrapper.vue"),
                    name: "stations:files:index",
                },
                {
                    path: "ls_config",
                    component: () =>
                        import("~/components/Stations/LiquidsoapConfig.vue"),
                    name: "stations:util:ls_config",
                },
                {
                    path: "logs",
                    component: () => import("~/components/Stations/Logs.vue"),
                    name: "stations:logs",
                },
                {
                    path: "playlists",
                    component: () =>
                        import("~/components/Stations/Playlists.vue"),
                    name: "stations:playlists:index",
                },
                {
                    path: "profile",
                    name: "stations:profile:index",
                    redirect: {
                        name: "stations:index",
                    },
                },
                {
                    path: "profile/edit",
                    name: "stations:profile:edit",
                    redirect: {
                        name: "stations:settings:index",
                    },
                },
                {
                    path: "mounts",
                    component: () => import("~/components/Stations/Mounts.vue"),
                    name: "stations:mounts:index",
                },
                {
                    path: "queue",
                    component: () => import("~/components/Stations/Queue.vue"),
                    name: "stations:queue:index",
                },
                {
                    path: "reports/overview",
                    component: () =>
                        import("~/components/Stations/Reports/Overview.vue"),
                    name: "stations:reports:overview",
                },
                {
                    path: "reports/timeline",
                    component: () =>
                        import("~/components/Stations/Reports/Timeline.vue"),
                    name: "stations:reports:timeline",
                },
                {
                    path: "reports/listeners",
                    component: () =>
                        import("~/components/Stations/Reports/Listeners.vue"),
                    name: "stations:reports:listeners",
                },
                {
                    path: "restart",
                    component: () =>
                        import("~/components/Stations/Restart.vue"),
                    name: "stations:restart:index",
                },
                {
                    path: "settings",
                    component: () =>
                        import("~/components/Stations/Settings.vue"),
                    name: "stations:settings:index",
                },
            ],
        },
    ];
}
