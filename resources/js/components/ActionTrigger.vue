<script setup>
import { computed, inject } from 'vue'
import TrustedIcon from './TrustedIcon.js'
import { filamentTooltip } from '../support/tooltip.js'
import { LOADING_INDICATORS_KEY, PENDING_CALLS_KEY } from '../support/injectionKeys.js'

const props = defineProps({
    /**
     * The server-serialized trigger (`ActionTriggerSerializer`):
     * `{ name, view, tag, label, labelHidden, class, style, iconHtml,
     * iconPosition, badge, badgeClass, badgeStyle, tooltip, disabled, url,
     * newTab, wireNavigate, extraAttributes }`.
     *
     * `class`/`style` arrive FINISHED — every `fi-*` class was computed
     * server-side with Filament's own component bags, so this template only
     * decides the element structure, never its styling.
     */
    action: {
        type: Object,
        required: true,
    },
    /**
     * The id of the call a click fires (`pendingIds.mountAction(...)`), the
     * island's `wire:target`. Null: no loading state (bulk and url actions).
     */
    pendingId: {
        type: String,
        default: null,
    },
})

const emit = defineEmits(['mount'])

/** @type {?import('../composables/usePendingCalls.js').PendingCalls} */
const pendingCalls = inject(PENDING_CALLS_KEY, null)

/** @type {import('vue').ComputedRef<Record<string, string>>|null} */
const loadingIndicators = inject(LOADING_INDICATORS_KEY, null)

/** Which structural shape to render; anything unknown falls back to a link. */
const view = computed(() => {
    const known = ['link', 'button', 'iconButton', 'badge', 'grouped']

    return known.includes(props.action.view) ? props.action.view : 'link'
})

/** A disabled action never navigates — stock drops the `href` too. */
const tag = computed(() => (props.action.url && ! props.action.disabled ? 'a' : 'button'))

/**
 * The call this trigger fires is in flight. Stock gives every trigger with a
 * Livewire click handler `wire:loading.attr="aria-disabled"` +
 * `wire:loading.class="fi-disabled"` on that exact handler; an url action and
 * a bulk action (an Alpine handler) have none, and get no `pendingId`.
 */
const isPending = computed(() => tag.value === 'button'
    && ! props.action.disabled
    && Boolean(props.pendingId)
    && Boolean(pendingCalls?.isPending(props.pendingId)))

/** Past the loading delay: the icon gives way to Filament's loading indicator. */
const isSpinning = computed(() => isPending.value && Boolean(pendingCalls?.isSpinning(props.pendingId)))

/**
 * The indicator takes the icon's size, as each stock generator passes the
 * same `$iconSize` to both; without an icon, the size the generator falls
 * back to for this view.
 */
const loadingIndicatorHtml = computed(() => {
    const sizes = loadingIndicators?.value ?? {}
    const iconSize = /\bfi-size-(xs|sm|md|lg)\b/.exec(props.action.iconHtml ?? '')?.[1]

    if (iconSize) {
        return sizes[iconSize] ?? null
    }

    const triggerSize = /\bfi-size-(xs|sm|md|lg|xl)\b/.exec(props.action.class ?? '')?.[1] ?? 'md'

    const size = {
        link: ['xs', 'sm'].includes(triggerSize) ? 'sm' : 'md',
        button: ['xs', 'sm'].includes(triggerSize) ? 'sm' : 'md',
        iconButton: triggerSize === 'xs' ? 'sm' : (['lg', 'xl'].includes(triggerSize) ? 'lg' : 'md'),
        badge: 'sm',
        grouped: 'md',
    }[view.value] ?? 'md'

    return sizes[size] ?? null
})

const badgeContainerClass = computed(() => ({
    link: 'fi-link-badge-ctn',
    button: 'fi-btn-badge-ctn',
    iconButton: 'fi-icon-btn-badge-ctn',
}[view.value] ?? null))

/**
 * Stock renders the counter badge inside a container div for link/button/
 * icon-button, and bare for a dropdown item. The `badge` VIEW has no counter
 * badge at all (it IS one).
 */
