<template>
    <dashboard-no-sidebar>
        <div
            id="dashboard"
            class="row-of-cards"
        >
            <section
                class="card"
                role="region"
                :aria-label="$gettext('Account Details')"
            >
                <user-info-panel>
                    <router-link
                        class="btn btn-dark"
                        role="button"
                        :to="{ name: 'profile:index'}"
                    >
                        <icon-ic-account-circle/>
                        <span>{{ $gettext('My Account') }}</span>
                    </router-link>
                    <router-link
                        v-if="showAdmin"
                        class="btn btn-dark"
                        role="button"
                        :to="{ name: 'admin:index' }"
                    >
                        <icon-ic-settings/>
                        <span>{{ $gettext('Administration') }}</span>
                    </router-link>
                </user-info-panel>

                <template v-if="!notificationsLoading && notifications && notifications.length > 0">
                    <div
                        v-for="notification in notifications"
                        :key="notification.id"
                        :id="notification.id"
                        class="card-body d-flex align-items-center alert flex-md-row flex-column"
                        :class="'alert-'+notification.type"
                        role="alert"
                        aria-live="polite"
                    >
                        <div
                            v-if="'info' === notification.type"
                            class="flex-shrink-0 me-3"
                        >
                            <icon-ic-info class="lg"/>
                        </div>
                        <div
                            v-else
                            class="flex-shrink-0 me-3"
                        >
                            <icon-ic-warning class="lg"/>
                        </div>
                        <div class="flex-fill">
                            <h4>{{ notification.title }}</h4>
                            <p class="card-text">
                                {{ notification.body }}
                            </p>
                        </div>
                        <div
                            v-if="notification.actionLabel && notification.actionUrl"
                            class="flex-shrink-0 ms-md-3 mt-3 mt-md-0"
                        >
                            <a
                                class="btn btn-sm"
                                :class="'btn-'+notification.type"
                                :href="notification.actionUrl"
                                target="_blank"
                            >
                                {{ notification.actionLabel }}
                            </a>
                        </div>
                    </div>
                </template>
            </section>

            <section
                class="listener-summary"
                role="region"
                :aria-label="$gettext('Listeners')"
            >
                <div class="summary-tile">
                    <icon-ic-headphones class="summary-icon"/>
                    <div class="summary-value">{{ summary.listeners }}</div>
                    <div class="summary-label">{{ $gettext('Listening now') }}</div>
                </div>
                <div class="summary-tile">
                    <icon-ic-person class="summary-icon"/>
                    <div class="summary-value">{{ summary.unique }}</div>
                    <div class="summary-label">{{ $gettext('Unique listeners') }}</div>
                </div>
                <div class="summary-tile">
                    <icon-ic-radio class="summary-icon"/>
                    <div class="summary-value">
                        {{ summary.online }}<small>/{{ summary.total }}</small>
                    </div>
                    <div class="summary-label">{{ $gettext('Stations on air') }}</div>
                </div>
                <div class="summary-tile">
                    <icon-ic-trending-up class="summary-icon"/>
                    <div class="summary-value summary-value-text">{{ summary.topStation ?? '–' }}</div>
                    <div class="summary-label">{{ $gettext('Most listened') }}</div>
                </div>
            </section>

            <section
                class="card"
                role="region"
                aria-labelledby="hdr_stations"
            >
                <div class="card-header text-bg-primary d-flex flex-wrap align-items-center gap-2">
                    <h2
                        id="hdr_stations"
                        class="card-title flex-fill mb-0"
                    >
                        {{ $gettext('Stations') }}
                    </h2>
                    <input
                        v-if="stations.length > 6"
                        v-model="stationSearch"
                        type="search"
                        class="form-control station-search"
                        :placeholder="$gettext('Search stations')"
                        :aria-label="$gettext('Search stations')"
                    >
                    <router-link
                        v-if="showAdmin"
                        class="btn btn-dark btn-sm"
                        :to="{ name: 'admin:stations:index' }"
                    >
                        <icon-ic-settings/>
                        <span>{{ $gettext('Manage Stations') }}</span>
                    </router-link>
                </div>

                <div class="card-body">
                    <div
                        v-if="stationsLoading"
                        class="text-muted"
                    >
                        {{ $gettext('Loading stations…') }}
                    </div>
                    <div
                        v-else-if="filteredStations.length === 0"
                        class="station-empty"
                    >
                        {{ stations.length === 0 ? $gettext('No stations yet.') : $gettext('No stations match your search.') }}
                    </div>
                    <div
                        v-else
                        class="station-grid"
                    >
                        <article
                            v-for="item in filteredStations"
                            :key="item.station.id"
                            class="station-card"
                        >
                            <div class="station-card-art">
                                <img
                                    v-if="showAlbumArt && item.now_playing?.song?.art"
                                    :src="item.now_playing.song.art"
                                    alt=""
                                    loading="lazy"
                                >
                                <play-button
                                    class="station-card-play btn-lg"
                                    :stream="{
                                        url: item.station.listen_url,
                                        title: item.station.name,
                                        isStream: true
                                    }"
                                />
                            </div>
                            <div class="station-card-body">
                                <div class="d-flex align-items-center gap-2">
                                    <h3 class="station-card-name">
                                        {{ item.station.name }}
                                    </h3>
                                    <span
                                        class="station-status"
                                        :class="item.is_online ? 'is-online' : 'is-offline'"
                                    >
                                        {{ item.is_online ? $gettext('On air') : $gettext('Offline') }}
                                    </span>
                                </div>
                                <div
                                    v-if="item.is_online"
                                    class="station-card-song"
                                    :title="item.now_playing?.song?.text"
                                >
                                    <strong>{{ item.now_playing?.song?.title || item.now_playing?.song?.text }}</strong>
                                    <span v-if="item.now_playing?.song?.artist">{{ item.now_playing.song.artist }}</span>
                                </div>
                                <div
                                    v-else
                                    class="station-card-song text-muted"
                                >
                                    {{ $gettext('Station Offline') }}
                                </div>
                                <div class="station-card-footer">
                                    <a
                                        v-if="item.links.listeners"
                                        :href="item.links.listeners"
                                        class="station-card-listeners"
                                        :aria-label="$gettext('View Listener Report')"
                                    >
                                        <icon-ic-headphones class="sm"/>
                                        {{ item.listeners.total }}
                                    </a>
                                    <span
                                        v-else
                                        class="station-card-listeners"
                                    >
                                        <icon-ic-headphones class="sm"/>
                                        {{ item.listeners.total }}
                                    </span>
                                    <a
                                        v-if="item.station.is_public"
                                        :href="item.links.public"
                                        target="_blank"
                                        class="btn btn-sm btn-link"
                                    >
                                        {{ $gettext('Public Page') }}
                                    </a>
                                    <router-link
                                        class="btn btn-sm btn-primary ms-auto"
                                        :to="{ name: 'stations:index', params: { station_id: item.station.id } }"
                                    >
                                        {{ $gettext('Manage') }}
                                    </router-link>
                                </div>
                            </div>
                        </article>
                    </div>
                </div>
            </section>
        </div>
    </dashboard-no-sidebar>
