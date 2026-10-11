<template>
    <footer id="footer">
        {{ $gettext('Powered by') }}
        <a
            href="https://www.azuracast.com/"
            target="_blank"
        >AzuraCast</a>
        &bull;
        <span v-html="version"/>
        &bull;
        <span v-html="platform"/>
        &bull;
        <a
            href="/source"
            :title="$gettext('Download the source code of this version (GNU AGPL v3)')"
        >{{ $gettext('Source code') }}</a>
    </footer>
</template>

<script setup lang="ts">
import { useAzuraCastDashboardGlobals } from "~/vendor/azuracast.ts";

const { version, platform } = useAzuraCastDashboardGlobals();
</script>
