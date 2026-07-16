<script setup>
import TrustedIcon from '../TrustedIcon.js'

defineProps({
    /**
     * Badge cell payload:
     * `{ type, formatted, color, colorClasses, iconHtml, size }`.
     * `colorClasses` is the exact class string Filament computes server-side
     * (FilamentColor::getComponentClasses for BadgeComponent); `iconHtml` is
     * server-rendered trusted SVG markup.
     */
    cell: {
        type: Object,
        required: true,
    },
})
</script>

<template>
    <div class="fi-ta-text-item fi-ta-text-has-badges fi-ta-text">
        <span :class="[cell.colorClasses || '', 'fi-badge', `fi-size-${cell.size || 'sm'}`]">
            <TrustedIcon :html="cell.iconHtml" />
            {{ cell.formatted }}
        </span>
    </div>
</template>
