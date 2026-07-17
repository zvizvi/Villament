<script setup>
import TrustedIcon from './TrustedIcon.js'

defineProps({
    /**
     * Active filter count for the badge (filter-type indicators only).
     */
    activeCount: {
        type: Number,
        default: 0,
    },
    /**
     * Localized UI strings (already resolved with English fallbacks).
     */
    strings: {
        type: Object,
        required: true,
    },
    /**
     * Server-rendered icon HTML map (`schema.icons`): `filters` (funnel).
     */
    icons: {
        type: Object,
        default: () => ({}),
    },
})

const emit = defineEmits(['toggle'])
</script>

<template>
    <!--
        Funnel trigger for the native Livewire filters panel. Byte-identical
        to Filament's stock filters trigger; the panel it opens is Filament's
        own filters component (rendered in Blade, Livewire-morphable). No
        filter UI exists in Vue.
    -->
    <div class="fi-dropdown fi-ta-filters-dropdown villament-filters-trigger">
        <div class="fi-dropdown-trigger">
            <button
                type="button"
                class="fi-icon-btn fi-size-md fi-ac-icon-btn-action fi-force-enabled"
                :title="strings.filtersTrigger"
                :aria-label="strings.filtersTrigger"
                @click="emit('toggle')"
            >
                <TrustedIcon :html="icons.filters" />

                <!-- Stock renders the count badge unconditionally (0 included). -->
                <div class="fi-icon-btn-badge-ctn">
                    <span class="fi-badge fi-size-xs">
                        {{ activeCount }}
                    </span>
                </div>
            </button>
        </div>
    </div>
</template>
