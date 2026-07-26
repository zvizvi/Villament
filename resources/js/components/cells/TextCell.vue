<script setup>
import { computed, ref } from 'vue'
import { filamentTooltip } from '../../support/tooltip.js'

const props = defineProps({
    /**
     * Text cell payload:
     * `{ type, formatted, empty, placeholder, descriptionAbove, description,
     * tooltip, size, extraAttributes }`, plus the `listWithLineBreaks()` /
     * `bulleted()` / `limitList()` family: `{ items, bulleted,
     * listWithLineBreaks, listLimit, overLimitCount, listExpandable,
     * listLimitMessage, listExpandMessage, listCollapseMessage }`.
     */
    cell: {
        type: Object,
        required: true,
    },
})

/**
 * `tooltip()` on a filled cell, `emptyTooltip()` on a blank one — the server
 * has already picked which of the two this is.
 */
const tooltip = computed(() => filamentTooltip(props.cell.tooltip))

const hasDescriptions = computed(() => Boolean(props.cell.descriptionAbove || props.cell.description))

/**
 * A cell renders as a list only when the server said so — `items` is absent
 * for every ordinary cell, including one whose values were comma-joined.
 */
const items = computed(() => props.cell.items ?? null)

const overLimitCount = computed(() => props.cell.overLimitCount ?? 0)

const sizeClass = computed(() => `fi-size-${props.cell.size || 'sm'}`)

/**
 * Stock's fast path: one item, no bullets, no descriptions and nothing hidden
 * behind a limit collapses the whole cell into a single element.
 */
const isSimple = computed(() => items.value === null && ! hasDescriptions.value && overLimitCount.value === 0)

/**
 * Descriptions and the "+N more" message both need somewhere to sit BESIDE
 * the value(s), so stock boxes the cell in a wrapper `<div>` and demotes the
 * value to a `<p>` or a `<ul>` inside it.
 */
const isBoxed = computed(() => hasDescriptions.value || overLimitCount.value > 0)

const wrapperClasses = computed(() => [
    'fi-ta-text',
    props.cell.inline ? 'fi-inline' : null,
    props.cell.bulleted ? 'fi-bulleted' : null,
    props.cell.listWithLineBreaks ? 'fi-ta-text-has-line-breaks' : null,
    hasDescriptions.value ? 'fi-ta-text-has-descriptions' : null,
    overLimitCount.value > 0 ? 'fi-ta-text-list-limited' : null,
].filter(Boolean))

/**
 * Only an EXPANDABLE list still holds its overflow: everything else was
 * sliced server-side, so every item it has is meant to be visible.
 */
const isLimited = ref(true)

/**
 * @param {number} index
 * @returns {boolean}
 */
function isItemVisible(index) {
    if (! props.cell.listExpandable || ! isLimited.value) {
        return true
    }

    return index < (props.cell.listLimit ?? Infinity)
}
</script>

<template>
    <!-- Blank state renders no text item — just the optional placeholder (stock). -->
    <div
        v-if="cell.empty"
        v-bind="cell.extraAttributes ?? {}"
        class="fi-ta-text"
        :class="cell.inline ? 'fi-inline' : null"
        :x-tooltip="tooltip || undefined"
    >
        <p
            v-if="cell.placeholder"
            class="fi-ta-placeholder"
        >
            {{ cell.placeholder }}
        </p>
    </div>

    <!-- Stock: a single wrapper carrying size + item + text classes, raw text inside. -->
    <div
        v-else-if="isSimple"
        v-bind="cell.extraAttributes ?? {}"
        class="fi-ta-text-item fi-ta-text"
        :class="[sizeClass, cell.inline ? 'fi-inline' : null]"
    >
        <span
            v-if="tooltip"
            :x-tooltip="tooltip"
        >{{ cell.formatted }}</span>
        <template v-else>{{ cell.formatted }}</template>
    </div>

    <!--
        With a description or a hidden remainder, stock drops that fast path:
        the wrapper keeps only the text classes and the value moves into its
        own `<p>` (or `<ul>`), flanked by the description paragraphs and the
        "+N more" message.
    -->
    <div
        v-else-if="isBoxed"
        v-bind="cell.extraAttributes ?? {}"
        :class="wrapperClasses"
    >
        <p
            v-if="cell.descriptionAbove"
            class="fi-ta-text-description"
        >
            {{ cell.descriptionAbove }}
        </p>

        <ul v-if="items">
            <li
                v-for="(item, index) in items"
                v-show="isItemVisible(index)"
                :key="index"
                class="fi-ta-text-item"
                :class="sizeClass"
            >
                <span
                    v-if="tooltip"
                    :x-tooltip="tooltip"
                >{{ item }}</span>
                <template v-else>{{ item }}</template>
            </li>
        </ul>

        <p
            v-else
            class="fi-ta-text-item"
            :class="sizeClass"
        >
            <span
                v-if="tooltip"
                :x-tooltip="tooltip"
            >{{ cell.formatted }}</span>
            <template v-else>{{ cell.formatted }}</template>
        </p>

        <!--
            Stock keeps these as `<div role="button">` rather than real
            buttons: the cell content may already be wrapped in an `<a>` or
            `<button>` by the row, and interactive content cannot nest inside
            one. `aria-expanded` carries the state instead.
        -->
        <div
            v-if="overLimitCount"
            class="fi-ta-text-list-limited-message"
        >
            <template v-if="cell.listExpandable">
                <div
                    v-show="isLimited"
                    role="button"
                    :aria-expanded="(! isLimited).toString()"
                    class="fi-link fi-size-xs"
                    @click.prevent.stop="isLimited = false"
                >
                    {{ cell.listExpandMessage }}
                </div>

                <div
                    v-show="! isLimited"
                    role="button"
                    :aria-expanded="(! isLimited).toString()"
                    class="fi-link fi-size-xs"
                    @click.prevent.stop="isLimited = true"
                >
                    {{ cell.listCollapseMessage }}
                </div>
            </template>

            <template v-else>{{ cell.listLimitMessage }}</template>
        </div>

        <p
            v-if="cell.description"
            class="fi-ta-text-description"
        >
            {{ cell.description }}
        </p>
    </div>

    <!-- A plain list: the wrapper IS the `<ul>`. -->
    <ul
        v-else
        v-bind="cell.extraAttributes ?? {}"
        :class="wrapperClasses"
    >
        <li
            v-for="(item, index) in items"
            :key="index"
            class="fi-ta-text-item"
            :class="sizeClass"
        >
            <span
                v-if="tooltip"
                :x-tooltip="tooltip"
            >{{ item }}</span>
            <template v-else>{{ item }}</template>
        </li>
    </ul>
</template>
