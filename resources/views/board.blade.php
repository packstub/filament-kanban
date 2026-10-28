@php
    use Filament\Support\Facades\FilamentAsset;
    use Filament\Support\Icons\Heroicon;

    $config = $this->getKanbanConfig();
    $hasFilters = $config['filters'] !== [];
    $filtersLayout = $config['filtersLayout'];

    // The filters are Filament form components, rendered through a schema.
    if (! $this instanceof \Filament\Schemas\Contracts\HasSchemas) {
        throw new \LogicException(static::class . ' must implement Filament\Schemas\Contracts\HasSchemas and use Filament\Schemas\Concerns\InteractsWithSchemas (the Kanban pages do).');
    }
@endphp

{{--
    The browser owns the board: cards are drawn from JSON by Alpine and moved
    optimistically; Livewire only answers renderless calls. wire:ignore keeps a
    page re-render (a header action's modal, say) from resetting it, and such a
    render gets the config without the board's state (see getKanbanConfig()).

    The shell is a table's: a container with a toolbar (the component's own
    controls at the start; search, filters and view toggles at the end), an
    indicator strip for the selection, the column tabs on narrow screens, then
    the board. The controls are Filament's own Blade components (button,
    icon-button, input wrapper + input, select, date picker, checkbox, badge,
    dropdown, tabs), rendered once and driven by Alpine; the ps-kanban-* classes
    beside theirs are the board's hooks.

    The stylesheet arrives with x-load-css, after the first paint: without this
    the markup shows unstyled for a frame (an icon the width of the page).
    kanban.css undoes the rule with a more specific selector once it applies;
    should it never arrive, the animation reveals the board after 1.5 s (CSS
    only: it works under a strict CSP and for a board inserted later).
--}}
<style>.ps-kanban { visibility: hidden; animation: ps-kanban-reveal 0s 1.5s forwards; } @keyframes ps-kanban-reveal { to { visibility: visible; } }</style>
<div
    wire:ignore
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('kanban', 'packstub/filament-kanban') }}"
    x-load-css="[@js(FilamentAsset::getStyleHref('kanban', 'packstub/filament-kanban'))]"
    x-data="packstubKanban(@js($config))"
    x-on:keydown.escape.window="menu = null; clearSelection()"
    x-on:packstub-kanban-refresh.window="refresh(true)"
    x-on:packstub-kanban-undo.window="undo($event.detail)"
    x-on:ps-kanban-filter="setFilter($event.detail.name, $event.detail.value, $event.detail.edge)"
    x-on:scroll.capture.passive="menuScrolled()"
    x-on:scroll.window.passive="menuScrolled()"
    x-on:resize.window.passive="menuScrolled()"
    class="ps-kanban"
    :class="{ 'ps-kanban-is-dragging': dragging, 'ps-kanban-compact': density === 'compact', 'ps-kanban-narrow': narrow }"
