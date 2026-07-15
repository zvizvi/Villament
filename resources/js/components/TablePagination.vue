<script setup>
import { computed } from 'vue'

const props = defineProps({
    /**
     * Pagination meta: `{ currentPage, perPage, total, lastPage, from, to }`.
     * `total`/`lastPage` may be null (simple/cursor pagination, future) —
     * the component then degrades to prev/next only.
     */
    meta: {
        type: Object,
        required: true,
    },
    page: {
        type: Number,
        required: true,
    },
    perPage: {
        type: Number,
        default: null,
    },
    pageOptions: {
        type: Array,
        default: () => [],
    },
    recordsCount: {
        type: Number,
        default: 0,
    },
})

const emit = defineEmits(['update:page', 'update:perPage'])

const isSimple = computed(() => props.meta.total === null || props.meta.total === undefined)

const lastPage = computed(() => props.meta.lastPage ?? null)

const hasMorePages = computed(() => {
    if (lastPage.value !== null) {
        return props.page < lastPage.value
    }

    // No length-aware meta: assume more pages while the current page is full.
    const effectivePerPage = props.meta.perPage ?? props.perPage

    return effectivePerPage ? props.recordsCount >= effectivePerPage : false
})

/**
 * Windowed page element list, Laravel-paginator style:
 * `[1, 2, '...', 9, 10, 11, '...', 49, 50]`.
 */
const pageElements = computed(() => {
    const last = lastPage.value

    if (last === null || last <= 1) {
        return []
    }

    if (last <= 10) {
        return range(1, last)
    }

    const current = props.page
    const onEachSide = 2

    if (current <= onEachSide + 3) {
        return [...range(1, onEachSide + 4), '...', ...range(last - 1, last)]
    }

    if (current >= last - (onEachSide + 2)) {
        return [...range(1, 2), '...', ...range(last - (onEachSide + 3), last)]
    }

    return [
        ...range(1, 2),
        '...',
        ...range(current - onEachSide, current + onEachSide),
        '...',
        ...range(last - 1, last),
    ]
})

/**
 * @param {number} start
 * @param {number} end
 * @returns {number[]} Inclusive integer range.
 */
function range(start, end) {
    const values = []

    for (let value = start; value <= end; value++) {
        values.push(value)
    }

    return values
}

/**
 * @param {number} page
 * @returns {void}
 */
function goToPage(page) {
    if (page >= 1 && page !== props.page && (lastPage.value === null || page <= lastPage.value)) {
        emit('update:page', page)
    }
}

/**
 * @param {Event} event
 * @returns {void}
 */
function onPerPageChange(event) {
    emit('update:perPage', Number.parseInt(event.target.value, 10))
}
</script>

<template>
    <nav
        role="navigation"
        aria-label="Pagination"
        class="fi-pagination"
        :class="{ 'fi-simple': isSimple }"
    >
        <button
            v-if="page > 1"
            type="button"
            rel="prev"
            class="fi-btn fi-size-md fi-pagination-previous-btn"
            @click="goToPage(page - 1)"
        >
            Previous
        </button>

        <span
            v-if="! isSimple"
            class="fi-pagination-overview"
        >
            Showing {{ meta.from ?? 0 }} to {{ meta.to ?? 0 }} of {{ meta.total }} results
        </span>

        <div
            v-if="pageOptions.length > 1"
            class="fi-pagination-records-per-page-select-ctn"
        >
            <label class="fi-pagination-records-per-page-select fi-compact">
                <div class="fi-input-wrp">
                    <select
                        class="fi-select-input"
                        :value="perPage ?? meta.perPage"
                        @change="onPerPageChange"
                    >
                        <option
                            v-for="option in pageOptions"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}
                        </option>
                    </select>
                </div>

                <span class="fi-sr-only">Per page</span>
            </label>

            <label class="fi-pagination-records-per-page-select">
                <div class="fi-input-wrp">
                    <div class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-input-wrp-prefix-has-label">
                        <span class="fi-input-wrp-label">Per page</span>
                    </div>

                    <select
                        class="fi-select-input"
                        :value="perPage ?? meta.perPage"
                        @change="onPerPageChange"
                    >
                        <option
                            v-for="option in pageOptions"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}
                        </option>
                    </select>
                </div>
            </label>
        </div>

        <button
            v-if="hasMorePages"
            type="button"
            rel="next"
            class="fi-btn fi-size-md fi-pagination-next-btn"
            @click="goToPage(page + 1)"
        >
            Next
        </button>

        <ol
            v-if="! isSimple && pageElements.length > 1"
            class="fi-pagination-items"
        >
            <li
                v-if="page > 1"
                class="fi-pagination-item"
            >
                <button
                    type="button"
                    rel="prev"
                    aria-label="Previous"
                    class="fi-pagination-item-btn"
                    @click="goToPage(page - 1)"
                >
                    <!-- Horizontal chevron: flipped in RTL via .fi-vue-ta-directional-icon. -->
                    <svg
                        class="fi-icon fi-size-md fi-pagination-item-icon fi-vue-ta-directional-icon"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M15.75 19.5 8.25 12l7.5-7.5"
                        />
                    </svg>
                </button>
            </li>

            <li
                v-for="(element, index) in pageElements"
                :key="`${element}-${index}`"
                class="fi-pagination-item"
                :class="{
                    'fi-active': element === page,
                    'fi-disabled': element === '...',
                }"
            >
                <button
                    type="button"
                    :disabled="element === '...'"
                    :aria-label="element === '...' ? undefined : `Go to page ${element}`"
                    class="fi-pagination-item-btn"
                    @click="element !== '...' && goToPage(element)"
                >
                    <span class="fi-pagination-item-label">{{ element }}</span>
                </button>
            </li>

            <li
                v-if="hasMorePages"
                class="fi-pagination-item"
            >
                <button
                    type="button"
                    rel="next"
                    aria-label="Next"
                    class="fi-pagination-item-btn"
                    @click="goToPage(page + 1)"
                >
                    <svg
                        class="fi-icon fi-size-md fi-pagination-item-icon fi-vue-ta-directional-icon"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="m8.25 4.5 7.5 7.5-7.5 7.5"
                        />
                    </svg>
                </button>
            </li>
        </ol>
    </nav>
</template>
