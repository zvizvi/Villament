<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import TrustedIcon from './TrustedIcon.js'

const props = defineProps({
    /**
     * Grouping config from the schema (`schema.grouping`): `{ groups: [{ id,
     * label }], default, directionSettingHidden, settingsInDropdownOnDesktop }`.
     */
    grouping: {
        type: Object,
        required: true,
    },
    /**
     * Current `tableGrouping` string (`"id"` or `"id:desc"`), or null. Mirrors
     * Filament's own `tableGrouping` Livewire property.
     */
    modelValue: {
        type: String,
        default: null,
    },
    /**
     * Localized UI strings (already resolved with English fallbacks):
     * `groupTrigger`, `groupBy`, `groupDirection`, `groupDirectionAsc`,
     * `groupDirectionDesc`.
     */
    strings: {
        type: Object,
        required: true,
    },
    /**
     * Server-rendered icon HTML map (`schema.icons`): `group` (trigger).
     */
    icons: {
        type: Object,
        default: () => ({}),
    },
})

const emit = defineEmits(['update:modelValue'])

const isOpen = ref(false)
const root = ref(null)

/**
 * Stock default (false): the mobile dropdown (`sm:fi-hidden`) plus inline
 * fields on desktop. True: the dropdown at every breakpoint, no inline fields.
 */
const settingsInDropdownOnDesktop = computed(() => Boolean(props.grouping.settingsInDropdownOnDesktop))

/** The selected group id, parsed from the `"id:direction"` model value. */
const selectedGroup = computed(() => {
    if (! props.modelValue) {
        return ''
    }

    return props.modelValue.split(':')[0]
})

/**
 * The selected direction (defaults to `asc` when the model omits it), matching
 * `getTableGroupingDirection()`.
 */
const selectedDirection = computed(() => {
    if (! props.modelValue || ! props.modelValue.includes(':')) {
        return 'asc'
    }

    return props.modelValue.split(':')[1] === 'desc' ? 'desc' : 'asc'
})

/** Whether the direction select shows (a group is chosen and it isn't hidden). */
const showsDirection = computed(
    () => selectedGroup.value !== '' && ! props.grouping.directionSettingHidden,
)

/**
 * Recomposes the `tableGrouping` string from a group id + direction, emitting
 * null when no group is selected (stock: `group ? id:direction : null`).
 *
 * @param {string} group
 * @param {string} direction
 * @returns {void}
 */
function emitGrouping(group, direction) {
    emit('update:modelValue', group ? `${group}:${direction}` : null)
}

/**
 * @param {Event} event
 * @returns {void}
 */
function onGroupChange(event) {
    emitGrouping(event.target.value, selectedDirection.value)
}

/**
 * @param {Event} event
 * @returns {void}
 */
function onDirectionChange(event) {
    emitGrouping(selectedGroup.value, event.target.value)
}

/**
 * @param {MouseEvent} event
 * @returns {void}
 */
function onDocumentPointerDown(event) {
    if (isOpen.value && root.value && ! root.value.contains(event.target)) {
        isOpen.value = false
    }
}

/**
 * @param {KeyboardEvent} event
 * @returns {void}
 */
function onDocumentKeydown(event) {
    if (event.key === 'Escape' && isOpen.value) {
        isOpen.value = false
    }
}

document.addEventListener('mousedown', onDocumentPointerDown)
document.addEventListener('keydown', onDocumentKeydown)

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocumentPointerDown)
    document.removeEventListener('keydown', onDocumentKeydown)
})
</script>