>
    {{-- Read by screen readers only: moves, refusals, pages loaded, search results (see announce()). --}}
    <div class="ps-kanban-live ps-kanban-sr" x-ref="live" aria-live="polite" aria-atomic="true"></div>

    <div class="ps-kanban-toolbar">
        <div class="ps-kanban-toolbar-start">
            @if ($config['focus'])
                <x-filament::icon-button
                    color="gray"
                    :icon="Heroicon::OutlinedBars3BottomLeft"
                    :label="__('packstub-kanban::kanban.show_sidebar')"
                    class="ps-kanban-sidebar-btn"
                    x-on:click="toggleSidebar()"
                    x-bind:title="sidebar ? t.hide_sidebar : t.show_sidebar"
                    x-bind:aria-pressed="sidebar"
                />
            @endif
        </div>

        <div class="ps-kanban-toolbar-end">
            @if ($config['searchable'])
                <div class="ps-kanban-search">
                    <x-filament::input.wrapper inline-prefix :prefix-icon="Heroicon::MagnifyingGlass" class="ps-kanban-field">
                        <x-filament::input type="search" inline-prefix autocomplete="off" x-model="search" x-on:input="queueRefresh()" x-ref="search" x-bind:placeholder="t.search" x-bind:aria-label="t.search" x-bind:title="t.search + ' (/)'" />
                    </x-filament::input.wrapper>
                </div>
            @endif

            {{-- the filters inline (filtersLayout toolbar) --}}
            @if ($hasFilters && $filtersLayout === 'toolbar')
                <div class="ps-kanban-filters-inline">
                    {{ $this->getSchema('kanbanFiltersForm') }}
                </div>
            @endif

            {{-- the filters behind a funnel button (filtersLayout dropdown): the count of active ones on the button, a reset link in the panel --}}
            @if ($hasFilters && $filtersLayout === 'dropdown')
                {{-- a panel of fields, not a menu: Filament 5.10+ would take the keys typed in a select's search for menu navigation --}}
                <x-filament::dropdown placement="bottom-end" width="xs" class="ps-kanban-filters-dropdown" :menu="false">
                    <x-slot name="trigger">
                        {{-- the count sits on the button's corner, in the icon button's own badge container, as a table's filter trigger --}}
                        <x-filament::icon-button color="gray" :icon="Heroicon::OutlinedFunnel" :label="__('packstub-kanban::kanban.filters')" class="ps-kanban-filters-btn">
                            <x-slot name="badge">
                                <x-filament::badge size="xs" color="primary" class="ps-kanban-filters-count" x-show="activeFilterCount()"><span x-text="activeFilterCount()"></span></x-filament::badge>
                            </x-slot>
                        </x-filament::icon-button>
                    </x-slot>
                    {{-- a table's filters panel: its heading, the reset link, Filament's fields (kanbanFiltersForm) --}}
                    <div class="fi-ta-filters ps-kanban-filters-panel">
                        <div class="fi-ta-filters-header">
                            <h4 class="fi-ta-filters-heading" x-text="t.filters"></h4>
                            <x-filament::link tag="button" color="danger" size="sm" class="ps-kanban-filters-reset" x-show="activeFilterCount()" x-on:click="resetFilters()">
                                <span x-text="t.reset_filters"></span>
                            </x-filament::link>
                        </div>
                        {{ $this->getSchema('kanbanFiltersForm') }}
                    </div>
                </x-filament::dropdown>
            @endif

            {{-- A fixed label with the pressed state: "Compact, pressed" while compact, "Compact, not pressed" otherwise; the tooltip names what a click gives. --}}
            <x-filament::icon-button
                color="gray"
                :icon="Heroicon::OutlinedBars4"
                :label="__('packstub-kanban::kanban.compact')"
                class="ps-kanban-density-btn"
                x-on:click="toggleDensity()"
                x-bind:title="tr(density === 'compact' ? 'comfortable' : 'compact')"
                x-bind:aria-pressed="density === 'compact'"
            />

            {{-- the columns manager: which columns are shown, the hidden count on the button --}}
            <x-filament::dropdown placement="bottom-end" width="xs" class="ps-kanban-columns-dropdown">
                <x-slot name="trigger">
                    <x-filament::icon-button color="gray" :icon="Heroicon::OutlinedViewColumns" :label="__('packstub-kanban::kanban.columns')" class="ps-kanban-columns-btn">
                        <x-slot name="badge">
                            <x-filament::badge size="xs" color="primary" class="ps-kanban-columns-count" x-show="hidden.length"><span x-text="hidden.length"></span></x-filament::badge>
                        </x-slot>
                    </x-filament::icon-button>
                </x-slot>
                <div class="ps-kanban-columns">
                    <h4 class="ps-kanban-columns-heading" x-text="t.columns"></h4>
                    <template x-for="column in columns" :key="column.name">
                        <label class="ps-kanban-columns-item">
                            <x-filament::input.checkbox x-bind:checked="! hidden.includes(column.name)" x-on:change="toggleHidden(column.name)" />
                            <span class="ps-kanban-dot" :style="dot(column.color)"></span>
                            <span class="ps-kanban-columns-label" x-html="labelHtml(column)"></span>
                            <span class="ps-kanban-columns-count-text" x-text="column.count"></span>
                        </label>
                    </template>
                </div>
            </x-filament::dropdown>
        </div>
    </div>

    {{-- the filters on a row of their own (filtersLayout above) --}}
    @if ($hasFilters && $filtersLayout === 'above')
        <div class="fi-ta-filters ps-kanban-filters-above">
            <div class="fi-ta-filters-header">
                <h4 class="fi-ta-filters-heading" x-text="t.filters"></h4>
                <x-filament::link tag="button" color="danger" size="sm" class="ps-kanban-filters-reset" x-show="activeFilterCount()" x-on:click="resetFilters()">
                    <span x-text="t.reset_filters"></span>
                </x-filament::link>
            </div>
            {{ $this->getSchema('kanbanFiltersForm') }}
        </div>
    @endif

    {{-- The selection: an indicator strip under the toolbar while cards are selected. --}}
    <div class="ps-kanban-indicator ps-kanban-selection" x-show="selected.length" x-cloak>
        <span class="ps-kanban-selection-count" x-text="t.selected.replace(':count', selected.length)"></span>

        <div class="ps-kanban-indicator-end">
            <div class="ps-kanban-menu-wrap" x-show="bulkTargets().length">
                <x-filament::button color="gray" size="sm" :icon="Heroicon::ChevronDown" icon-position="after" class="ps-kanban-bulk-move" x-on:click.stop="menu = menu === 'bulk' ? null : 'bulk'" x-bind:aria-expanded="menu === 'bulk'">
                    <span x-text="t.move_to + '…'"></span>
                </x-filament::button>
                <div class="ps-kanban-menu ps-kanban-menu-end fi-dropdown-panel" role="menu" x-show="menu === 'bulk'" x-cloak x-transition.opacity.duration.100ms x-on:click.outside="menu = null">
                    <div class="fi-dropdown-list">
                        <template x-for="target in bulkTargets()" :key="target.name">
                            <button type="button" class="ps-kanban-menu-item fi-dropdown-list-item" role="menuitem" x-on:click="menu = null; moveMany(target.name)">
                                <span class="ps-kanban-dot" :style="dot(target.color)"></span>
                                <span class="fi-dropdown-list-item-label" x-html="labelHtml(target)"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            @foreach ($config['bulkActions'] as $action)
                <x-filament::button :color="$action['color'] ?? 'gray'" size="sm" :icon="$action['iconName']" class="ps-kanban-bulk-action" x-on:click="runBulkAction(@js($action['name']))">
                    {{ $action['label'] }}
                </x-filament::button>
            @endforeach

            <x-filament::button color="gray" size="sm" class="ps-kanban-selection-clear" x-on:click="clearSelection()" x-bind:title="t.clear + ' (Esc)'">
                <span x-text="t.clear"></span>
            </x-filament::button>
        </div>
    </div>

    {{-- Narrow screens only (see .ps-kanban-narrow): one column at a time, picked here or by a sideways swipe. --}}
    <x-filament::tabs :label="__('packstub-kanban::kanban.columns')" class="ps-kanban-tabs" role="tablist" x-ref="tabs" x-on:keydown="tabKeys($event)">
        <template x-for="column in columns" :key="column.name">
            <x-filament::tabs.item
                alpine-active="column.name === currentTab()"
                class="ps-kanban-tab"
                role="tab"
                x-bind:data-column="column.name"
                x-show="! hidden.includes(column.name)"
                x-bind:aria-selected="column.name === currentTab()"
                x-bind:tabindex="column.name === currentTab() ? 0 : -1"
                x-on:click="selectTab(column.name)"
            >
                <span class="ps-kanban-dot" :style="dot(column.color)"></span>
                <span x-text="column.label"></span>
                <span class="ps-kanban-tab-count" x-text="column.limit !== null ? column.total + '/' + column.limit : column.count"></span>
            </x-filament::tabs.item>
        </template>
    </x-filament::tabs>

    <div class="ps-kanban-board" x-ref="board" :class="{ 'ps-kanban-board-lanes': lanes }" :style="gridStyle()" x-on:touchstart.passive="swipeStart($event)" x-on:touchend.passive="swipeEnd($event)">
        <template x-for="column in columns" :key="column.name">
            <section
                class="ps-kanban-col"
                role="region"
                x-show="! hidden.includes(column.name)"
                :data-column="column.name"
                :aria-label="columnLabel(column)"
                :class="{
                    'ps-kanban-col-folded': folded[column.name] && ! narrow,
                    'ps-kanban-col-tab': narrow && column.name === currentTab(),
                    'ps-kanban-col-target': dragging && dragging.from !== column.name && canDrop(dragging.from, column.name),
                    'ps-kanban-col-blocked': dragging && dragging.from !== column.name && ! canDrop(dragging.from, column.name),
                    'ps-kanban-col-locked': ! column.draggable,
                    'ps-kanban-col-full': isFull(column),
                }"
                x-on:click="if (folded[column.name] && ! narrow && ! $event.target.closest('.ps-kanban-fold')) toggleFold(column.name)"
            >
                <header class="ps-kanban-col-head" x-on:dblclick="if (! narrow) toggleFold(column.name)" :style="headStyle(column)" :title="column.description || null">
                    <span class="ps-kanban-dot" :style="dot(column.color)"></span>
                    <span class="ps-kanban-col-icon" x-show="column.icon" x-html="column.icon"></span>
                    <div class="ps-kanban-col-title">
                        <h3 x-html="labelHtml(column)"></h3>
                        <span class="ps-kanban-summary" x-show="column.summary" x-text="column.summary"></span>
                    </div>
                    <span
                        class="ps-kanban-count"
                        x-text="column.limit !== null ? column.total + '/' + column.limit : column.count"
                        :title="column.limit !== null ? t.limit.replace(':limit', column.limit) : null"
                    ></span>
                    <button
                        type="button"
                        class="ps-kanban-icon-btn ps-kanban-create"
                        x-show="createAction && column.creatable && ! isFull(column)"
                        x-on:click.stop="create(column)"
                        :title="createAction?.label"
                        :aria-label="createAction?.label"
                    >
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M10 4.5v11M4.5 10h11"/></svg>
                    </button>
                    <div class="ps-kanban-menu-wrap ps-kanban-col-menu-wrap" x-show="column.actions && column.actions.length">
                        <button
                            type="button"
                            class="ps-kanban-icon-btn ps-kanban-col-menu"
                            x-on:click.stop="menu = menu === 'column:' + column.name ? null : 'column:' + column.name"
                            :aria-label="t.column_menu"
                            :aria-expanded="menu === 'column:' + column.name"
                            :title="t.column_menu"
                        >
                            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><circle cx="5" cy="10" r="1.4"/><circle cx="10" cy="10" r="1.4"/><circle cx="15" cy="10" r="1.4"/></svg>
                        </button>
                        <div class="ps-kanban-menu ps-kanban-menu-end fi-dropdown-panel" role="menu" x-show="menu === 'column:' + column.name" x-cloak x-transition.opacity.duration.100ms x-on:click.outside="menu = null" x-on:dblclick.stop>
                            <div class="fi-dropdown-list">
                                <template x-for="action in column.actions" :key="action.name">
                                    <button type="button" class="ps-kanban-menu-item fi-dropdown-list-item" :class="action.colorClasses" role="menuitem" x-on:click.stop="runColumnAction(action.name, column)">
                                        <template x-if="action.icon"><span class="ps-kanban-menu-icon" x-html="action.icon"></span></template>
                                        <span class="fi-dropdown-list-item-label" x-text="action.label"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="ps-kanban-icon-btn ps-kanban-fold" x-on:click="toggleFold(column.name)" :title="folded[column.name] ? t.expand : t.collapse">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m12 5-5 5 5 5"/></svg>
                    </button>
                </header>

                <template x-for="lane in rows" :key="lane.value ?? '_'">
                <div class="ps-kanban-cell" :class="{ 'ps-kanban-cell-folded': lanes && foldedLanes[lane.value] }" :style="cellStyle(column, lane)">
                <ol class="ps-kanban-cards" role="list" :data-column="column.name" :data-lane="lane.value" x-init="bindSortable($el, column, lane)" x-on:keydown="keys($event, column)" x-on:contextmenu="contextMenu($event)">
                    <template x-for="card in cardsIn(column, lane)" :key="card.id">
                        <li
                            class="ps-kanban-card"
                            role="listitem"
                            :data-id="card.id"
                            x-show="matches(card)"
                            :class="{ 'ps-kanban-card-pending': card._pending, 'ps-kanban-card-flash': card._flash, 'ps-kanban-card-accent': card.accent, 'ps-kanban-card-selected': isSelected(card.id), 'ps-kanban-card-locked': card.draggable === false }"
                            :style="{ '--ps-kanban-card-accent': tone(card.accent) }"
                        >
                            <button type="button" class="ps-kanban-card-check" x-show="hasSelection" x-on:click.stop="toggleSelect(card, column, lane, $event)" :aria-pressed="isSelected(card.id)" :aria-label="t.select_card" :title="t.select_card">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 10.5 3.5 3.5L15 7"/></svg>
                            </button>
                            {{-- Without a url the anchor is still focusable (arrows, Shift+F10); with a click action it is a button, Enter and Space run it (see keys()). --}}
                            <a class="ps-kanban-card-link" :href="safeUrl(card.url)" :role="! card.url && clickable(card) ? 'button' : null" :tabindex="card.url ? null : 0" x-on:click="open($event, card, column, lane)" draggable="false">
                                <span class="ps-kanban-lock" x-show="card.draggable === false" role="img" :title="t.locked" :aria-label="t.locked">
                                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="4.75" y="8.75" width="10.5" height="8.5" rx="1.5"/><path d="M7 8.75V6.5a3 3 0 0 1 6 0v2.25"/></svg>
                                </span>
                                <div class="ps-kanban-card-top" x-show="card.eyebrow || card.aside">
                                    <span class="ps-kanban-eyebrow" x-text="card.eyebrow"></span>
                                    <span class="ps-kanban-aside" x-text="card.aside"></span>
                                </div>
                                <div class="ps-kanban-title" x-show="card.title" x-text="card.title"></div>
                                <div class="ps-kanban-description" x-show="card.description" x-text="card.description"></div>
                                <div class="ps-kanban-card-foot" x-show="(card.badges && card.badges.length) || (card.meta && card.meta.length) || (card.avatars && card.avatars.length) || card.due || card.progress">
                                    <template x-for="badge in (card.badges || [])" :key="badge.label">
                                        <span class="ps-kanban-badge" :style="{ '--ps-kanban-badge': tone(badge.color) ?? tone('gray') }">
                                            <template x-if="badge.icon && icons[badge.icon]"><span class="ps-kanban-badge-icon" x-html="icons[badge.icon]"></span></template>
                                            <span x-text="badge.label"></span>
                                        </span>
                                    </template>
                                    <span class="ps-kanban-meta" x-text="(card.meta || []).join(' · ')"></span>
                                    <span class="ps-kanban-due" x-show="card.due" :class="dueState(card.due)" :title="card.due?.date">
                                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3.25" y="4.75" width="13.5" height="11.5" rx="1.5"/><path d="M3.25 8.75h13.5M7 3v3M13 3v3"/></svg>
                                        <span x-text="card.due?.label"></span>
                                    </span>
                                    <span class="ps-kanban-avatars" x-show="card.avatars && card.avatars.length">
                                        <template x-for="(avatar, i) in (card.avatars || [])" :key="i">
                                            <span class="ps-kanban-avatar" :title="avatar.name">
                                                <template x-if="avatar.url"><img :src="avatar.url" :alt="avatar.name || ''" loading="lazy"></template>
                                                <template x-if="! avatar.url"><span x-text="initials(avatar.name)"></span></template>
                                            </span>
                                        </template>
                                    </span>
                                    <span class="ps-kanban-progress" x-show="card.progress" :title="card.progress?.label" role="progressbar" :aria-valuenow="card.progress ? Math.round(card.progress.value * 100) : null" aria-valuemin="0" aria-valuemax="100" :aria-label="card.progress?.label">
                                        <span class="ps-kanban-progress-bar" :class="{ 'ps-kanban-progress-full': card.progress && card.progress.value >= 1 }" :style="'width:' + (card.progress ? card.progress.value * 100 : 0) + '%'"></span>
                                    </span>
                                </div>
                            </a>
                            <button
                                type="button"
                                class="ps-kanban-card-menu"
                                x-show="hasMenu(card, column)"
                                x-on:click.stop="openMenu(card)"
                                :aria-label="t.card_menu"
                                :aria-expanded="menu === 'card:' + card.id"
                                :title="t.card_menu"
                            >
                                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><circle cx="5" cy="10" r="1.4"/><circle cx="10" cy="10" r="1.4"/><circle cx="15" cy="10" r="1.4"/></svg>
                            </button>
                            {{-- The card's menu stays in the card's markup (the keyboard handler and click-outside see it there) and floats over the board, fixed where placeMenu() put it, in the dropdown panel's clothes. --}}
                            <div class="ps-kanban-menu ps-kanban-card-popover fi-dropdown-panel" role="menu" x-show="menu === 'card:' + card.id" x-cloak x-on:click.outside="menu = null">
                                <div class="fi-dropdown-list" x-show="actionsFor(card).length">
                                    <template x-for="action in actionsFor(card)" :key="action.name">
                                        <button type="button" class="ps-kanban-menu-item fi-dropdown-list-item" :class="action.colorClasses" role="menuitem" x-on:click="runAction(action.name, card)">
                                            <template x-if="action.icon"><span class="ps-kanban-menu-icon" x-html="action.icon"></span></template>
                                            <span class="fi-dropdown-list-item-label" x-text="action.label"></span>
                                        </button>
                                    </template>
                                </div>
                                <div class="fi-dropdown-list" x-show="canMove(card, column) && targets(column.name).length">
                                    <div class="fi-dropdown-header ps-kanban-menu-label"><span x-text="t.move_to"></span></div>
                                    <template x-for="target in (canMove(card, column) ? targets(column.name) : [])" :key="target.name">
                                        <button type="button" class="ps-kanban-menu-item fi-dropdown-list-item" role="menuitem" x-on:click="menu = null; moveTo(card.id, column.name, target.name)">
                                            <span class="ps-kanban-dot" :style="dot(target.color)"></span>
                                            <span class="fi-dropdown-list-item-label" x-html="labelHtml(target)"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </li>
                    </template>
                </ol>

                <div class="ps-kanban-col-empty" x-show="! visibleCount(column, lane)">
                    <span x-text="dragging && canDrop(dragging.from, column.name, dragging.lane, lane.value) ? t.drop_here : (search ? t.no_match : t.empty)"></span>
                </div>

                <button type="button" class="ps-kanban-more" :data-column="column.name" :data-lane="lane.value" x-show="loadedIn(column, lane) < countIn(column, lane) && (! folded[column.name] || narrow) && ! search" x-init="observeMore($el, column, lane)" x-on:click="more(column, lane)" :disabled="loading[cellKey(column, lane)]">
                    <span x-text="t.more"></span>
                    <span x-text="'(' + (countIn(column, lane) - loadedIn(column, lane)) + ')'"></span>
                </button>
                </div>
                </template>
            </section>
        </template>

        {{-- Swimlanes: a header row before each lane's cells; the cells are placed on the grid by the columns above. --}}
        <template x-for="(lane, i) in (lanes || [])" :key="lane.value">
            <div class="ps-kanban-lane" :class="{ 'ps-kanban-lane-folded': foldedLanes[lane.value] }" :style="laneStyle(i)" :data-lane="lane.value">
                <div class="ps-kanban-lane-head" x-on:dblclick="toggleLane(lane.value)">
                    <button type="button" class="ps-kanban-icon-btn ps-kanban-lane-fold" x-on:click="toggleLane(lane.value)" :title="foldedLanes[lane.value] ? t.unfold_lane : t.fold_lane" :aria-expanded="! foldedLanes[lane.value]">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m5 8 5 5 5-5"/></svg>
                    </button>
                    <span class="ps-kanban-dot" :style="dot(lane.color)"></span>
                    <h4 x-text="lane.label"></h4>
                    <span class="ps-kanban-count" x-text="laneCount(lane)"></span>
                </div>
            </div>
        </template>
    </div>
</div>
