<template>
    <footer id="footer">
        {{ $gettext('Powered by') }}
        Acacia Radio
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
import { useAcaciaRadioDashboardGlobals } from "~/vendor/acacia.ts";

const { version, platform } = useAcaciaRadioDashboardGlobals();
</script>
