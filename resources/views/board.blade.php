@php
    use Filament\Support\Facades\FilamentAsset;

    $config = $this->getKanbanConfig();
@endphp

{{--
    The browser owns the board: cards are drawn from JSON by Alpine and moved
    optimistically; Livewire only answers renderless calls. wire:ignore keeps a
    page re-render (a header action's modal, say) from resetting it, and such a
    render gets the config without the board's state (see getKanbanConfig()).

    The stylesheet arrives with x-load-css, after the first paint: without this
    the markup shows unstyled for a frame (an icon the width of the page).
    kanban.css undoes the rule with a more specific selector once it applies;
    should it never arrive, the animation reveals the board after 1.5 s (CSS
    only: it works under a strict CSP and for a board inserted later).
--}}
<style>.pk { visibility: hidden; animation: pk-reveal 0s 1.5s forwards; } @keyframes pk-reveal { to { visibility: visible; } }</style>
<div
    wire:ignore
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('kanban', 'packstub/filament-kanban') }}"
    x-load-css="[@js(FilamentAsset::getStyleHref('kanban', 'packstub/filament-kanban'))]"
    x-data="packstubKanban(@js($config))"
    x-on:keydown.escape.window="menu = null; clearSelection()"
    x-on:packstub-kanban-refresh.window="refresh(true)"
    x-on:packstub-kanban-undo.window="undo($event.detail)"
    class="pk"
    :class="{ 'pk-is-dragging': dragging }"
