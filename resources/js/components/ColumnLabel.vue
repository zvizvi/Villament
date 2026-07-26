<script setup>
/**
 * A column's header label, HTML or plain.
 *
 * Stock echoes `$column->getLabel()` straight into the `<th>`, so a label set
 * to an `Htmlable` renders as markup — which is how a plugin decorates a
 * header without touching the table view (`->label(new HtmlString(...))`).
 * Escaping it, as `{{ }}` in Vue does, would print the tags. `labelHtml` is
 * present only for those labels; everything else stays plain text.
 */
import TrustedHtml from './TrustedHtml.js'

defineProps({
    column: {
        type: Object,
        required: true,
    },
})
</script>

<template>
    <TrustedHtml
        v-if="column.labelHtml"
        :html="column.labelHtml"
    />

    <template v-else>{{ column.label }}</template>
</template>
