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

export default function packstubKanban(config) {
    return {
        columns: config.columns,
        filters: config.filters,
        t: config.i18n,
        search: '',
        active: Object.fromEntries(config.filters.map((f) => [f.name, ''])),
        hidden: [],
        folded: {},
        loading: {},
        dragging: null,
        menu: null,
        sidebar: false,
        refreshTimer: null,
        refreshSeq: 0,

        init() {
            this.restore()

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
        },

        destroy() {
            window.removeEventListener('keydown', this.onSlash)
            window.removeEventListener('resize', this.fit)
            document.body.classList.remove('pk-focus-sidebar')
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
                    this.menu = null
                    this.dragging = { from: event.from.dataset.column, id: event.item.dataset.id }
                },
                onEnd: (event) => this.dropped(event),
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

            if (from !== to) {
                source.count--
                target.count++
            }

            card._pending = true
            const order = config.reorderable ? target.cards.map((c) => c.id) : null

            const undo = (message) => {
                const now = target.cards.findIndex((c) => c.id === id)
                if (now >= 0) target.cards.splice(now, 1)
                source.cards.splice(Math.min(at, source.cards.length), 0, card)
                if (from !== to) {
                    source.count++
                    target.count--
                }
                card._pending = false
                this.notify(message, 'danger')
            }

            this.$wire.kanbanMove(id, to, order)
                .then((result) => {
                    if (! result?.ok) {
                        return undo(result?.message || this.t.failed)
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
        },

        canDrop(from, to) {
            if (from === to) {
                return config.reorderable
            }

            const source = this.findColumn(from)
            const target = this.findColumn(to)

            return !! (source && target && source.draggable && target.droppable
                && (target.accepts === null || target.accepts.includes(from)))
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
                text = [card.eyebrow, card.title, card.aside, ...(card.meta || []), ...(card.badges || []).map((b) => b.label), card.search]
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

        refresh() {
            clearTimeout(this.refreshTimer)
            const seq = ++this.refreshSeq

            this.$wire.kanbanRefresh(this.search, this.active).then((result) => {
                if (seq !== this.refreshSeq || ! result) {
                    return
                }

                for (const fresh of result.columns) {
                    const column = this.findColumn(fresh.name)
                    if (column) {
                        column.cards = fresh.cards
                        column.count = fresh.count
                    }
                }
            })
        },

        more(column) {
            if (this.loading[column.name]) {
                return
            }

            this.loading[column.name] = true

            this.$wire.kanbanMore(column.name, column.cards.length, this.search, this.active)
                .then((cards) => {
                    const known = new Set(column.cards.map((c) => c.id))
                    column.cards.push(...(cards || []).filter((c) => ! known.has(c.id)))
                })
                .finally(() => (this.loading[column.name] = false))
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

        notify(message, status) {
            if (window.FilamentNotification) {
                new window.FilamentNotification().title(message)[status]().send()
            }
        },
    }
}
