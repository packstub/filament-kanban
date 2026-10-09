/**
 * packstub/filament-kanban — the board in the browser.
 *
 * The board is state (columns → cards) drawn by Alpine. A drop changes that state
 * at once and asks the server afterwards; if the server refuses, the card goes back
 * and the reason is shown. SortableJS comes with Filament (window.Sortable).
 * Hand-written ES module, no build step.
 */

const TONES = {
    gray: '#71717a', slate: '#64748b', zinc: '#71717a', stone: '#78716c',
    red: '#ef4444', orange: '#f97316', amber: '#f59e0b', yellow: '#eab308',
    lime: '#84cc16', green: '#22c55e', emerald: '#10b981', teal: '#14b8a6',
    cyan: '#06b6d4', sky: '#0ea5e9', blue: '#3b82f6', indigo: '#6366f1',
    violet: '#8b5cf6', purple: '#a855f7', fuchsia: '#d946ef', pink: '#ec4899', rose: '#f43f5e',
    primary: 'var(--primary-500)', success: 'var(--success-500)', warning: 'var(--warning-500)',
    danger: 'var(--danger-500)', info: 'var(--info-500)',
}

// Search text per card, outside Alpine's reactivity (filled while rendering).
const TEXT = new WeakMap()

// One of a translation's `a|b|c` forms for a count, as Laravel's trans_choice() picks it:
// `{n}` and `[a,b]` (`*` open) prefixes first, then the locale's plural rules.
function choose(text, count, locale) {
    const range = /^\s*(?:\{(\d+)\}|\[(\d+|\*),\s*(\d+|\*)\])\s*/
    const forms = text.split('|')

    for (const form of forms) {
        const m = form.match(range)
        if (m && (m[1] !== undefined ? count === +m[1] : (m[2] === '*' || count >= +m[2]) && (m[3] === '*' || count <= +m[3]))) {
            return form.replace(range, '')
        }
    }

    const plain = forms.filter((form) => ! range.test(form))
    let index = count === 1 ? 0 : 1
    try {
        const rules = new Intl.PluralRules(locale || document.documentElement.lang || undefined)
        const order = ['zero', 'one', 'two', 'few', 'many', 'other'].filter((c) => rules.resolvedOptions().pluralCategories.includes(c))
        index = order.indexOf(rules.select(count))
    } catch (e) {}

    return (plain[Math.min(Math.max(index, 0), plain.length - 1)] ?? text).trim()
}

// This tab's token: sent with every change, echoed in the broadcast, so the tab ignores its own.
const ORIGIN = Math.random().toString(36).slice(2, 12)

// Without swimlanes every column is one cell: the board draws this single, nameless lane.
const NO_LANE = { value: null }

// The viewer's calendar day as an ISO date (YYYY-MM-DD), compared with a card's due date.
function localDay(now = new Date()) {
    return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0')
}