<template>
    <div
        ref="root"
        class="fi-ta-grouping-settings villament-grouping"
    >
        <!--
            Mobile dropdown (stock: `sm:fi-hidden` when the desktop shows the
            inline fields; visible at every breakpoint otherwise).
        -->
        <!--
            `villament-dropdown` + `-start`: the island has no Alpine/floating-ui,
            so the panel is CSS-anchored (relative wrapper + `inset-block-start:
            calc(100% + 0.5rem)`) below the trigger with stock's 8px offset,
            opening toward inline-end like stock's `placement.bottom-start`.
        -->
        <div
            class="fi-dropdown villament-dropdown villament-dropdown-start"
            :class="{ 'sm:fi-hidden': ! settingsInDropdownOnDesktop }"
        >
            <!--
                Stock binds the toggle to the WRAPPER, not the button (the
                trigger action's own click handler is disabled server-side) —
                so the Filament-rendered trigger drops straight in, with every
                `groupRecordsTriggerAction()` customization intact.
            -->
            <div
                class="fi-dropdown-trigger"
                :aria-expanded="isOpen ? 'true' : 'false'"
                @click="isOpen = ! isOpen"
            >
                <TrustedIcon
                    v-if="grouping.triggerHtml"
                    :html="grouping.triggerHtml"
                />

                <button
                    v-else
                    type="button"
                    class="fi-icon-btn fi-size-md fi-ac-icon-btn-action fi-force-enabled"
                    :title="strings.groupTrigger"
                    :aria-label="strings.groupTrigger"
                >
                    <TrustedIcon :html="icons.group" />
                </button>
            </div>

            <div
                v-if="isOpen"
                class="fi-dropdown-panel fi-width-xs"
            >
                <div class="fi-ta-grouping-settings-fields">
                    <label>
                        <span>{{ strings.groupBy }}</span>

                        <div class="fi-input-wrp">
                            <div class="fi-input-wrp-content-ctn">
                                <select
                                    class="fi-select-input"
                                    :value="selectedGroup"
                                    @change="onGroupChange"
                                >
                                    <option value="">
                                        -
                                    </option>

                                    <option
                                        v-for="group in grouping.groups"
                                        :key="group.id"
                                        :value="group.id"
                                    >
                                        {{ group.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </label>

                    <label v-if="showsDirection">
                        <span>{{ strings.groupDirection }}</span>

                        <div class="fi-input-wrp">
                            <div class="fi-input-wrp-content-ctn">
                                <select
                                    class="fi-select-input"
                                    :value="selectedDirection"
                                    @change="onDirectionChange"
                                >
                                    <option value="asc">
                                        {{ strings.groupDirectionAsc }}
                                    </option>

                                    <option value="desc">
                                        {{ strings.groupDirectionDesc }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </label>
                </div>
            </div>
        </div>

        <!--
            Desktop inline fields (stock: rendered unless grouping settings are
            configured to stay in the dropdown on desktop). The group select
            carries a prefix label; the direction label is screen-reader only.
        -->
        <div
            v-if="! settingsInDropdownOnDesktop"
            class="fi-ta-grouping-settings-fields"
        >
            <label>
                <div class="fi-input-wrp">
                    <div class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-input-wrp-prefix-has-label">
                        <span class="fi-input-wrp-label">
                            {{ strings.groupBy }}
                        </span>
                    </div>

                    <div class="fi-input-wrp-content-ctn">
                        <select
                            class="fi-select-input"
                            :value="selectedGroup"
                            @change="onGroupChange"
                        >
                            <option value="">
                                -
                            </option>

                            <option
                                v-for="group in grouping.groups"
                                :key="group.id"
                                :value="group.id"
                            >
                                {{ group.label }}
                            </option>
                        </select>
                    </div>
                </div>
            </label>

            <label v-if="showsDirection">
                <span class="fi-sr-only">
                    {{ strings.groupDirection }}
                </span>

                <div class="fi-input-wrp">
                    <div class="fi-input-wrp-content-ctn">
                        <select
                            class="fi-select-input"
                            :value="selectedDirection"
                            @change="onDirectionChange"
                        >
                            <option value="asc">
                                {{ strings.groupDirectionAsc }}
                            </option>

                            <option value="desc">
                                {{ strings.groupDirectionDesc }}
                            </option>
                        </select>
                    </div>
                </div>
            </label>
        </div>
    </div>
</template>
