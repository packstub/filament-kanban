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

// A filter's "nothing chosen", by type.
const blankFor = (filter) => filter.type === 'multiple' ? [] : filter.type === 'toggle' ? false : ''

export default function packstubKanban(config) {
    return {
        columns: config.columns,
        filters: config.filters,
        cardActions: config.cardActions || [],
        createAction: config.createAction,
        t: config.i18n,
        search: '',
        // '' for a select, [] for a multiple, false for a toggle
        active: Object.fromEntries(config.filters.map((f) => [f.name, blankFor(f)])),
        hidden: [],
        folded: {},
        loading: {},
        dragging: null,
        menu: null,
        sidebar: false,
        refreshTimer: null,
        refreshSeq: 0,
        pending: 0,

        init() {
            this.restore()

            // A bookmarked or shared board: the page was drawn as its URL says (config.initial).
            // A board drawn on a later request (lazy, deferred) came unfiltered: load it as the URL says.
            if (config.initial) {
                this.search = config.initial.search || ''
                Object.assign(this.active, config.initial.filters || {})
            } else if (config.url && this.readUrl()) {
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
            this.moreObserver?.disconnect()
            document.body.classList.remove('pk-focus-sidebar')
            delete document.body._x_ignoreMutationObserver
        },

        /* ------------------------------------------------------------ drag and drop */

        bindSortable(el, column) {
            if (! window.Sortable || el.sortable) {
                return
            }

            el.sortable = window.Sortable.create(el, {
                group: {
                    name: config.key,
                    pull: () => column.draggable,
                    put: (to, from) => this.canDrop(from.el.dataset.column, to.el.dataset.column),
                },
                sort: config.reorderable,
                draggable: '.pk-card',
                filter: '.pk-card-menu, .pk-card-popover, .pk-card-pending',
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
                    this.dragging = { from: event.from.dataset.column, id: event.item.dataset.id }
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
            this.dragging = null

            if (from === to && event.oldDraggableIndex === event.newDraggableIndex) {
                return
            }

            // Put the node back where Sortable took it from and let Alpine move it from
            // the state; otherwise Alpine's keyed list and the DOM disagree.
            const cards = (list) => list.querySelectorAll(':scope > .pk-card')
            event.item.remove()
            const ref = cards(event.from)[event.oldDraggableIndex] ?? null
            ref ? event.from.insertBefore(event.item, ref) : event.from.appendChild(event.item)

            this.move(id, from, to, event.newDraggableIndex)
        },

        moveTo(id, from, to) {
            this.move(id, from, to, 0)
            this.$nextTick(() => this.$refs.board.querySelector(`.pk-cards[data-column="${CSS.escape(to)}"]`)?.scrollTo({ top: 0, behavior: 'smooth' }))
        },

        move(id, from, to, index) {
            const source = this.findColumn(from)
            const target = this.findColumn(to)
            const at = source.cards.findIndex((c) => c.id === id)

            if (at < 0) {
                return
            }

            const [card] = source.cards.splice(at, 1)
            target.cards.splice(Math.min(index, target.cards.length), 0, card)

            const shift = (by) => {
                if (from === to) return
                source.count -= by
                target.count += by
                if (source.total !== null) source.total -= by
                if (target.total !== null) target.total += by
            }

            shift(1)
            card._pending = true
            this.pending++
            const order = config.reorderable ? target.cards.map((c) => c.id) : null

            const undo = (message) => {
                const now = target.cards.findIndex((c) => c.id === id)
                if (now >= 0) target.cards.splice(now, 1)
                source.cards.splice(Math.min(at, source.cards.length), 0, card)
                shift(-1)
                card._pending = false
                this.notify(message, 'danger')
            }

            this.$wire.kanbanMove(id, to, order, this.search, this.active)
                .then((result) => {
                    if (! result?.ok) {
                        return undo(result?.message || this.t.failed)
                    }

                    for (const [name, summary] of Object.entries(result.summaries || {})) {
                        const column = this.findColumn(name)
                        if (column) column.summary = summary
                    }

                    if (from !== to) {
                        this.$dispatch('kanban-card-moved', { id, from, to, card: result.card })
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

        canDrop(from, to) {
            if (from === to) {
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
            return this.actionsFor(card).length > 0 || (column.draggable && this.targets(column.name).length > 0)
        },

        runAction(name, card) {
            this.menu = null
            this.$wire.mountAction(name, { kanbanRecord: card.id })
        },

        open(event, card) {
            if (config.cardAction && this.actionsFor(card).some((a) => a.name === config.cardAction)) {
                // A modified click still opens the url in a new tab.
                if (card.url && (event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1)) return
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

        /* ------------------------------------------------------------ search, filters, paging */

        matches(card) {
            const needle = this.search.trim().toLowerCase()

            if (! needle) {
                return true
            }

            const raw = window.Alpine?.raw ? window.Alpine.raw(card) : card
            let text = TEXT.get(raw)

            if (text === undefined) {
                text = [card.eyebrow, card.title, card.aside, ...(card.meta || []), ...(card.badges || []).map((b) => b.label), ...(card.avatars || []).map((a) => a.name), card.search]
                    .filter(Boolean).join(' ').toLowerCase()
                TEXT.set(raw, text)
            }

            return needle.split(/\s+/).every((word) => text.includes(word))
        },

        visibleCount(column) {
            return column.cards.filter((card) => this.matches(card)).length
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
            const loaded = background ? Object.fromEntries(this.columns.map((c) => [c.name, c.cards.length])) : {}

            if (! background) {
                this.writeUrl()
            }

            this.$wire.kanbanRefresh(this.search, this.active, loaded).then((result) => {
                if (seq !== this.refreshSeq || ! result || (background && (this.dragging || this.pending))) {
                    return
                }

                for (const fresh of result.columns) {
                    const column = this.findColumn(fresh.name)
                    if (column) {
                        column.cards = fresh.cards
                        column.count = fresh.count
                        column.total = fresh.total
                        column.summary = fresh.summary
                    }
                }
            })
        },

        isActive(filter) {
            const value = this.active[filter.name]

            return filter.type === 'multiple' ? value.length > 0 : filter.type === 'toggle' ? value === true : value !== ''
        },

        // A few quick clicks are one request (as typing in the search is).
        toggleOption(filter, value) {
            const current = this.active[filter.name]
            this.active[filter.name] = current.includes(value) ? current.filter((v) => v !== value) : [...current, value]
            this.queueRefresh()
        },

        toggleFilter(filter) {
            this.active[filter.name] = ! this.active[filter.name]
            this.queueRefresh()
        },

        clearFilter(filter) {
            this.active[filter.name] = blankFor(filter)
            this.queueRefresh()
        },

        // The filter popover opens rightwards, or leftwards when that would leave the screen.
        openFilter(filter, button) {
            const key = 'filter:' + filter.name
            this.menu = this.menu === key ? null : key
            if (! this.menu) return

            this.$nextTick(() => {
                const popover = button.parentElement.querySelector('.pk-filter-menu')
                popover?.classList.remove('pk-menu-end')
                if (popover && popover.getBoundingClientRect().right > document.documentElement.clientWidth - 8) {
                    popover.classList.add('pk-menu-end')
                }
            })
        },

        /* ------------------------------------------------------------ the URL */

        // `?search=…&filters[owner_id][0]=1&filters[owner_id][1]=2&filters[mine]=1`: only the
        // filters the board defines are read, only values among their options are kept
        // (the server checks them again), and a toggle is on for 1, true, on or yes.
        readUrl() {
            let found = false

            try {
                const params = new URLSearchParams(window.location.search)
                const search = (params.get('search') || '').trim()

                if (search && config.searchable) {
                    this.search = search
                    found = true
                }

                const sent = {}
                for (const [key, value] of params) {
                    const m = key.match(/^filters\[([^\]]+)\](?:\[\d*\])?$/)
                    if (m && value !== '') (sent[m[1]] ??= []).push(value)
                }

                for (const filter of this.filters) {
                    const values = sent[filter.name] || []
                    const offered = filter.options.map((o) => o.value)
                    const known = [...new Set(values.filter((v) => offered.includes(v)))]

                    if (filter.type === 'multiple' && known.length) {
                        this.active[filter.name] = known
                        found = true
                    } else if (filter.type === 'toggle' && values.length && ['1', 'true', 'on', 'yes'].includes(values[0].toLowerCase())) {
                        this.active[filter.name] = true
                        found = true
                    } else if (filter.type === 'select' && known.length) {
                        this.active[filter.name] = known[0]
                        found = true
                    }
                }
            } catch (e) {}

            return found
        },

        // Mirrors the state into the query string in place (no history entry); an empty
        // search or filter is removed, so an untouched board keeps a clean URL. Lists use
        // indexed keys, and the brackets stay readable: Livewire's own URL sync (a #[Url]
        // property elsewhere on the page) rewrites the query string and would keep only
        // the last of repeated `[]` keys.
        writeUrl() {
            if (! config.url) return

            try {
                // Only what this board owns is replaced: other parameters (another component's
                // `filters[...]`, a `search` the board does not use) stay as they are.
                const names = new Set(this.filters.map((f) => f.name))
                const owned = (key) => (key === 'search' && config.searchable) || names.has(key.match(/^filters\[([^\]]+)\](?:\[\d*\])?$/)?.[1])
                const url = new URL(window.location.href)
                const pairs = [...url.searchParams].filter(([key]) => ! owned(key))

                const search = this.search.trim()
                if (search && config.searchable) pairs.push(['search', search])

                for (const filter of this.filters) {
                    const value = this.active[filter.name]

                    if (filter.type === 'multiple') {
                        value.forEach((v, i) => pairs.push([`filters[${filter.name}][${i}]`, v]))
                    } else if (filter.type === 'toggle') {
                        if (value === true) pairs.push([`filters[${filter.name}]`, '1'])
                    } else if (value !== '') {
                        pairs.push([`filters[${filter.name}]`, value])
                    }
                }

                const encode = (s) => encodeURIComponent(s).replace(/%5B/gi, '[').replace(/%5D/gi, ']').replace(/%20/g, '+')
                const query = pairs.map(([k, v]) => encode(k) + '=' + encode(v)).join('&')
                const next = url.pathname + (query ? '?' + query : '') + url.hash

                if (next !== window.location.pathname + window.location.search + window.location.hash) {
                    window.history.replaceState(window.history.state, '', next)
                }
            } catch (e) {}
        },

        // "Load more" loads itself when it scrolls into view; the button stays for keyboards.
        observeMore(el, column) {
            if (! window.IntersectionObserver) return
            this.moreObserver ??= new IntersectionObserver((entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting && entry.target.offsetParent) entry.target._more?.()
                }
            }, { rootMargin: '0px 0px 200px 0px' })
            el._more = () => this.more(column)
            this.moreObserver.observe(el)
        },

        more(column) {
            if (this.loading[column.name] || column.cards.length >= column.count) {
                return
            }

            this.loading[column.name] = true

            this.$wire.kanbanMore(column.name, column.cards.length, this.search, this.active)
                .then((cards) => {
                    const known = new Set(column.cards.map((c) => c.id))
                    column.cards.push(...(cards || []).filter((c) => ! known.has(c.id)))
                })
                .finally(() => {
                    this.loading[column.name] = false
                    // Still in view (a short page)? Observing again reports it, and the next page loads.
                    this.$nextTick(() => {
                        const el = this.$root.querySelector(`.pk-more[data-column="${CSS.escape(column.name)}"]`)
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

        toggleHidden(name) {
            this.hidden = this.hidden.includes(name) ? this.hidden.filter((n) => n !== name) : [...this.hidden, name]
            this.persist()
        },

        toggleSidebar() {
            this.sidebar = ! this.sidebar
            document.body.classList.toggle('pk-focus-sidebar', this.sidebar)
            this.persist()
        },

        restore() {
            for (const column of this.columns) {
                this.folded[column.name] = !! column.collapsed
            }

            try {
                const saved = JSON.parse(localStorage.getItem(config.key) || 'null')
                if (saved) {
                    const names = this.columns.map((c) => c.name)
                    Object.assign(this.folded, Object.fromEntries(Object.entries(saved.folded || {}).filter(([n]) => names.includes(n))))
                    this.hidden = (saved.hidden || []).filter((n) => names.includes(n))
                    this.sidebar = !! saved.sidebar
                }
            } catch (e) {}
        },

        persist() {
            try {
                localStorage.setItem(config.key, JSON.stringify({ folded: this.folded, hidden: this.hidden, sidebar: this.sidebar }))
            } catch (e) {}
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

        notify(message, status) {
            if (window.FilamentNotification) {
                new window.FilamentNotification().title(message)[status]().send()
            }
        },
    }
}
