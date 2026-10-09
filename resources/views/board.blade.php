@php
    use Filament\Support\Facades\FilamentAsset;

    $config = $this->getKanbanConfig();
@endphp

{{--
    The browser owns the board: cards are drawn from JSON by Alpine and moved
    optimistically; Livewire only answers renderless calls. wire:ignore keeps a
    page re-render (a header action's modal, say) from resetting it.

    The stylesheet arrives with x-load-css, after the first paint: without this
    the markup shows unstyled for a frame (an icon the width of the page).
    kanban.css undoes the rule with a more specific selector once it applies.
--}}
<style>.pk { visibility: hidden; }</style>
<div
    wire:ignore
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('kanban', 'packstub/filament-kanban') }}"
    x-load-css="[@js(FilamentAsset::getStyleHref('kanban', 'packstub/filament-kanban'))]"
    x-data="packstubKanban(@js($config))"
    x-on:keydown.escape.window="menu = null"
    x-on:packstub-kanban-refresh.window="refresh(true)"
    class="pk"
    :class="{ 'pk-is-dragging': dragging, 'pk-compact': density === 'compact', 'pk-narrow': narrow }"
>
    {{-- Read by screen readers only: moves, refusals, pages loaded, search results (see announce()). --}}
    <div class="pk-live" x-ref="live" aria-live="polite" aria-atomic="true"></div>

    <div class="pk-toolbar">
        @if ($config['focus'])
            <button type="button" class="pk-icon-btn pk-sidebar-btn" x-on:click="toggleSidebar()" :title="sidebar ? t.hide_sidebar : t.show_sidebar" :aria-pressed="sidebar">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="2.75" y="3.75" width="14.5" height="12.5" rx="2"/><path d="M7.25 3.75v12.5"/></svg>
            </button>
        @endif

        @if ($config['searchable'])
            <label class="pk-search">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="9" cy="9" r="5.25"/><path d="m13 13 3.5 3.5"/></svg>
                <input type="search" x-model="search" x-on:input="queueRefresh()" x-ref="search" :placeholder="t.search + '…'" :aria-label="t.search" autocomplete="off">
                <kbd x-show="! search">/</kbd>
            </label>
        @endif

        <template x-for="filter in filters" :key="filter.name">
            <label class="pk-filter" :class="{ 'pk-filter-on': active[filter.name] }">
                <span x-text="filter.label"></span>
                <select x-model="active[filter.name]" x-on:change="refresh()">
                    <option value="" x-text="t.all"></option>
                    <template x-for="option in filter.options" :key="option.value">
                        <option :value="option.value" x-text="option.label"></option>
                    </template>
                </select>
            </label>
        </template>

        <div class="pk-spacer"></div>

        {{-- A fixed label with the pressed state: "Compact, pressed" while compact, "Compact, not pressed" otherwise. --}}
        <button type="button" class="pk-icon-btn pk-density-btn" x-on:click="toggleDensity()" :title="tr('compact')" :aria-label="tr('compact')" :aria-pressed="density === 'compact'">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3.75 5.25h12.5M3.75 8.5h12.5M3.75 11.75h12.5M3.75 15h12.5"/></svg>
        </button>

        <div class="pk-menu-wrap">
            <button type="button" class="pk-btn" x-on:click.stop="menu = menu === 'columns' ? null : 'columns'" :aria-expanded="menu === 'columns'">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="2.75" y="3.75" width="4" height="12.5" rx="1"/><rect x="8" y="3.75" width="4" height="8.5" rx="1"/><rect x="13.25" y="3.75" width="4" height="10.5" rx="1"/></svg>
                <span x-text="t.columns"></span>
                <span class="pk-btn-count" x-show="hidden.length" x-text="columns.length - hidden.length + '/' + columns.length"></span>
            </button>
            <div class="pk-menu pk-menu-end" x-show="menu === 'columns'" x-cloak x-transition.opacity.duration.100ms x-on:click.outside="menu = null">
                <template x-for="column in columns" :key="column.name">
                    <label class="pk-menu-item">
                        <input type="checkbox" :checked="! hidden.includes(column.name)" x-on:change="toggleHidden(column.name)">
                        <span class="pk-dot" :style="dot(column.color)"></span>
                        <span x-text="column.label"></span>
                        <span class="pk-menu-count" x-text="column.count"></span>
                    </label>
                </template>
            </div>
        </div>
    </div>

    {{-- Narrow screens only (see .pk-narrow): one column at a time, picked here or by a sideways swipe. --}}
    <div class="pk-tabs" role="tablist" :aria-label="tr('columns')" x-ref="tabs" x-on:keydown="tabKeys($event)">
        <template x-for="column in columns" :key="column.name">
            <button
                type="button"
                role="tab"
                class="pk-tab"
                :data-column="column.name"
                x-show="! hidden.includes(column.name)"
                :class="{ 'pk-tab-on': column.name === currentTab() }"
                :aria-selected="column.name === currentTab()"
                :tabindex="column.name === currentTab() ? 0 : -1"
                x-on:click="selectTab(column.name)"
            >
                <span class="pk-dot" :style="dot(column.color)"></span>
                <span x-text="column.label"></span>
                <span class="pk-tab-count" x-text="column.limit !== null ? column.total + '/' + column.limit : column.count"></span>
            </button>
        </template>
    </div>

    <div class="pk-board" x-ref="board" x-on:touchstart.passive="swipeStart($event)" x-on:touchend.passive="swipeEnd($event)">
        <template x-for="column in columns" :key="column.name">
            <section
                class="pk-col"
                role="region"
                x-show="! hidden.includes(column.name)"
                :data-column="column.name"
                :aria-label="columnLabel(column)"
                :class="{
                    'pk-col-folded': folded[column.name] && ! narrow,
                    'pk-col-tab': narrow && column.name === currentTab(),
                    'pk-col-target': dragging && dragging.from !== column.name && canDrop(dragging.from, column.name),
                    'pk-col-blocked': dragging && dragging.from !== column.name && ! canDrop(dragging.from, column.name),
                    'pk-col-locked': ! column.draggable,
                    'pk-col-full': isFull(column),
                }"
                x-on:click="if (folded[column.name] && ! narrow && ! $event.target.closest('.pk-fold')) toggleFold(column.name)"
            >
                <header class="pk-col-head" x-on:dblclick="if (! narrow) toggleFold(column.name)">
                    <span class="pk-dot" :style="dot(column.color)"></span>
                    <div class="pk-col-title">
                        <h3 x-text="column.label"></h3>
                        <span class="pk-summary" x-show="column.summary" x-text="column.summary"></span>
                    </div>
                    <span
                        class="pk-count"
                        x-text="column.limit !== null ? column.total + '/' + column.limit : column.count"
                        :title="column.limit !== null ? t.limit.replace(':limit', column.limit) : null"
                    ></span>
                    <button
                        type="button"
                        class="pk-icon-btn pk-create"
                        x-show="createAction && column.creatable && ! isFull(column)"
                        x-on:click.stop="create(column)"
                        :title="createAction?.label"
                        :aria-label="createAction?.label"
                    >
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M10 4.5v11M4.5 10h11"/></svg>
                    </button>
                    <button type="button" class="pk-icon-btn pk-fold" x-on:click="toggleFold(column.name)" :title="folded[column.name] ? t.expand : t.collapse">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m12 5-5 5 5 5"/></svg>
                    </button>
                </header>

                <ol class="pk-cards" role="list" :data-column="column.name" x-init="bindSortable($el, column)" x-on:keydown="keys($event, column)">
                    <template x-for="card in column.cards" :key="card.id">
                        <li
                            class="pk-card"
                            role="listitem"
                            :data-id="card.id"
                            x-show="matches(card)"
                            :class="{ 'pk-card-pending': card._pending, 'pk-card-flash': card._flash, 'pk-card-accent': card.accent }"
                            :style="card.accent ? '--pk-card-accent:' + tone(card.accent) : ''"
                        >
                            {{-- Without a url the anchor is still focusable (arrows, Shift+F10); with a click action it is a button, Enter and Space run it (see keys()). --}}
                            <a class="pk-card-link" :href="card.url || null" :role="! card.url && clickable(card) ? 'button' : null" :tabindex="card.url ? null : 0" x-on:click="open($event, card)" draggable="false">
                                <div class="pk-card-top" x-show="card.eyebrow || card.aside">
                                    <span class="pk-eyebrow" x-text="card.eyebrow"></span>
                                    <span class="pk-aside" x-text="card.aside"></span>
                                </div>
                                <div class="pk-title" x-show="card.title" x-text="card.title"></div>
                                <div class="pk-card-foot" x-show="(card.badges && card.badges.length) || (card.meta && card.meta.length) || (card.avatars && card.avatars.length)">
                                    <template x-for="badge in (card.badges || [])" :key="badge.label">
                                        <span class="pk-badge" :style="'--pk-badge:' + tone(badge.color || 'gray')" x-text="badge.label"></span>
                                    </template>
                                    <span class="pk-meta" x-text="(card.meta || []).join(' · ')"></span>
                                    <span class="pk-avatars" x-show="card.avatars && card.avatars.length">
                                        <template x-for="(avatar, i) in (card.avatars || [])" :key="i">
                                            <span class="pk-avatar" :title="avatar.name">
                                                <template x-if="avatar.url"><img :src="avatar.url" :alt="avatar.name || ''" loading="lazy"></template>
                                                <template x-if="! avatar.url"><span x-text="initials(avatar.name)"></span></template>
                                            </span>
                                        </template>
                                    </span>
                                </div>
                            </a>
                            <button
                                type="button"
                                class="pk-card-menu"
                                x-show="hasMenu(card, column)"
                                x-on:click.stop="openMenu(card)"
                                :aria-label="t.card_menu"
                                :aria-expanded="menu === 'card:' + card.id"
                                :title="t.card_menu"
                            >
                                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><circle cx="5" cy="10" r="1.4"/><circle cx="10" cy="10" r="1.4"/><circle cx="15" cy="10" r="1.4"/></svg>
                            </button>
                            <div class="pk-menu pk-menu-end pk-card-popover" x-show="menu === 'card:' + card.id" x-cloak x-on:click.outside="menu = null">
                                <template x-for="action in actionsFor(card)" :key="action.name">
                                    <button type="button" class="pk-menu-item" :class="action.color && 'pk-menu-item-' + action.color" x-on:click="runAction(action.name, card)">
                                        <span class="pk-menu-icon" x-show="action.icon" x-html="action.icon"></span>
                                        <span x-text="action.label"></span>
                                    </button>
                                </template>
                                <div class="pk-menu-sep" x-show="actionsFor(card).length && column.draggable && targets(column.name).length"></div>
                                <div class="pk-menu-label" x-show="column.draggable && targets(column.name).length" x-text="t.move_to"></div>
                                <template x-for="target in (column.draggable ? targets(column.name) : [])" :key="target.name">
                                    <button type="button" class="pk-menu-item" x-on:click="menu = null; moveTo(card.id, column.name, target.name)">
                                        <span class="pk-dot" :style="dot(target.color)"></span>
                                        <span x-text="target.label"></span>
                                    </button>
                                </template>
                            </div>
                        </li>
                    </template>
                </ol>

                <div class="pk-col-empty" x-show="! visibleCount(column)">
                    <span x-text="dragging && canDrop(dragging.from, column.name) ? t.drop_here : (search ? t.no_match : t.empty)"></span>
                </div>

                <button type="button" class="pk-more" :data-column="column.name" x-show="column.cards.length < column.count && (! folded[column.name] || narrow) && ! search" x-init="observeMore($el, column)" x-on:click="more(column)" :disabled="loading[column.name]">
                    <span x-text="t.more"></span>
                    <span x-text="'(' + (column.count - column.cards.length) + ')'"></span>
                </button>
            </section>
        </template>
    </div>
</div>
