# Changelog

All notable changes to `packstub/filament-kanban` are documented in this file.

## 0.1.0 - 2026-09-30

First public preview. The API may still change before 1.0.

- First release: `Board`, `Column`, `Card`, `Filter`; `KanbanPage` and `KanbanResourcePage`; the `InteractsWithKanban` trait for any Livewire component.
- Optimistic drag and drop on Filament's SortableJS, with server-enforced column rules (`visible`, `accepts`, `droppable`, `draggable`) and roll-back with the reason when a move is refused.
- Instant in-browser search refined by the server, select filters, paging per column, "Move to…" menu, folding and hiding columns remembered per user, focus mode without the sidebar.
- Translations: en, ro, ru, de.
