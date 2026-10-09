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
        refreshTimer: null,
        refreshSeq: 0,
        pending: 0,
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
        },

        destroy() {
            window.removeEventListener('keydown', this.onSlash)
            window.removeEventListener('resize', this.fit)
            clearInterval(this.poller)
            clearInterval(this.clock)
            this.moreObserver?.disconnect()
            document.body.classList.remove('pk-focus-sidebar')
            delete document.body._x_ignoreMutationObserver
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
                return
            }

            // Put the node back where Sortable took it from and let Alpine move it from
            // the state; otherwise Alpine's keyed list and the DOM disagree.
            const cards = (list) => list.querySelectorAll(':scope > .pk-card')
            event.item.remove()
            const ref = cards(event.from)[event.oldDraggableIndex] ?? null
            ref ? event.from.insertBefore(event.item, ref) : event.from.appendChild(event.item)

            this.move(id, from, to, event.newDraggableIndex, lane)
        },

        moveTo(id, from, to) {
            const lane = this.findColumn(from)?.cards.find((c) => c.id === id)?.lane
            this.move(id, from, to, 0, lane)
            const cell = lane === undefined ? '' : `[data-lane="${CSS.escape(lane)}"]`
            this.$nextTick(() => this.$refs.board.querySelector(`.pk-cards[data-column="${CSS.escape(to)}"]${cell}`)?.scrollTo({ top: 0, behavior: 'smooth' }))
        },

        // `index` is the position in the target cell (the column, or the column's lane with swimlanes).
        move(id, from, to, index, lane = undefined) {
            const source = this.findColumn(from)
            const target = this.findColumn(to)
            const at = source.cards.findIndex((c) => c.id === id)

            if (at < 0) {
                return
            }

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

            const undo = (message) => {
                const now = target.cards.findIndex((c) => c.id === id)
                if (now >= 0) target.cards.splice(now, 1)
                shift(-1)
                if (laned) card.lane = fromLane
                source.cards.splice(Math.min(at, source.cards.length), 0, card)
                card._pending = false
                this.notify(message, 'danger')
            }

            this.$wire.kanbanMove(id, to, order, this.search, this.active, laned ? lane : null)
                .then((result) => {
                    if (! result?.ok) {
                        return undo(result?.message || this.t.failed)
                    }

                    Object.assign(this.icons, result.icons || {})

                    for (const [name, summary] of Object.entries(result.summaries || {})) {
                        const column = this.findColumn(name)
                        if (column) column.summary = summary
                    }

                    if (from !== to || (laned && lane !== fromLane)) {
                        this.$dispatch('kanban-card-moved', { id, from, to, ...(laned ? { lane } : {}), card: result.card })
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
                .catch(() => undo(this.t.offline))
                .finally(() => this.pending--)
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
            this.$wire.mountAction(name, { kanbanRecord: card.id })
        },

        open(event, card, column = null, lane = NO_LANE) {
            // Ctrl/⌘-click toggles the card in the selection, Shift-click selects up to it; a selected card is not opened.
            if (this.hasSelection && (event.metaKey || event.ctrlKey || event.shiftKey || this.isSelected(card.id))) {
                event.preventDefault()
                return this.toggleSelect(card, column, lane, event)
            }

            if (config.cardAction && this.actionsFor(card).some((a) => a.name === config.cardAction)) {
                // A middle click still opens the url in a new tab.
                if (card.url && event.button === 1) return
                event.preventDefault()
                return this.runAction(config.cardAction, card)
            }

            if (! card.url) event.preventDefault()
        },

        create(column) {
            this.$wire.mountAction(this.createAction.name, { kanbanColumn: column.name })
        },

        targets(from) {
            return this.columns.filter((c) => c.name !== from && ! this.hidden.includes(c.name) && this.canDrop(from, c.name))
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
            this.$wire.mountAction(name, { kanbanRecords: this.selectedCards().map(([, card]) => card.id) })
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

            this.$wire.kanbanMoveMany(moves.map((m) => m.card.id), to, this.search, this.active, null)
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
                .finally(() => this.pending--)
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

            this.$wire.kanbanRefresh(this.search, this.active, loaded).catch(() => null).then((result) => {
                if (seq !== this.refreshSeq) {
                    return
                }

                // Without its columns the board would stay empty: try again, a little later each time.
                if (! result && ! this.drawn && this.retries < 5) {
                    this.refreshTimer = setTimeout(() => this.refresh(), 1000 * 2 ** this.retries++)
                }

                if (! result || (background && (this.dragging || this.pending))) {
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
                    column.cards.push(...(result?.cards || []).filter((c) => ! known.has(c.id)))
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
        gridStyle() {
            if (! this.lanes) return {}
            const widths = this.columns.filter((c) => ! this.hidden.includes(c.name)).map((c) => this.folded[c.name] ? 'var(--pk-folded-width)' : 'var(--pk-col-width)')
            return { 'grid-template-columns': widths.join(' ') }
        },

        gridColumn(column) {
            return this.columns.filter((c) => ! this.hidden.includes(c.name)).indexOf(column) + 1
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
