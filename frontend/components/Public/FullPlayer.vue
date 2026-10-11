<template>
    <div class="public-page">
        <div class="card">
            <div class="card-body">
                <h2 class="card-title mb-3">
                    {{ stationName }}
                </h2>

                <div class="stations nowplaying">
                    <radio-player
                        v-bind="player"
                        @np_updated="onNowPlayingUpdate"
                    />
                </div>
            </div>
            <div class="card-body buttons pt-0">
                <a
                    v-if="widgetCustomization.showHistoryButton"
                    class="btn btn-link text-secondary"
                    @click.prevent="openSongHistoryModal"
                >
                    <icon-ic-history/>

                    <span>
                        {{ $gettext('Song History') }}
                    </span>
                </a>
                <a
                    v-if="widgetCustomization.showPlaylistButton"
                    class="btn btn-link text-secondary"
                    :href="downloadPlaylistUri"
                >
                    <icon-ic-cloud-download/>

                    <span>
                        {{ $gettext('Playlist') }}
                    </span>
                </a>
            </div>
        </div>
    </div>

    <song-history-modal
        ref="$songHistoryModal"
        :show-album-art="player.showAlbumArt"
        :history="history"
    />

    <lightbox ref="$lightbox" />
</template>

<script setup lang="ts">
import { computed, shallowRef, useTemplateRef } from "vue";
import Lightbox from "~/components/Common/Lightbox.vue";
import SongHistoryModal from "~/components/Public/FullPlayer/SongHistoryModal.vue";
import RadioPlayer, { PlayerProps } from "~/components/Public/Player.vue";
import {
    ApiNowPlaying,
    ApiNowPlayingSongHistory,
} from "~/entities/ApiInterfaces.ts";
import { defaultWidgetSettings } from "~/entities/PublicPlayer.ts";
import { useProvideLightbox } from "~/vendor/lightbox";
import IconIcCloudDownload from "~icons/ic/baseline-cloud-download";
import IconIcHistory from "~icons/ic/baseline-history";

const props = defineProps<{
    stationName: string;
    downloadPlaylistUri: string;
    player: PlayerProps;
}>();

const widgetCustomization = computed(
    () => props.player.widgetCustomization ?? defaultWidgetSettings,
);

const history = shallowRef<ApiNowPlayingSongHistory[]>([]);

const onNowPlayingUpdate = (newNowPlaying: ApiNowPlaying) => {
    history.value = newNowPlaying?.song_history;
};

const $songHistoryModal = useTemplateRef("$songHistoryModal");

const openSongHistoryModal = () => {
    $songHistoryModal.value?.open();
};

const $lightbox = useTemplateRef("$lightbox");

useProvideLightbox($lightbox);
</script>