export default function packstubKanban(config) {
    return {
        columns: config.columns || [],
        lanes: config.lanes || null,
        icons: config.icons || {}, // badge icons by name, grown from every answer that carries cards
        today: localDay(), // the viewer's calendar day, for due dates; moves on at midnight
        filters: config.filters,
        cardActions: config.cardActions || [],
        bulkActions: config.bulkActions || [],
        createAction: config.createAction,
        t: config.i18n,
        search: '',
        active: Object.fromEntries(config.filters.map((f) => [f.name, ''])),
        hidden: [],
        folded: {},
        foldedLanes: {},
        loading: {},
        dragging: null,
        selected: [],
        lastSelected: null,
        menu: null,
        sidebar: false,
        density: config.density || 'comfortable',
        clickAction: !! config.cardAction && (config.cardActions || []).some((a) => a.name === config.cardAction),
        densityChosen: false,
        narrow: false, // below 64rem: one column at a time, picked from a tab bar
        tab: null,
        refreshTimer: null,
        refreshSeq: 0,
        pending: 0,
        stale: false,
        drawn: config.columns !== null, // false until a board drawn again has its columns
        retries: 0,

        /** Whether cards can be selected: the board has bulk actions, or asked for it with selectable(). */
        get hasSelection() {
            return !! config.selectable
        },

        /** The rows of every column: the lanes, or one nameless lane without swimlanes. */
        get rows() {
            return this.lanes || [NO_LANE]
        },

        init() {
            this.restore()

            // Drawn again on a later request (the server sends no state then): ask for it
            // instead of drawing an empty board.
            if (! this.drawn) {
                this.refresh()
            }

            if (config.focus) {
                document.body.classList.toggle('pk-focus-sidebar', this.sidebar)
            }

            this.onSlash = (event) => {
                const tag = (event.target.tagName || '').toLowerCase()
                if (event.key === '/' && ! ['input', 'textarea', 'select'].includes(tag) && ! event.target.isContentEditable && this.$refs.search) {
                    event.preventDefault()
                    this.$refs.search.focus()
                }
            }
            window.addEventListener('keydown', this.onSlash)

            // The board fills the window below its own top edge, so columns scroll
            // inside it and the horizontal scrollbar is always in view.
            this.fit = () => {
                const board = this.$refs.board
                if (! board) return
                const top = board.getBoundingClientRect().top + window.scrollY
                this.$root.style.setProperty('--pk-height', Math.max(360, window.innerHeight - top - 16) + 'px')
            }
            window.addEventListener('resize', this.fit)
            this.$nextTick(this.fit)

            // The same breakpoint as the stylesheet's; the class on the root keeps both in step.
            this.media = window.matchMedia ? window.matchMedia('(max-width: 63.999rem)') : null
            this.narrow = !! this.media?.matches
            this.onMedia = (event) => {
                this.narrow = event.matches
                this.$nextTick(this.fit)
            }
            // Safari before 14 has addListener() only.
            this.media?.addEventListener ? this.media.addEventListener('change', this.onMedia) : this.media?.addListener?.(this.onMedia)

            // A board left open overnight recolours its due dates.
            this.clock = setInterval(() => {
                const day = localDay()
                if (day !== this.today) this.today = day
            }, 60000)

            // Pick up other people's changes, but never while a card is in the air.
            if (config.poll) {
                this.poller = setInterval(() => {
                    if (! document.hidden && ! this.dragging && ! this.pending && ! this.menu) {
                        this.refresh(true)
                    }
                }, config.poll)
            }

            // With Laravel Echo on the page, another tab's change reloads the board at once;
            // a burst of events is one request. Echo may arrive after the board (Filament
            // dispatches EchoLoaded when it does).
            if (config.broadcast) {
                this.$watch('menu', (menu) => menu || this.settle())
                this.onEcho = () => this.listen()
                window.Echo ? this.listen() : window.addEventListener('EchoLoaded', this.onEcho, { once: true })
            }
        },

        destroy() {
            window.removeEventListener('keydown', this.onSlash)
            window.removeEventListener('resize', this.fit)
            this.media?.removeEventListener ? this.media.removeEventListener('change', this.onMedia) : this.media?.removeListener?.(this.onMedia)
            window.removeEventListener('EchoLoaded', this.onEcho)
            clearInterval(this.poller)
            clearTimeout(this.echoTimer)
            // Only this board's listener: the channel may be shared with other boards and the app.
            if (this.channel) this.channel.stopListening(config.broadcast.event, this.onChange)
            document.removeEventListener('visibilitychange', this.onVisible)
            clearInterval(this.clock)
            this.moreObserver?.disconnect()
            document.body.classList.remove('pk-focus-sidebar')
            delete document.body._x_ignoreMutationObserver
        },

        listen() {
            if (this.channel || ! window.Echo) return

            this.onChange = (payload) => {
                if (payload?.origin && payload.origin === ORIGIN) return
                // Another board on the same channel (two keys mapped to one channel name).
                if (payload?.board != null && config.broadcast.board != null && payload.board !== config.broadcast.board) return
                clearTimeout(this.echoTimer)
                this.echoTimer = setTimeout(() => {
                    this.stale = true
                    this.settle()
                }, 300)
            }
            this.onVisible = () => this.settle()
            document.addEventListener('visibilitychange', this.onVisible)

            this.channel = window.Echo.private(config.broadcast.channel)
            this.channel.listen(config.broadcast.event, this.onChange)
        },

        // A change that arrived while a card was in the air, a menu was open or the tab was in
        // the background is loaded once the drop has settled, the menu closed, the tab shown.
        settle() {
            if (this.stale && ! this.dragging && ! this.pending && ! this.menu && ! document.hidden) {
                this.stale = false
                this.refresh(true)
            }
        },

        /* ------------------------------------------------------------ drag and drop */

        bindSortable(el, column, lane = NO_LANE) {
            if (! window.Sortable || el.sortable) {
                return
            }

            el.sortable = window.Sortable.create(el, {
                group: {
                    name: config.key,
                    pull: () => column.draggable,
                    put: (to, from) => this.canDrop(from.el.dataset.column, to.el.dataset.column, from.el.dataset.lane, to.el.dataset.lane),
                },
                sort: config.reorderable,
                draggable: '.pk-card',
                // A locked card is filtered rather than left out of `draggable`, so Sortable's
                // indexes still count every card and match the column's state.
                filter: '.pk-card-locked, .pk-card-menu, .pk-card-popover, .pk-card-pending',
                preventOnFilter: false,
                disabled: ! column.draggable && ! column.droppable,
                animation: 150,
                easing: 'cubic-bezier(0.2, 0, 0, 1)',
                ghostClass: 'pk-ghost',
                chosenClass: 'pk-chosen',
                dragClass: 'pk-drag',
                delay: 150,
                delayOnTouchOnly: true,
                forceFallback: true, // a real element follows the pointer (and works the same on touch)
                fallbackOnBody: true,
                fallbackTolerance: 4,
                scroll: true,
                bubbleScroll: true,
                scrollSensitivity: 90,
                scrollSpeed: 18,
                onStart: (event) => {
                    // Sortable's ghost is a copy of the card appended to <body>; while a card is
                    // in the air, Alpine must not initialize that copy outside the board's scope.
                    document.body._x_ignoreMutationObserver = true
                    this.menu = null
                    this.dragging = { from: event.from.dataset.column, id: event.item.dataset.id, lane: event.from.dataset.lane }
                },
                onEnd: (event) => {
                    delete document.body._x_ignoreMutationObserver
                    this.dropped(event)
                },
            })
        },

        dropped(event) {
            const id = event.item.dataset.id
            const from = event.from.dataset.column
            const to = event.to.dataset.column
            const lane = event.to.dataset.lane // undefined without swimlanes
            this.dragging = null

            if (from === to && event.from.dataset.lane === lane && event.oldDraggableIndex === event.newDraggableIndex) {
                return this.settle()
            }

            // Put the node back where Sortable took it from and let Alpine move it from
            // the state; otherwise Alpine's keyed list and the DOM disagree.
            const cards = (list) => list.querySelectorAll(':scope > .pk-card')
            event.item.remove()
            const ref = cards(event.from)[event.oldDraggableIndex] ?? null
            ref ? event.from.insertBefore(event.item, ref) : event.from.appendChild(event.item)

            this.move(id, from, to, event.newDraggableIndex, lane, { pointer: true })
        },

        moveTo(id, from, to) {
            const lane = this.findColumn(from)?.cards.find((c) => c.id === id)?.lane
            this.move(id, from, to, 0, lane)
            const cell = lane === undefined ? '' : `[data-lane="${CSS.escape(lane)}"]`
            this.$nextTick(() => this.$refs.board.querySelector(`.pk-cards[data-column="${CSS.escape(to)}"]${cell}`)?.scrollTo({ top: 0, behavior: 'smooth' }))
        },

        // Undo is a move back, through every rule and moveUsing() again; a refusal shows as usual.
        undo(detail) {
            if (! detail || detail.key !== config.key) return
            this.move(detail.id, detail.to, detail.from, config.reorderable ? detail.index : 0, detail.lane ?? undefined, { undo: true })
        },

        // Only when the way back is open (a one-way accepts(), a column that is not draggable): the server decides anyway.
        // With swimlanes, `lane` is the lane the card came from (it goes back there).
        offerUndo(id, from, to, index, lane = undefined, toLane = undefined) {
            if (! config.undo || ! window.FilamentNotification || ! window.FilamentNotificationAction || ! this.canDrop(to, from, toLane, lane)) return false

            new window.FilamentNotification()
                .title(this.tr('moved_to', { column: this.findColumn(to)?.label ?? to }))
                .success()
                .duration(config.undo * 1000)
                .actions([
                    new window.FilamentNotificationAction('undo')
                        .label(this.t.undo)
                        .button()
                        .close()
                        .dispatch('packstub-kanban-undo', { key: config.key, id, from, to, index, lane: lane ?? null }),
                ])
                .send()

            return true
        },

        // `index` is the position in the target cell (the column, or the column's lane with swimlanes).
        move(id, from, to, index, lane = undefined, options = {}) {
            const source = this.findColumn(from)
            const target = this.findColumn(to)
            const at = source.cards.findIndex((c) => c.id === id)

            if (at < 0) {
                return
            }

            // Where the card sat in its own cell, for an Undo that puts it back there.
            const cellAt = this.lanes ? this.cardsIn(source, { value: source.cards[at].lane }).indexOf(source.cards[at]) : at
            const [card] = source.cards.splice(at, 1)
            const fromLane = card.lane
            const laned = this.lanes && lane !== undefined
            if (laned) card.lane = lane
            target.cards.splice(this.cellIndex(target, lane, index), 0, card)

            const shift = (by) => {
                if (laned) {
                    source.counts[fromLane] = (source.counts[fromLane] || 0) - by
                    target.counts[lane] = (target.counts[lane] || 0) + by
                }
                if (from === to) return
                source.count -= by
                target.count += by
                if (source.total !== null) source.total -= by
                if (target.total !== null) target.total += by
            }

            shift(1)
            card._pending = true
            this.pending++
            const order = config.reorderable ? (laned ? this.cardsIn(target, { value: lane }) : target.cards).map((c) => c.id) : null

            // A keyboard user keeps their place: the card is drawn again (in another
            // column, or back where it was), so focus follows it by id. Only when focus
            // was in the card when the move started and has not gone elsewhere since (the
            // menu closing leaves it on <body>): a late refusal never pulls focus from a
            // search box or a modal.
            // A mouse drop leaves focus alone (the link took it on mousedown, not the user).
            const inCard = () => document.activeElement?.closest?.('.pk-card')?.dataset.id === id
            const onTarget = () => {
                const el = document.activeElement
                return !! el?.matches?.('.pk-tab, .pk-fold') && (el.dataset.column ?? el.closest('.pk-col')?.dataset.column) === to
            }
            const focused = ! options.pointer && inCard()
            const keepFocus = () => focused && (inCard() || onTarget() || document.activeElement === document.body || ! document.activeElement)
            if (focused) this.refocus(id, to)

            const undo = (message) => {
                const now = target.cards.findIndex((c) => c.id === id)
                if (now >= 0) target.cards.splice(now, 1)
                shift(-1)
                if (laned) card.lane = fromLane
                source.cards.splice(Math.min(at, source.cards.length), 0, card)
                card._pending = false
                this.notify(message, 'danger')
                // Filament's notifications are a live region already; the board's own only steps in without them.
                if (! window.FilamentNotification) this.announce(message)
                if (keepFocus()) this.refocus(id, from)
            }

            this.$wire.kanbanMove(id, to, order, this.search, this.active, laned ? lane : null, ORIGIN)
                .then((result) => {
                    if (! result?.ok) {
                        return undo(result?.message || this.tr('failed'))
                    }

                    Object.assign(this.icons, result.icons || {})

                    for (const [name, summary] of Object.entries(result.summaries || {})) {
                        const column = this.findColumn(name)
                        if (column) column.summary = summary
                    }

                    if (from !== to || (laned && lane !== fromLane)) {
                        this.$dispatch('kanban-card-moved', { id, from, to, ...(laned ? { lane } : {}), card: result.card })
                        // The Undo notification is a live region of its own; without it the board announces the move.
                        const offered = ! options.undo && this.offerUndo(id, from, to, cellAt, laned ? fromLane : undefined, laned ? lane : undefined)
                        if (from !== to && ! offered) this.announce(this.tr('moved_to', { column: target.label }))
                    }

                    const now = target.cards.findIndex((c) => c.id === id)
                    if (now >= 0) {
                        target.cards[now] = { ...result.card, _flash: true }
                        setTimeout(() => {
                            const c = target.cards.find((c) => c.id === id)
                            if (c) c._flash = false
                        }, 900)
                    }
                })
                .catch(() => undo(this.tr('offline')))
                .finally(() => {
                    this.pending--
                    this.settle()
                })
        },

        canDrop(from, to, fromLane = undefined, toLane = undefined) {
            // A lane may take no cards from other lanes (the derived "Other" lane), as on the server.
            if (this.lanes && toLane !== undefined && fromLane !== toLane && this.lanes.find((l) => l.value === toLane)?.droppable === false) {
                return false
            }

            if (from === to) {
                // A lane change inside the column is a move: the column must let cards out and in, as on the server.
                if (this.lanes && fromLane !== toLane) {
                    const column = this.findColumn(from)
                    return !! (column && column.draggable && column.droppable)
                }

                return config.reorderable
            }

            const source = this.findColumn(from)
            const target = this.findColumn(to)

            return !! (source && target && source.draggable && target.droppable && ! this.isFull(target)
                && (target.accepts === null || target.accepts.includes(from)))
        },

        isFull(column) {
            return column.limit !== null && column.limit !== undefined && column.total >= column.limit
        },

        /* ------------------------------------------------------------ Filament actions */

        actionsFor(card) {
            return this.cardActions.filter((a) => ! card.actions || card.actions.includes(a.name))
        },

        hasMenu(card, column) {
            return this.actionsFor(card).length > 0 || (this.canMove(card, column) && this.targets(column.name).length > 0)
        },

        // The column lets cards out and the card itself is not locked (the server checks both again).
        canMove(card, column) {
            return !! column.draggable && card.draggable !== false
        },

        runAction(name, card) {
            this.menu = null
            this.$wire.mountAction(name, { kanbanRecord: card.id, kanbanOrigin: ORIGIN })
        },

        // Whether a click on the card runs the click action (a card with a url is a link anyway).
        clickable(card) {
            return this.clickAction && (! card.actions || card.actions.includes(config.cardAction))
        },

        open(event, card, column = null, lane = NO_LANE) {
            // Ctrl/⌘-click toggles the card in the selection, Shift-click selects up to it; a selected card is not opened.
            if (this.hasSelection && (event.metaKey || event.ctrlKey || event.shiftKey || this.isSelected(card.id))) {
                event.preventDefault()
                return this.toggleSelect(card, column, lane, event)
            }

            if (this.clickable(card)) {
                // A middle click (or, on a board without selection, a modified click) still opens the url in a new tab.
                if (card.url && (event.button === 1 || event.metaKey || event.ctrlKey || event.shiftKey)) return
                event.preventDefault()
                return this.runAction(config.cardAction, card)
            }

            if (! card.url) event.preventDefault()
        },

        // The server resolves the column again and takes the search and filters as it does on a refresh.
        runColumnAction(name, column) {
            this.menu = null
            this.$wire.mountAction(name, { kanbanColumn: column.name, kanbanSearch: this.search, kanbanFilters: this.active, kanbanOrigin: ORIGIN })
        },

        create(column) {
            this.$wire.mountAction(this.createAction.name, { kanbanColumn: column.name, kanbanOrigin: ORIGIN })
        },

        targets(from) {
            return this.columns.filter((c) => c.name !== from && ! this.hidden.includes(c.name) && this.canDrop(from, c.name))
        },

        /* ------------------------------------------------------------ keyboard */

        // One handler per column list: arrows walk the visible cards, Home/End jump,
        // Enter opens, Shift+F10 or the menu key opens the card's menu; inside an open
        // menu the arrows walk its items and Escape returns to the card.
        keys(event, column) {
            const item = event.target.closest('.pk-card')
            if (! item || event.altKey || event.ctrlKey || event.metaKey) return

            const link = item.querySelector(':scope > .pk-card-link')
            const menu = event.target.closest('.pk-menu')

            if (menu) {
                if (event.key === 'Escape') {
                    this.menu = null
                    return link?.focus()
                }
                const items = [...menu.querySelectorAll('.pk-menu-item')]
                const at = items.indexOf(event.target)
                const next = { ArrowDown: items[(at + 1) % items.length], ArrowUp: items[(at - 1 + items.length) % items.length], Home: items[0], End: items[items.length - 1] }[event.key]
                if (next) {
                    event.preventDefault()
                    next.focus()
                }
                return
            }

            if (event.target !== link) return

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Home' || event.key === 'End') {
                const links = [...event.currentTarget.querySelectorAll(':scope > .pk-card > .pk-card-link')].filter((l) => l.offsetParent)
                const at = links.indexOf(link)
                const to = event.key === 'ArrowDown' ? at + 1 : event.key === 'ArrowUp' ? at - 1 : event.key === 'Home' ? 0 : links.length - 1
                if (links[to]) {
                    event.preventDefault()
                    links[to].focus()
                }
            } else if ((event.key === 'Enter' || event.key === ' ') && ! link.hasAttribute('href')) {
                // An anchor without href is not activated by the keyboard on its own.
                event.preventDefault()
                link.click()
            } else if ((event.key === 'F10' && event.shiftKey) || event.key === 'ContextMenu') {
                const card = column.cards.find((c) => c.id === item.dataset.id)
                if (card && this.hasMenu(card, column)) {
                    event.preventDefault()
                    // The browser's own menu follows as a separate contextmenu event (on keyup in Chrome on Windows).
                    this.menuKey = Date.now()
                    this.openMenu(card)
                }
            }
        },

        // The contextmenu event a menu key (or Shift+F10) fires after the board opened its own menu.
        contextMenu(event) {
            if (this.menuKey && Date.now() - this.menuKey < 1000) {
                event.preventDefault()
                this.menuKey = 0
            }
        },

        openMenu(card) {
            const key = 'card:' + card.id
            this.menu = this.menu === key ? null : key
            if (this.menu) {
                this.$nextTick(() => this.$root.querySelector(`.pk-card[data-id="${CSS.escape(card.id)}"] .pk-menu-item`)?.focus())
            }
        },

        // Focus the card again once Alpine has drawn it; when its column is not on screen
        // (another tab on a narrow screen, a folded column) the column's tab or fold
        // button takes the focus instead, so it never drops to <body>.
        refocus(id, column) {
            this.$nextTick(() => {
                const link = this.$root.querySelector(`.pk-card[data-id="${CSS.escape(id)}"] > .pk-card-link`)
                if (link?.offsetParent) return link.focus()

                const name = CSS.escape(column ?? '')
                const fallback = this.narrow
                    ? this.$refs.tabs?.querySelector(`.pk-tab[data-column="${name}"]`)
                    : this.$refs.board?.querySelector(`.pk-col[data-column="${name}"] .pk-fold`)
                fallback?.focus()
            })
        },

        // The live region reads a text once; cleared first so the same text is read again.
        announce(text) {
            const live = this.$refs.live
            if (! live || ! text) return
            live.textContent = ''
            setTimeout(() => { live.textContent = text }, 50)
        },

        // A string with its placeholders filled, as Laravel does: every occurrence, longer
        // names first, never a value's own text (one pass). With a count, `a|b` forms are
        // chosen as trans_choice() would. A missing key (a published translation of a locale
        // the package does not ship) gives the key, never an error.
        tr(key, replacements = {}) {
            let text = typeof this.t?.[key] === 'string' ? this.t[key] : key
            if (text.includes('|') && typeof replacements.count === 'number') {
                text = choose(text, replacements.count, config.locale)
            }

            const names = Object.keys(replacements).sort((a, b) => b.length - a.length)
            if (! names.length) return text

            return text.replace(new RegExp(':(' + names.join('|') + ')', 'g'), (_, name) => String(replacements[name]))
        },

        columnLabel(column) {
            return this.tr('column_label', { label: column.label, count: column.count })
        },

        /* ------------------------------------------------------------ narrow screens: one column at a time */

        // The selected tab, falling back to the first visible column when it is hidden or gone.
        currentTab() {
            const visible = this.columns.filter((c) => ! this.hidden.includes(c.name))

            return visible.some((c) => c.name === this.tab) ? this.tab : (visible[0]?.name ?? null)
        },

        selectTab(name) {
            this.tab = name
            this.menu = null
            this.persist()
            this.$nextTick(() => this.$refs.tabs?.querySelector('.pk-tab-on')?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' }))
        },

        // Roving tabindex on the tab bar: the arrows (wrapping), Home and End select and focus the next tab.
        tabKeys(event) {
            const tabs = [...event.currentTarget.querySelectorAll('.pk-tab')].filter((t) => t.offsetParent)
            const at = tabs.indexOf(event.target)
            if (at < 0) return

            const to = { ArrowRight: (at + 1) % tabs.length, ArrowLeft: (at - 1 + tabs.length) % tabs.length, Home: 0, End: tabs.length - 1 }[event.key]
            if (to === undefined || ! tabs[to]) return

            event.preventDefault()
            this.selectTab(tabs[to].dataset.column)
            tabs[to].focus()
        },

        // A one-finger sideways swipe over the board goes to the next or previous column;
        // never while a card is in the air, never a pinch.
        swipeStart(event) {
            const touch = event.touches?.[0]
            this.swipe = touch && event.touches.length === 1 ? { x: touch.clientX, y: touch.clientY } : null
        },

        swipeEnd(event) {
            const start = this.swipe
            const touch = event.changedTouches?.[0]
            this.swipe = null
            if (! start || ! touch || event.changedTouches.length > 1 || event.touches?.length || ! this.narrow || this.dragging) return

            const dx = touch.clientX - start.x
            if (Math.abs(dx) < 60 || Math.abs(dx) < Math.abs(touch.clientY - start.y)) return

            const visible = this.columns.filter((c) => ! this.hidden.includes(c.name))
            const next = visible[visible.findIndex((c) => c.name === this.currentTab()) + (dx < 0 ? 1 : -1)]
            if (next) this.selectTab(next.name)
        },

        /* ------------------------------------------------------------ selection */

        isSelected(id) {
            return this.selected.includes(id)
        },

        // Shift selects the range from the last selected card, inside the same cell; otherwise the card is toggled.
        toggleSelect(card, column, lane = NO_LANE, event = null) {
            const cell = column ? this.cardsIn(column, lane).filter((c) => this.matches(c)).map((c) => c.id) : []
            const from = cell.indexOf(this.lastSelected)
            const to = cell.indexOf(card.id)

            let next
            if (event?.shiftKey && from >= 0 && to >= 0) {
                const range = cell.slice(Math.min(from, to), Math.max(from, to) + 1)
                next = [...new Set([...this.selected, ...range])]
            } else if (this.isSelected(card.id)) {
                next = this.selected.filter((id) => id !== card.id)
            } else {
                next = [...this.selected, card.id]
            }

            // The server refuses a selection past the cap as a whole, so the browser stops there.
            if (config.maxSelection && next.length > config.maxSelection) {
                return this.notify(this.t.bulk_limit.replace(':max', config.maxSelection), 'danger')
            }

            this.selected = next

            this.lastSelected = card.id
            this.menu = null
        },

        clearSelection() {
            this.selected = []
            this.lastSelected = null
        },

        // A card that left the board (deleted by an action, filtered out, dropped by a poll) leaves the selection too.
        pruneSelection() {
            if (! this.selected.length) return
            const loaded = new Set(this.columns.flatMap((c) => c.cards.map((x) => x.id)))
            this.selected = this.selected.filter((id) => loaded.has(id))
            if (this.lastSelected && ! loaded.has(this.lastSelected)) this.lastSelected = null
        },

        /** Where every selected card may go: the columns each one's source column allows (locked cards stay). */
        bulkTargets() {
            const sources = [...new Set(this.movableSelection().map(([column]) => column.name))]

            return sources.length
                ? this.columns.filter((c) => ! this.hidden.includes(c.name) && sources.some((from) => from !== c.name && this.canDrop(from, c.name)))
                : []
        },

        /** The selected cards with their columns, in board order; a selection outlives a refresh only where the cards still are. */
        selectedCards() {
            const pairs = []
            for (const column of this.columns) {
                for (const card of column.cards) {
                    if (this.isSelected(card.id)) pairs.push([column, card])
                }
            }
            return pairs
        },

        /** The selected cards a bulk move may take: not pending, and not locked (`draggable: false` on the card). */
        movableSelection() {
            return this.selectedCards().filter(([, card]) => ! card._pending && card.draggable !== false)
        },

        runBulkAction(name) {
            this.menu = null
            this.$wire.mountAction(name, { kanbanRecords: this.selectedCards().map(([, card]) => card.id), kanbanOrigin: ORIGIN })
        },

        // Every selected card moves at once; the ones the server refuses come back, with one notification.
        moveMany(to) {
            const target = this.findColumn(to)
            const moves = []

            // Cards already there, or from a column that may not drop into it, stay put.
            for (const [source, card] of this.movableSelection()) {
                if (source.name === to || ! this.canDrop(source.name, to)) continue
                const at = source.cards.indexOf(card)
                source.cards.splice(at, 1)
                target.cards.push(card)
                source.count--
                target.count++
                if (source.total !== null) source.total--
                if (target.total !== null) target.total++
                if (this.lanes) {
                    source.counts[card.lane] = (source.counts[card.lane] || 0) - 1
                    target.counts[card.lane] = (target.counts[card.lane] || 0) + 1
                }
                card._pending = true
                moves.push({ source, card, at })
            }

            this.clearSelection()

            if (! moves.length) return

            const undo = ({ source, card, at }) => {
                const now = target.cards.indexOf(card)
                if (now >= 0) target.cards.splice(now, 1)
                source.cards.splice(Math.min(at, source.cards.length), 0, card)
                source.count++
                target.count--
                if (source.total !== null) source.total++
                if (target.total !== null) target.total--
                if (this.lanes) {
                    source.counts[card.lane] = (source.counts[card.lane] || 0) + 1
                    target.counts[card.lane] = (target.counts[card.lane] || 0) - 1
                }
                card._pending = false
            }

            this.pending++

            this.$wire.kanbanMoveMany(moves.map((m) => m.card.id), to, this.search, this.active, null, ORIGIN)
                .then((result) => {
                    if (! result || (! result.ok && result.message)) {
                        moves.forEach(undo)
                        return this.notify(result?.message || this.t.failed, 'danger')
                    }

                    Object.assign(this.icons, result.icons || {})

                    for (const [name, summary] of Object.entries(result.summaries || {})) {
                        const column = this.findColumn(name)
                        if (column) column.summary = summary
                    }

                    const refused = new Map((result.refused || []).map((r) => [r.id, r.message]))
                    moves.filter((m) => refused.has(m.card.id)).forEach(undo)

                    for (const fresh of result.moved || []) {
                        const move = moves.find((m) => m.card.id === fresh.id)
                        const now = target.cards.findIndex((c) => c.id === fresh.id)
                        if (move) this.$dispatch('kanban-card-moved', { id: fresh.id, from: move.source.name, to, card: fresh })
                        if (now >= 0) target.cards[now] = { ...fresh, _flash: true }
                    }

                    setTimeout(() => {
                        for (const c of target.cards) if (c._flash) c._flash = false
                    }, 900)

                    if (refused.size) {
                        const reasons = [...new Set(refused.values())].slice(0, 3).join(' ')
                        const title = refused.size === 1 ? this.t.bulk_refused_one : this.t.bulk_refused.replace(':count', refused.size)
                        this.notify(title + ' ' + reasons, 'danger')
                    }
                })
                .catch(() => {
                    moves.forEach(undo)
                    this.notify(this.t.offline, 'danger')
                })
                .finally(() => {
                    this.pending--
                    this.settle()
                })
        },

        /* ------------------------------------------------------------ search, filters, paging */

        matches(card) {
            const needle = this.search.trim().toLowerCase()

            if (! needle) {
                return true
            }

            const raw = window.Alpine?.raw ? window.Alpine.raw(card) : card
            let text = TEXT.get(raw)

            if (text === undefined) {
                text = [card.eyebrow, card.title, card.aside, card.description, card.due?.label, ...(card.meta || []), ...(card.badges || []).map((b) => b.label), ...(card.avatars || []).map((a) => a.name), card.search]
                    .filter(Boolean).join(' ').toLowerCase()
                TEXT.set(raw, text)
            }

            return needle.split(/\s+/).every((word) => text.includes(word))
        },

        visibleCount(column, lane = NO_LANE) {
            return this.cardsIn(column, lane).filter((card) => this.matches(card)).length
        },

        queueRefresh() {
            clearTimeout(this.refreshTimer)
            this.refreshTimer = setTimeout(() => this.refresh(), 250)
        },

        // A search or filter starts every column over; a background refresh (a poll, an
        // action) keeps the pages already loaded and gives way to a card in the air.
        refresh(background = false) {
            clearTimeout(this.refreshTimer)
            const seq = ++this.refreshSeq
            const loaded = background ? Object.fromEntries(this.columns.map((c) => [c.name, this.lanes
                ? Object.fromEntries(this.lanes.map((l) => [l.value, this.loadedIn(c, l)]))
                : c.cards.length])) : {}

            this.$wire.kanbanRefresh(this.search, this.active, loaded, ! this.drawn).catch(() => null).then((result) => {
                if (seq !== this.refreshSeq) {
                    return
                }

                // Without its columns the board would stay empty: try again, a little later each time.
                if (! result && ! this.drawn && this.retries < 5) {
                    this.refreshTimer = setTimeout(() => this.refresh(), 1000 * 2 ** this.retries++)
                }

                if (! result) {
                    return
                }

                // A drag started meanwhile: keep the answer for after the drop (see settle()).
                if (background && (this.dragging || this.pending)) {
                    this.stale = true
                    return
                }

                Object.assign(this.icons, result.icons || {})

                if (this.lanes && result.lanes) {
                    this.lanes = result.lanes
                    this.restoreLanes()
                }

                if (! this.drawn) {
                    this.columns = result.columns
                    this.drawn = true
                    this.restore(false) // collapsed() columns and the saved folded/hidden ones, now that there are columns
                    return
                }

                for (const fresh of result.columns) {
                    const column = this.findColumn(fresh.name)
                    if (column) {
                        Object.assign(column, {
                            label: fresh.label,
                            labelHtml: fresh.labelHtml,
                            icon: fresh.icon,
                            description: fresh.description,
                        })
                        column.cards = fresh.cards
                        column.count = fresh.count
                        column.counts = fresh.counts
                        column.total = fresh.total
                        column.summary = fresh.summary
                    }
                }

                if (! background && this.search.trim()) {
                    const shown = result.columns.filter((c) => ! this.hidden.includes(c.name))
                    this.announce(this.tr('matches_count', { count: shown.reduce((sum, c) => sum + (c.count || 0), 0) }))
                }

                this.pruneSelection()
            })
        },

        // "Load more" loads itself when it scrolls into view; the button stays for keyboards.
        observeMore(el, column, lane = NO_LANE) {
            if (! window.IntersectionObserver) return
            this.moreObserver ??= new IntersectionObserver((entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting && entry.target.offsetParent) entry.target._more?.()
                }
            }, { rootMargin: '0px 0px 200px 0px' })
            el._more = () => this.more(column, lane)
            this.moreObserver.observe(el)
        },

        more(column, lane = NO_LANE) {
            const key = this.cellKey(column, lane)

            if (this.loading[key] || this.loadedIn(column, lane) >= this.countIn(column, lane)) {
                return
            }

            this.loading[key] = true

            this.$wire.kanbanMore(column.name, this.loadedIn(column, lane), this.search, this.active, this.lanes ? lane.value : null)
                .then((result) => {
                    Object.assign(this.icons, result?.icons || {})
                    const known = new Set(column.cards.map((c) => c.id))
                    const fresh = (result?.cards || []).filter((c) => ! known.has(c.id))
                    column.cards.push(...fresh)
                    this.announce(this.tr('loaded_more', { count: fresh.length }))
                })
                .finally(() => {
                    this.loading[key] = false
                    // Still in view (a short page)? Observing again reports it, and the next page loads.
                    this.$nextTick(() => {
                        const cell = this.lanes ? `[data-lane="${CSS.escape(lane.value)}"]` : ''
                        const el = this.$root.querySelector(`.pk-more[data-column="${CSS.escape(column.name)}"]${cell}`)
                        if (el && this.moreObserver) {
                            this.moreObserver.unobserve(el)
                            this.moreObserver.observe(el)
                        }
                    })
                })
        },

        /* ------------------------------------------------------------ view preferences */

        toggleFold(name) {
            this.folded[name] = ! this.folded[name]
            this.persist()
        },

        toggleLane(value) {
            this.foldedLanes[value] = ! this.foldedLanes[value]
            this.persist()
        },

        toggleHidden(name) {
            this.hidden = this.hidden.includes(name) ? this.hidden.filter((n) => n !== name) : [...this.hidden, name]
            this.persist()
        },

        toggleSidebar() {
            this.sidebar = ! this.sidebar
            document.body.classList.toggle('pk-focus-sidebar', this.sidebar)
            this.persist()
        },

        // The user's choice is saved and wins over the board's default; until they choose,
        // nothing is saved, so a later density() default still applies.
        toggleDensity() {
            this.density = this.density === 'compact' ? 'comfortable' : 'compact'
            this.densityChosen = true
            this.persist()
        },

        restore(sidebar = true) {
            for (const column of this.columns) {
                this.folded[column.name] = !! column.collapsed
            }

            try {
                const saved = JSON.parse(localStorage.getItem(config.key) || 'null')
                if (saved) {
                    const names = this.columns.map((c) => c.name)
                    Object.assign(this.folded, Object.fromEntries(Object.entries(saved.folded || {}).filter(([n]) => names.includes(n))))
                    this.hidden = (saved.hidden || []).filter((n) => names.includes(n))
                    if (sidebar) this.sidebar = !! saved.sidebar
                    if (saved.density === 'compact' || saved.density === 'comfortable') {
                        this.density = saved.density
                        this.densityChosen = true
                    }
                    if (names.includes(saved.tab)) this.tab = saved.tab
                }
            } catch (e) {}

            this.restoreLanes()
        },

        // A lane starts as its definition says, then as the user left it (lanes that came and went keep their fold).
        restoreLanes() {
            for (const lane of this.lanes || []) {
                if (this.foldedLanes[lane.value] === undefined) this.foldedLanes[lane.value] = !! lane.collapsed
            }

            try {
                const saved = JSON.parse(localStorage.getItem(config.key) || 'null')
                for (const [value, folded] of Object.entries(saved?.lanes || {})) {
                    if (this.foldedLanes[value] !== undefined) this.foldedLanes[value] = !! folded
                }
            } catch (e) {}
        },

        persist() {
            try {
                // Before the columns arrive, folded and hidden are empty: keep the saved ones.
                const saved = this.drawn ? null : JSON.parse(localStorage.getItem(config.key) || 'null')
                localStorage.setItem(config.key, JSON.stringify({
                    folded: saved ? saved.folded || {} : this.folded,
                    hidden: saved ? saved.hidden || [] : this.hidden,
                    sidebar: this.sidebar,
                    lanes: this.foldedLanes,
                    density: this.densityChosen ? this.density : undefined,
                    tab: this.tab ?? undefined,
                }))
            } catch (e) {}
        },

        /* ------------------------------------------------------------ swimlanes */

        // Without swimlanes a column is one cell holding all its cards; with them, one cell per lane.
        cardsIn(column, lane = NO_LANE) {
            return this.lanes ? column.cards.filter((c) => c.lane === lane.value) : column.cards
        },

        countIn(column, lane = NO_LANE) {
            return this.lanes ? (column.counts?.[lane.value] || 0) : column.count
        },

        loadedIn(column, lane = NO_LANE) {
            return this.cardsIn(column, lane).length
        },

        cellKey(column, lane = NO_LANE) {
            return this.lanes ? column.name + '\u0000' + lane.value : column.name
        },

        laneCount(lane) {
            return this.columns.reduce((sum, c) => sum + (this.hidden.includes(c.name) ? 0 : this.countIn(c, lane)), 0)
        },

        // Where a card dropped at `index` of a cell goes in the column's card list (the lanes' cards are kept together).
        cellIndex(column, lane, index) {
            if (! this.lanes || lane === undefined) {
                return Math.min(index, column.cards.length)
            }

            const cell = this.cardsIn(column, { value: lane })
            const before = cell[index]
            if (before) return column.cards.indexOf(before)
            const last = cell[cell.length - 1]
            return last ? column.cards.indexOf(last) + 1 : column.cards.length
        },

        /* ------------------------------------------------------------ swimlane layout */

        // With swimlanes the board is a grid: the column headers on the first row, then a header row and a row
        // of cells per lane. Columns are `display: contents`, so their headers and cells sit on the grid directly.
        // The columns the lanes grid draws: the visible ones, or the selected tab's alone on a narrow screen.
        gridColumns() {
            const visible = this.columns.filter((c) => ! this.hidden.includes(c.name))
            return this.narrow ? visible.filter((c) => c.name === this.currentTab()) : visible
        },

        gridStyle() {
            if (! this.lanes) return {}
            if (this.narrow) return { 'grid-template-columns': 'minmax(0, 1fr)' }
            const widths = this.gridColumns().map((c) => this.folded[c.name] ? 'var(--pk-folded-width)' : 'var(--pk-col-width)')
            return { 'grid-template-columns': widths.join(' ') }
        },

        gridColumn(column) {
            return this.gridColumns().indexOf(column) + 1
        },

        headStyle(column) {
            return this.lanes ? { 'grid-column': this.gridColumn(column), 'grid-row': 1 } : {}
        },

        cellStyle(column, lane) {
            if (! this.lanes) return {}
            const i = this.lanes.findIndex((l) => l.value === lane.value)
            return { 'grid-column': this.gridColumn(column), 'grid-row': 3 + i * 2 }
        },

        laneStyle(i) {
            return { 'grid-column': '1 / -1', 'grid-row': 2 + i * 2 }
        },

        /* ------------------------------------------------------------ helpers */

        findColumn(name) {
            return this.columns.find((c) => c.name === name)
        },

        tone(color) {
            return TONES[color] ?? color ?? TONES.gray
        },

        dot(color) {
            return 'background:' + this.tone(color)
        },

        initials(name) {
            return (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase()
        },

        // A column label is text (escaped here) unless the server rendered an Htmlable.
        labelHtml(column) {
            return column.labelHtml ? column.label : this.escape(column.label)
        },

        escape(text) {
            return String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])
        },

        // Past or today, by the ISO date in the viewer's own calendar day.
        dueState(due) {
            if (! due?.date) return ''
            return due.date < this.today ? 'pk-due-past' : (due.date === this.today ? 'pk-due-today' : '')
        },

        notify(message, status) {
            if (window.FilamentNotification) {
                new window.FilamentNotification().title(message)[status]().send()
            }
        },
    }
}