>
    <div class="pk-toolbar" x-show="! selected.length">
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
                        <span x-html="labelHtml(column)"></span>
                        <span class="pk-menu-count" x-text="column.count"></span>
                    </label>
                </template>
            </div>
        </div>
    </div>

    {{-- The selection bar takes the toolbar's place while cards are selected. --}}
    <div class="pk-selection" x-show="selected.length" x-cloak>
        <span class="pk-selection-count" x-text="t.selected.replace(':count', selected.length)"></span>

        <div class="pk-menu-wrap">
            <button type="button" class="pk-btn" x-show="bulkTargets().length" x-on:click.stop="menu = menu === 'bulk' ? null : 'bulk'" :aria-expanded="menu === 'bulk'">
                <span x-text="t.move_to + '…'"></span>
            </button>
            <div class="pk-menu" x-show="menu === 'bulk'" x-cloak x-transition.opacity.duration.100ms x-on:click.outside="menu = null">
                <template x-for="target in bulkTargets()" :key="target.name">
                    <button type="button" class="pk-menu-item" x-on:click="menu = null; moveMany(target.name)">
                        <span class="pk-dot" :style="dot(target.color)"></span>
                        <span x-text="target.label"></span>
                    </button>
                </template>
            </div>
        </div>

        <template x-for="action in bulkActions" :key="action.name">
            <button type="button" class="pk-btn" :class="action.color && 'pk-btn-' + action.color" x-on:click="runBulkAction(action.name)">
                <span class="pk-menu-icon" x-show="action.icon" x-html="action.icon"></span>
                <span x-text="action.label"></span>
            </button>
        </template>

        <div class="pk-spacer"></div>

        <button type="button" class="pk-btn" x-on:click="clearSelection()">
            <span x-text="t.clear"></span>
            <kbd>Esc</kbd>
        </button>
    </div>

    <div class="pk-board" x-ref="board" :class="{ 'pk-board-lanes': lanes }" :style="gridStyle()">
        <template x-for="column in columns" :key="column.name">
            <section
                class="pk-col"
                x-show="! hidden.includes(column.name)"
                :data-column="column.name"
                :class="{
                    'pk-col-folded': folded[column.name],
                    'pk-col-target': dragging && dragging.from !== column.name && canDrop(dragging.from, column.name),
                    'pk-col-blocked': dragging && dragging.from !== column.name && ! canDrop(dragging.from, column.name),
                    'pk-col-locked': ! column.draggable,
                    'pk-col-full': isFull(column),
                }"
                x-on:click="if (folded[column.name] && ! $event.target.closest('.pk-fold')) toggleFold(column.name)"
            >
                <header class="pk-col-head" x-on:dblclick="toggleFold(column.name)" :style="headStyle(column)" :title="column.description || null">
                    <span class="pk-dot" :style="dot(column.color)"></span>
                    <span class="pk-col-icon" x-show="column.icon" x-html="column.icon"></span>
                    <div class="pk-col-title">
                        <h3 x-html="labelHtml(column)"></h3>
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
                    <div class="pk-menu-wrap pk-col-menu-wrap" x-show="column.actions && column.actions.length">
                        <button
                            type="button"
                            class="pk-icon-btn pk-col-menu"
                            x-on:click.stop="menu = menu === 'column:' + column.name ? null : 'column:' + column.name"
                            :aria-label="t.column_menu"
                            :aria-expanded="menu === 'column:' + column.name"
                            :title="t.column_menu"
                        >
                            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><circle cx="5" cy="10" r="1.4"/><circle cx="10" cy="10" r="1.4"/><circle cx="15" cy="10" r="1.4"/></svg>
                        </button>
                        <div class="pk-menu pk-menu-end" x-show="menu === 'column:' + column.name" x-cloak x-transition.opacity.duration.100ms x-on:click.outside="menu = null" x-on:dblclick.stop>
                            <template x-for="action in column.actions" :key="action.name">
                                <button type="button" class="pk-menu-item" :class="action.color && 'pk-menu-item-' + action.color" x-on:click.stop="runColumnAction(action.name, column)">
                                    <span class="pk-menu-icon" x-show="action.icon" x-html="action.icon"></span>
                                    <span x-text="action.label"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                    <button type="button" class="pk-icon-btn pk-fold" x-on:click="toggleFold(column.name)" :title="folded[column.name] ? t.expand : t.collapse">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m12 5-5 5 5 5"/></svg>
                    </button>
                </header>

                <template x-for="lane in rows" :key="lane.value ?? '_'">
                <div class="pk-cell" :class="{ 'pk-cell-folded': lanes && foldedLanes[lane.value] }" :style="cellStyle(column, lane)">
                <ol class="pk-cards" :data-column="column.name" :data-lane="lane.value" x-init="bindSortable($el, column, lane)">
                    <template x-for="card in cardsIn(column, lane)" :key="card.id">
                        <li
                            class="pk-card"
                            :data-id="card.id"
                            x-show="matches(card)"
                            :class="{ 'pk-card-pending': card._pending, 'pk-card-flash': card._flash, 'pk-card-accent': card.accent, 'pk-card-selected': isSelected(card.id), 'pk-card-locked': card.draggable === false }"
                            :style="card.accent ? '--pk-card-accent:' + tone(card.accent) : ''"
                        >
                            <button type="button" class="pk-card-check" x-show="hasSelection" x-on:click.stop="toggleSelect(card, column, lane, $event)" :aria-pressed="isSelected(card.id)" :aria-label="t.select_card" :title="t.select_card">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 10.5 3.5 3.5L15 7"/></svg>
                            </button>
                            <a class="pk-card-link" :href="card.url || null" x-on:click="open($event, card, column, lane)" draggable="false">
                                <span class="pk-lock" x-show="card.draggable === false" role="img" :title="t.locked" :aria-label="t.locked">
                                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="4.75" y="8.75" width="10.5" height="8.5" rx="1.5"/><path d="M7 8.75V6.5a3 3 0 0 1 6 0v2.25"/></svg>
                                </span>
                                <div class="pk-card-top" x-show="card.eyebrow || card.aside">
                                    <span class="pk-eyebrow" x-text="card.eyebrow"></span>
                                    <span class="pk-aside" x-text="card.aside"></span>
                                </div>
                                <div class="pk-title" x-show="card.title" x-text="card.title"></div>
                                <div class="pk-description" x-show="card.description" x-text="card.description"></div>
                                <div class="pk-card-foot" x-show="(card.badges && card.badges.length) || (card.meta && card.meta.length) || (card.avatars && card.avatars.length) || card.due || card.progress">
                                    <template x-for="badge in (card.badges || [])" :key="badge.label">
                                        <span class="pk-badge" :style="'--pk-badge:' + tone(badge.color || 'gray')">
                                            <template x-if="badge.icon && icons[badge.icon]"><span class="pk-badge-icon" x-html="icons[badge.icon]"></span></template>
                                            <span x-text="badge.label"></span>
                                        </span>
                                    </template>
                                    <span class="pk-meta" x-text="(card.meta || []).join(' · ')"></span>
                                    <span class="pk-due" x-show="card.due" :class="dueState(card.due)" :title="card.due?.date">
                                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3.25" y="4.75" width="13.5" height="11.5" rx="1.5"/><path d="M3.25 8.75h13.5M7 3v3M13 3v3"/></svg>
                                        <span x-text="card.due?.label"></span>
                                    </span>
                                    <span class="pk-avatars" x-show="card.avatars && card.avatars.length">
                                        <template x-for="(avatar, i) in (card.avatars || [])" :key="i">
                                            <span class="pk-avatar" :title="avatar.name">
                                                <template x-if="avatar.url"><img :src="avatar.url" :alt="avatar.name || ''" loading="lazy"></template>
                                                <template x-if="! avatar.url"><span x-text="initials(avatar.name)"></span></template>
                                            </span>
                                        </template>
                                    </span>
                                    <span class="pk-progress" x-show="card.progress" :title="card.progress?.label" role="progressbar" :aria-valuenow="card.progress ? Math.round(card.progress.value * 100) : null" aria-valuemin="0" aria-valuemax="100" :aria-label="card.progress?.label">
                                        <span class="pk-progress-bar" :class="{ 'pk-progress-full': card.progress && card.progress.value >= 1 }" :style="'width:' + (card.progress ? card.progress.value * 100 : 0) + '%'"></span>
                                    </span>
                                </div>
                            </a>
                            <button
                                type="button"
                                class="pk-card-menu"
                                x-show="hasMenu(card, column)"
                                x-on:click.stop="menu = menu === 'card:' + card.id ? null : 'card:' + card.id"
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
                                <div class="pk-menu-sep" x-show="actionsFor(card).length && canMove(card, column) && targets(column.name).length"></div>
                                <div class="pk-menu-label" x-show="canMove(card, column) && targets(column.name).length" x-text="t.move_to"></div>
                                <template x-for="target in (canMove(card, column) ? targets(column.name) : [])" :key="target.name">
                                    <button type="button" class="pk-menu-item" x-on:click="menu = null; moveTo(card.id, column.name, target.name)">
                                        <span class="pk-dot" :style="dot(target.color)"></span>
                                        <span x-html="labelHtml(target)"></span>
                                    </button>
                                </template>
                            </div>
                        </li>
                    </template>
                </ol>

                <div class="pk-col-empty" x-show="! visibleCount(column, lane)">
                    <span x-text="dragging && canDrop(dragging.from, column.name, dragging.lane, lane.value) ? t.drop_here : (search ? t.no_match : t.empty)"></span>
                </div>

                <button type="button" class="pk-more" :data-column="column.name" :data-lane="lane.value" x-show="loadedIn(column, lane) < countIn(column, lane) && ! folded[column.name] && ! search" x-init="observeMore($el, column, lane)" x-on:click="more(column, lane)" :disabled="loading[cellKey(column, lane)]">
                    <span x-text="t.more"></span>
                    <span x-text="'(' + (countIn(column, lane) - loadedIn(column, lane)) + ')'"></span>
                </button>
                </div>
                </template>
            </section>
        </template>

        {{-- Swimlanes: a header row before each lane's cells; the cells are placed on the grid by the columns above. --}}
        <template x-for="(lane, i) in (lanes || [])" :key="lane.value">
            <div class="pk-lane" :class="{ 'pk-lane-folded': foldedLanes[lane.value] }" :style="laneStyle(i)" :data-lane="lane.value">
                <div class="pk-lane-head" x-on:dblclick="toggleLane(lane.value)">
                    <button type="button" class="pk-icon-btn pk-lane-fold" x-on:click="toggleLane(lane.value)" :title="foldedLanes[lane.value] ? t.unfold_lane : t.fold_lane" :aria-expanded="! foldedLanes[lane.value]">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m5 8 5 5 5-5"/></svg>
                    </button>
                    <span class="pk-dot" :style="dot(lane.color)"></span>
                    <h4 x-text="lane.label"></h4>
                    <span class="pk-count" x-text="laneCount(lane)"></span>
                </div>
            </div>
        </template>
    </div>
</div>