</template>

<script setup lang="ts">
import { useQuery } from "@tanstack/vue-query";
import { computed, ref } from "vue";
import UserInfoPanel from "~/components/Account/UserInfoPanel.vue";
import PlayButton from "~/components/Common/Audio/PlayButton.vue";
import DashboardNoSidebar from "~/components/Layout/DashboardNoSidebar.vue";
import {
    ApiNotification,
    ApiNowPlaying,
    GlobalPermissions,
    HasLinks,
} from "~/entities/ApiInterfaces.ts";
import { QueryKeys } from "~/entities/Queries.ts";
import { useApiRouter } from "~/functions/useApiRouter.ts";
import { useUserAllowed } from "~/functions/useUserAllowed.ts";
import { useAxios } from "~/vendor/axios";
import { useAcaciaRadioDashboardGlobals } from "~/vendor/acacia.ts";
import { useTranslate } from "~/vendor/gettext";
import IconIcAccountCircle from "~icons/ic/baseline-account-circle";
import IconIcHeadphones from "~icons/ic/baseline-headphones";
import IconIcInfo from "~icons/ic/baseline-info";
import IconIcPerson from "~icons/ic/baseline-person";
import IconIcRadio from "~icons/ic/baseline-radio";
import IconIcTrendingUp from "~icons/ic/baseline-trending-up";
import IconIcSettings from "~icons/ic/baseline-settings";
import IconIcWarning from "~icons/ic/baseline-warning";

const { showAlbumArt } = useAcaciaRadioDashboardGlobals();

const { userAllowed } = useUserAllowed();
const showAdmin = userAllowed(GlobalPermissions.View);

const { getApiUrl } = useApiRouter();

const notificationsUrl = getApiUrl("/frontend/dashboard/notifications");
const stationsUrl = getApiUrl("/frontend/dashboard/stations");

const { $gettext } = useTranslate();

const { axios } = useAxios();

const { data: notifications, isLoading: notificationsLoading } = useQuery<
    ApiNotification[]
>({
    queryKey: [QueryKeys.Dashboard, "notifications"],
    queryFn: async ({ signal }) => {
        const { data } = await axios.get<ApiNotification[]>(
            notificationsUrl.value,
            { signal },
        );
        return data;
    },
});

type ApiDashboard = ApiNowPlaying & Required<HasLinks>;

// All stations the user can see (the endpoint returns them unpaginated without paging parameters),
// refreshed with the now-playing data. Feeds both the listener summary and the station cards.
const { data: stationsData, isLoading: stationsLoading } = useQuery<ApiDashboard[]>({
    queryKey: [QueryKeys.Dashboard, "stations", "all"],
    queryFn: async ({ signal }) => {
        const { data } = await axios.get<ApiDashboard[]>(stationsUrl.value, { signal });
        return Array.isArray(data) ? data : [];
    },
    refetchInterval: 15 * 1000,
});

const stations = computed<ApiDashboard[]>(() => stationsData.value ?? []);

const stationSearch = ref("");
const filteredStations = computed(() => {
    const term = stationSearch.value.trim().toLowerCase();
    return term === ""
        ? stations.value
        : stations.value.filter((item) => item.station.name.toLowerCase().includes(term));
});

const summary = computed(() => {
    const list = stations.value;
    const top = [...list].sort((a, b) => b.listeners.total - a.listeners.total)[0];
    return {
        listeners: list.reduce((sum, item) => sum + item.listeners.total, 0),
        unique: list.reduce((sum, item) => sum + item.listeners.unique, 0),
        online: list.filter((item) => item.is_online).length,
        total: list.length,
        topStation: top && top.listeners.total > 0 ? top.station.name : null,
    };
});
</script>