const hasBadge = computed(
    () => Boolean(props.action.badge) && view.value !== 'badge',
)

const iconBefore = computed(
    () => Boolean(props.action.iconHtml) && props.action.iconPosition !== 'after',
)

const iconAfter = computed(
    () => Boolean(props.action.iconHtml) && props.action.iconPosition === 'after',
)

/**
 * Everything that is the same for every view: the finished class/style, the
 * anchor/button plumbing, the tooltip, and the developer's own
 * `extraAttributes()`. Bound as ONE object so Vue merges the class keys.
 */
const attrs = computed(() => {
    const action = props.action

    return {
        ...(action.extraAttributes ?? {}),
        class: isPending.value ? [action.class, 'fi-disabled'] : action.class,
        style: action.style || undefined,
        type: tag.value === 'button' ? 'button' : undefined,
        href: tag.value === 'a' ? action.url : undefined,
        target: tag.value === 'a' && action.newTab ? '_blank' : undefined,
        rel: tag.value === 'a' && action.newTab ? 'noopener noreferrer' : undefined,
        ...(tag.value === 'a' && action.wireNavigate ? { 'wire:navigate': '' } : {}),
        disabled: tag.value === 'button' && action.disabled ? true : undefined,
        'aria-disabled': (action.disabled || isPending.value) ? 'true' : undefined,
        // `hiddenLabel()` keeps the label only as the accessible name; the
        // icon-button view always does, since it renders no label either.
        'aria-label': (action.labelHidden || view.value === 'iconButton') ? action.label : undefined,
        // Stock puts the plain `title` on an icon button unless it has a tooltip.
        title: (view.value === 'iconButton' && ! action.tooltip) ? action.label : undefined,
        'x-tooltip': filamentTooltip(action.tooltip) ?? undefined,
    }
})

/**
 * Anchors navigate natively (stock does too); only the button form mounts the
 * action on the Livewire component.
 *
 * @param {MouseEvent} event
 * @returns {void}
 */
function onClick(event) {
    if (tag.value === 'a') {
        return
    }

    event.preventDefault()
    event.stopPropagation()

    if (props.action.disabled || isPending.value) {
        return
    }

    emit('mount', props.action.name, event.currentTarget)
}
</script>

<template>
    <component
        :is="tag"
        v-bind="attrs"
        @click="onClick"
    >
        <TrustedIcon
            v-if="isSpinning && ! iconAfter && loadingIndicatorHtml"
            :html="loadingIndicatorHtml"
        />
        <TrustedIcon
            v-else-if="iconBefore"
            :html="action.iconHtml"
        />

        <!-- Link: the label lives in its own span (stock markup). -->
        <span
            v-if="view === 'link' && ! action.labelHidden"
            class="fi-link-label"
        >{{ action.label }}</span>

        <!-- Badge: two nested spans, exactly like `generateBadgeHtml()`. -->
        <span
            v-else-if="view === 'badge'"
            class="fi-badge-label-ctn"
        ><span class="fi-badge-label">{{ action.label }}</span></span>

        <span
            v-else-if="view === 'grouped'"
            class="fi-dropdown-list-item-label"
        >{{ action.label }}</span>

        <!-- Button: the label is a bare text node; icon buttons render none. -->
        <template v-else-if="view === 'button' && ! action.labelHidden">{{ action.label }}</template>

        <TrustedIcon
            v-if="isSpinning && iconAfter && loadingIndicatorHtml"
            :html="loadingIndicatorHtml"
        />
        <TrustedIcon
            v-else-if="iconAfter"
            :html="action.iconHtml"
        />

        <div
            v-if="hasBadge && badgeContainerClass"
            :class="badgeContainerClass"
        >
            <span
                :class="action.badgeClass"
                :style="action.badgeStyle || undefined"
            >{{ action.badge }}</span>
        </div>

        <span
            v-else-if="hasBadge"
            :class="action.badgeClass"
            :style="action.badgeStyle || undefined"
        >{{ action.badge }}</span>
    </component>
</template>
