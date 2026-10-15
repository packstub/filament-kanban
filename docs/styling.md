# Styling

The board sits on the page the way a Filament table does: a container with a rounded border and a shadow, a toolbar on top (the focus-mode toggle at the start; the search, the filters, the density toggle and the columns manager at the end), the selection strip under it, then the columns on a soft surface. Every control in the toolbar and every menu is one of Filament's own components (button, icon button, input, select, date picker, checkbox, badge, dropdown, tabs), so a panel theme restyles them with the rest of the panel.

The stylesheet is plain CSS on Filament's colour variables (`--gray-*`, `--primary-*`, `--danger-*`) and follows the panel's theme and dark mode without compiling anything. Restyle it with the `--ps-kanban-*` variables on `.ps-kanban` from your theme, prefixed so they win over the plugin's stylesheet:

```css
.fi-body .ps-kanban {
    --ps-kanban-col-width: 20rem;
    --ps-kanban-col-radius: 0.75rem;
    --ps-kanban-ring: var(--primary-500);
    --ps-kanban-height: 40rem;        /* the board's height; pages fit it to the window by default */
}
```

| Variable | What it sets |
| --- | --- |
| `--ps-kanban-bg`, `--ps-kanban-surface`, `--ps-kanban-border`, `--ps-kanban-line` | The container's background, the columns' surface, borders and lines. |
| `--ps-kanban-text`, `--ps-kanban-muted`, `--ps-kanban-soft` | Text colours. |
| `--ps-kanban-ring`, `--ps-kanban-radius`, `--ps-kanban-shadow`, `--ps-kanban-outline`, `--ps-kanban-mono` | Focus rings and drop targets, the container's corners, shadow and ring, the monospaced font of eyebrows. |
| `--ps-kanban-col-bg`, `--ps-kanban-col-border`, `--ps-kanban-col-radius`, `--ps-kanban-col-width`, `--ps-kanban-folded-width`, `--ps-kanban-gap` | Columns. |
| `--ps-kanban-card-bg`, `--ps-kanban-card-border`, `--ps-kanban-card-hover`, `--ps-kanban-card-radius` | Cards. |
| `--ps-kanban-height` | The board's height; the page sets it to fill the window below the toolbar. |

The first group is the scheme [Filament Gantt](https://packstub.dev/docs/filament-gantt) (`--ps-gantt-*`) and [Filament Spreadsheet](https://packstub.dev/docs/filament-spreadsheet) (`--ps-sheet-*`) share, with the same names and the same values, so the three plugins look like one family and like a table on the same page.

Classes are prefixed `ps-kanban-` (`ps-kanban-toolbar`, `ps-kanban-col`, `ps-kanban-card`, `ps-kanban-card-selected`, `ps-kanban-col-target` while a card is dragged over a column it may enter…) for your own rules. The focus mode puts `ps-kanban-focus` on `<body>` (`ps-kanban-focus-sidebar` while the user brought the sidebar back).

## Dark mode

Handled: the variables are redefined under `.dark .ps-kanban`. Override them there for your own dark palette.

## Dates

A date-range filter's pickers display `date_format` from `config/packstub-kanban.php` (`M j, Y` when null) and start their weeks on `first_day_of_week` (1 = Monday … 7 = Sunday). Publish the file to change them:

```bash
php artisan vendor:publish --tag=packstub-kanban-config
```

## Translations

Every string is in `resources/lang/{en,ro,ru,de}/kanban.php`. Publish them to change or add a language:

```bash
php artisan vendor:publish --tag=packstub-kanban-translations
```
