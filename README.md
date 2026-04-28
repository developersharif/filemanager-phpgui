# PHP File Manager

A native desktop file manager built with [**php-gui**](https://github.com/developersharif/php-gui) — pure PHP, no Electron, no web server, no browser.

It is meant as a real-world showcase of what you can build with the php-gui widget set: paned layouts, treeviews with sortable columns, menus, popup context menus, modal prompts, keyboard bindings, and live status updates.

---

## Features

- **Browse** any directory with a 4-column file list (Name • Size • Modified • Kind)
- **Places sidebar** — Home, Desktop, Documents, Downloads, Pictures, Music, Videos, plus mounted devices under `/mnt` and `/media`
- **Toolbar** — Back ◀ / Forward ▶ / Up ▲ / Home 🏠 / Refresh ⟳ / address bar / 🔍 search
- **Browser-style history** with disabled back/forward buttons at the ends of the stack
- **Sortable columns** — click any header; click again to reverse the order
- **Right-click context menu** — different items for empty space, files, and folders
- **Copy / Cut / Paste** with cross-filesystem fallback and auto-rename on collision (`name (copy 1).ext`)
- **Cut entries are greyed out** until paste or clear
- **Rename / Delete** with confirm dialogs
- **New folder / new file** via a custom modal prompt
- **Properties dialog** — path, kind, size, permissions, owner, modified time
- **Open in Terminal** — tries `gnome-terminal`, `konsole`, `xfce4-terminal`, then `xterm`
- **Hidden-files toggle** (`Ctrl+H`)
- **Search filter** — type into the search box and press Enter to filter the current directory
- **Full keyboard map** — `F2`, `F5`, `Del`, `Alt+←/→/↑`, `Ctrl+C/X/V/A/H/N/L/Q`, `Ctrl+Shift+N`
- **Live status bar** — folder/file counts, selection size, free disk space

---


https://github.com/user-attachments/assets/35911a10-261c-4aa6-9053-e50ce87b1f2b


## Requirements

| | Minimum |
|---|---|
| **PHP** | 8.1 or newer |
| **Extension** | `ext-ffi` enabled (`ffi.enable=true` in `php.ini`) |
| **Composer** | any recent version |
| **OS** | Linux, macOS, or Windows |

> php-gui bundles Tcl/Tk on every platform, so you do **not** need to install Tk separately. On a fresh Linux box `composer install && php index.php` is everything.

### Enabling FFI

Most distros ship FFI installed but disabled. Edit your `php.ini`:

```ini
extension=ffi
ffi.enable=true
```

Verify:

```bash
php -m | grep FFI
```

---

## Installation

```bash
git clone https://github.com/developersharif/filemanager-phpgui           # or just copy the folder
cd filemanager-phpgui
composer install
```

## Running

```bash
php index.php                # opens at $HOME
php index.php /etc           # or pass a starting directory
php index.php "$(pwd)"       # browse the current shell directory
```

A native window opens immediately. No build step, no packaging.

---

## Keyboard shortcuts

| Action | Shortcut |
|---|---|
| Back | `Alt + ←` |
| Forward | `Alt + →` |
| Up to parent | `Alt + ↑` |
| Refresh | `F5` |
| Toggle hidden files | `Ctrl + H` |
| Focus the address bar | `Ctrl + L` |
| New file | `Ctrl + N` |
| New folder | `Ctrl + Shift + N` |
| Copy | `Ctrl + C` |
| Cut | `Ctrl + X` |
| Paste | `Ctrl + V` |
| Select all | `Ctrl + A` |
| Rename | `F2` |
| Delete | `Del` |
| Quit | `Ctrl + Q` |

Double-click a folder to enter it. Double-click a file to open it in the system default app (`xdg-open` / `open` / `start`).

---

## Project layout

```
filemanager-phpgui/
├── composer.json          # autoload + php-gui dependency
├── index.php              # entry point — boots the App
├── README.md
└── src/
    ├── App.php            # main controller — builds the UI, wires events
    ├── FileSystem.php     # directory listing, size formatting, sort
    ├── Operations.php     # copy / move / delete / mkdir / touch / rename
    ├── History.php        # browser-style back/forward stack
    ├── Clipboard.php      # copy/cut state for paste
    └── Icons.php          # extension → emoji glyph + kind label
```

Each file is small and self-contained — read top-to-bottom and you'll see how a php-gui app is structured.

### How the UI is laid out

```
┌─ Window ──────────────────────────────────────────────────────┐
│  Menu bar  (File / Edit / View / Go / Help)                   │
├───────────────────────────────────────────────────────────────┤
│  Toolbar  ◀ ▶ ▲ 🏠 ⟳   [ address bar ]   🔍 [ search ]        │
├───────────────────────────────────────────────────────────────┤
│           │                                                   │
│  Places   │   File list  (Name • Size • Modified • Kind)      │
│  sidebar  │   ── PanedWindow lets you drag the divider ──     │
│ (tree)    │                                                   │
│           │                                                   │
├───────────────────────────────────────────────────────────────┤
│  Status bar  — counts, selection size, free space             │
└───────────────────────────────────────────────────────────────┘
```

Both the sidebar and the file list are `Treeview` widgets. The split between them is a `PanedWindow` so users can drag the divider.

---

## How it uses php-gui

The app exercises most of the v1.9 widget set:

| php-gui widget | Used for |
|---|---|
| `Window`            | Top-level shell |
| `Menu`              | Main menu bar **and** right-click popup (`tk_popup`) |
| `Frame`             | Toolbar row, sidebar/main containers |
| `PanedWindow`       | Resizable sidebar/main split |
| `Treeview`          | Places sidebar (`show=tree`) and file list (`show=headings`) |
| `Scrollbar`         | Auto-attached to both treeviews via `Scrollbar::attachTo()` |
| `Input`             | Address bar, search box, rename/new-folder prompt |
| `Button`            | Toolbar buttons, dialog OK/Cancel |
| `Label`             | Status bar, properties rows, prompt headings |
| `TopLevel`          | Properties dialog, custom prompt, message boxes |

A couple of things needed raw Tcl through `ProcessTCL::evalTcl()`:

- **Right-click context menu** — `bind <Button-3>` + `tk_popup`, since v1.9's `popupMenu()` helper doesn't yet take pre-built items
- **Disabled-state styling** for back/forward buttons (`-state disabled`)
- **Window title updates** on navigation (`wm title`)
- **Sortable headings** — `Treeview` heading `-command` wired to `setSort($col)`
- **Tag styling** to grey out cut items (`tag configure cut -foreground #888`)

---

## Implementation notes

### The "modal prompt" trick

php-gui doesn't ship a modal-input dialog. The custom `promptString()` builds a `TopLevel` + `Input` + OK/Cancel pair, then pumps Tk's event loop manually:

```php
while (!$done) {
    $tcl->evalTcl('update');
    $tcl->drainPendingCallbacks();
    if ($tcl->shouldQuit()) break;
    usleep(20_000);
}
```

Two non-obvious points:

1. `php::executeCallback` only **enqueues** the callback id into a Tcl list. You must call `drainPendingCallbacks()` yourself — `update` alone is not enough.
2. Override the dialog's `WM_DELETE_WINDOW` protocol, otherwise clicking the X triggers `::exit_app` and quits the whole app.

### Copy/move across filesystems

`rename()` only works inside one mountpoint. `Operations::paste()` falls back to a recursive copy + delete on `EXDEV`-style failures, so dragging from `/home` to `/media/usb` Just Works.

### Filename collisions on paste

If `report.pdf` already exists in the destination, the next copy lands as `report (copy 1).pdf`, then `(copy 2)`, etc.

---

## Troubleshooting

**"Class 'FFI' not found"** — Enable `ext-ffi` in `php.ini` (see Requirements).

**Window opens then closes immediately** — Run from a terminal so PHP errors are visible: `php index.php`.

**No icons in file rows** — The icons are emoji glyphs. If your system font lacks them, install one (e.g. `fonts-noto-color-emoji` on Debian/Ubuntu).

**"Open in Terminal" does nothing** — None of `gnome-terminal`, `konsole`, `xfce4-terminal`, or `xterm` are on your `PATH`. Install one.

---

## Credits

Built with [**php-gui**](https://github.com/developersharif/php-gui) by [@developersharif](https://github.com/developersharif).

This file manager is a demo project — fork it, hack it, learn from it.

---

## License

MIT.
