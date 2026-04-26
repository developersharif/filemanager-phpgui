<?php

declare(strict_types=1);

namespace FileManager;

use PhpGui\Application;
use PhpGui\ProcessTCL;
use PhpGui\Widget\Button;
use PhpGui\Widget\Checkbutton;
use PhpGui\Widget\Combobox;
use PhpGui\Widget\Frame;
use PhpGui\Widget\Input;
use PhpGui\Widget\Label;
use PhpGui\Widget\Menu;
use PhpGui\Widget\PanedWindow;
use PhpGui\Widget\Scrollbar;
use PhpGui\Widget\TopLevel;
use PhpGui\Widget\Treeview;
use PhpGui\Widget\Window;

final class App
{
    private Application $app;
    private Window $window;
    private string $windowId;
    private ProcessTCL $tcl;

    private Treeview $sidebar;
    private Treeview $files;
    private Input $address;
    private Input $search;
    private Label $status;
    private Button $btnBack;
    private Button $btnFwd;
    private Button $btnUp;

    private History $history;
    private Clipboard $clipboard;

    private string $cwd;
    private bool $showHidden = false;
    private string $sortBy = 'name';
    private bool $sortAsc = true;
    private string $filter = '';

    /** rowId → row data for the file list. */
    private array $rowMeta = [];
    /** sidebar rowId → absolute path */
    private array $sidebarPaths = [];

    public function __construct(string $startDir)
    {
        $this->app = new Application();
        $this->tcl = ProcessTCL::getInstance();

        $this->window = new Window([
            'title'  => 'PHP File Manager',
            'width'  => 1100,
            'height' => 680,
        ]);
        $this->windowId = $this->window->getId();

        $this->history = new History();
        $this->clipboard = new Clipboard();
        $this->cwd = FileSystem::canonical($startDir);

        $this->buildMenu();
        $this->buildToolbar();
        $this->buildBody();
        $this->buildStatusBar();
        $this->bindKeys();

        $this->history->push($this->cwd);
        $this->refresh();
    }

    public function run(): void { $this->app->run(); }

    // ---------------------------------------------------------------------
    //  UI construction
    // ---------------------------------------------------------------------

    private function buildMenu(): void
    {
        $main = new Menu($this->windowId, ['type' => 'main']);

        $file = $main->addSubmenu('File');
        $file->addCommand('New Folder         Ctrl+Shift+N', fn() => $this->promptNewFolder());
        $file->addCommand('New File           Ctrl+N',       fn() => $this->promptNewFile());
        $file->addSeparator();
        $file->addCommand('Open in Terminal',                fn() => $this->openInTerminal($this->cwd));
        $file->addSeparator();
        $file->addCommand('Quit               Ctrl+Q',       fn() => exit(), ['foreground' => 'red']);

        $edit = $main->addSubmenu('Edit');
        $edit->addCommand('Copy           Ctrl+C', fn() => $this->copySelected());
        $edit->addCommand('Cut            Ctrl+X', fn() => $this->cutSelected());
        $edit->addCommand('Paste          Ctrl+V', fn() => $this->pasteHere());
        $edit->addSeparator();
        $edit->addCommand('Rename         F2',     fn() => $this->renameSelected());
        $edit->addCommand('Delete         Del',    fn() => $this->deleteSelected());
        $edit->addSeparator();
        $edit->addCommand('Select All     Ctrl+A', fn() => $this->selectAll());

        $view = $main->addSubmenu('View');
        $view->addCommand('Refresh        F5', fn() => $this->refresh());
        $view->addCommand('Toggle Hidden  Ctrl+H', fn() => $this->toggleHidden());
        $view->addSeparator();
        $view->addCommand('Sort by Name',     fn() => $this->setSort('name'));
        $view->addCommand('Sort by Size',     fn() => $this->setSort('size'));
        $view->addCommand('Sort by Modified', fn() => $this->setSort('modified'));
        $view->addCommand('Sort by Kind',     fn() => $this->setSort('kind'));

        $go = $main->addSubmenu('Go');
        $go->addCommand('Back        Alt+Left',  fn() => $this->goBack());
        $go->addCommand('Forward     Alt+Right', fn() => $this->goForward());
        $go->addCommand('Up          Alt+Up',    fn() => $this->goUp());
        $go->addCommand('Home',                  fn() => $this->navigate($this->homeDir()));
        $go->addCommand('Root  /',               fn() => $this->navigate('/'));

        $help = $main->addSubmenu('Help');
        $help->addCommand('About', fn() => TopLevel::messageBox(
            "PHP File Manager\n\nA native desktop file manager built with php-gui.",
            'ok'
        ));
    }

    private function buildToolbar(): void
    {
        $bar = new Frame($this->windowId);
        $bar->pack(['side' => 'top', 'fill' => 'x', 'padx' => 6, 'pady' => 6]);

        $this->btnBack = new Button($bar->getId(), [
            'text' => '◀',  'width' => 3,
            'command' => fn() => $this->goBack(),
        ]);
        $this->btnBack->pack(['side' => 'left', 'padx' => 2]);

        $this->btnFwd = new Button($bar->getId(), [
            'text' => '▶',  'width' => 3,
            'command' => fn() => $this->goForward(),
        ]);
        $this->btnFwd->pack(['side' => 'left', 'padx' => 2]);

        $this->btnUp = new Button($bar->getId(), [
            'text' => '▲',  'width' => 3,
            'command' => fn() => $this->goUp(),
        ]);
        $this->btnUp->pack(['side' => 'left', 'padx' => 2]);

        (new Button($bar->getId(), [
            'text' => '🏠', 'width' => 3,
            'command' => fn() => $this->navigate($this->homeDir()),
        ]))->pack(['side' => 'left', 'padx' => 2]);

        (new Button($bar->getId(), [
            'text' => '⟳',  'width' => 3,
            'command' => fn() => $this->refresh(),
        ]))->pack(['side' => 'left', 'padx' => 2]);

        // Search box on the right.
        $this->search = new Input($bar->getId(), ['text' => '']);
        $this->search->pack(['side' => 'right', 'padx' => 4]);
        $this->search->onEnter(function () {
            $this->filter = trim($this->search->getValue());
            $this->refresh();
        });
        (new Label($bar->getId(), ['text' => '🔍', 'fg' => '#666']))
            ->pack(['side' => 'right']);

        // Address bar fills the remaining space.
        $this->address = new Input($bar->getId(), ['text' => $this->cwd]);
        $this->address->pack(['side' => 'left', 'fill' => 'x', 'expand' => 1, 'padx' => 8]);
        $this->address->onEnter(function () {
            $target = trim($this->address->getValue());
            if ($target !== '' && is_dir($target)) {
                $this->navigate($target);
            } else {
                $this->setStatus("Path not found: {$target}");
                $this->address->setValue($this->cwd);
            }
        });
    }

    private function buildBody(): void
    {
        $split = new PanedWindow($this->windowId, ['orient' => 'horizontal']);
        $split->pack(['fill' => 'both', 'expand' => 1, 'padx' => 6, 'pady' => 0]);

        // ---- Left: places sidebar
        $left = new Frame($split->getId());
        $right = new Frame($split->getId());
        $split->addPane($left,  ['weight' => 1]);
        $split->addPane($right, ['weight' => 4]);

        (new Label($left->getId(), [
            'text' => 'Places', 'font' => 'Arial 10 bold',
            'fg'   => '#444',   'pady' => 4,
        ]))->pack(['side' => 'top', 'anchor' => 'w', 'padx' => 6]);

        $this->sidebar = new Treeview($left->getId(), [
            'columns'    => ['label'],
            'show'       => 'tree',
            'selectmode' => 'browse',
            'height'     => 20,
        ]);
        $this->sidebar->pack(['side' => 'left', 'fill' => 'both', 'expand' => 1]);
        Scrollbar::attachTo($this->sidebar, 'vertical');

        $this->populateSidebar();

        $this->sidebar->onSelect(function (array $rowIds) {
            if ($rowIds === []) return;
            $path = $this->sidebarPaths[$rowIds[0]] ?? null;
            if ($path !== null && is_dir($path)) $this->navigate($path);
        });

        // ---- Right: file list
        $this->files = new Treeview($right->getId(), [
            'columns'    => ['name', 'size', 'modified', 'kind'],
            'headings'   => ['Name', 'Size', 'Modified', 'Kind'],
            'show'       => 'headings',
            'selectmode' => 'extended',
            'height'     => 20,
        ]);
        $this->files->pack(['side' => 'left', 'fill' => 'both', 'expand' => 1]);
        Scrollbar::attachTo($this->files, 'vertical');

        $this->files->setColumn('name',     ['width' => 380, 'anchor' => 'w']);
        $this->files->setColumn('size',     ['width' =>  90, 'anchor' => 'e']);
        $this->files->setColumn('modified', ['width' => 140, 'anchor' => 'w']);
        $this->files->setColumn('kind',     ['width' => 140, 'anchor' => 'w']);

        // Headings act as sort toggles.
        $this->setSortableHeading('name',     'Name');
        $this->setSortableHeading('size',     'Size');
        $this->setSortableHeading('modified', 'Modified');
        $this->setSortableHeading('kind',     'Kind');

        // Tag styling — lets us grey out cut entries.
        $this->tcl->evalTcl(
            $this->files->getTclPath() . ' tag configure cut -foreground #888'
        );
        $this->tcl->evalTcl(
            $this->files->getTclPath() . ' tag configure dir  -foreground #1976D2'
        );

        $this->files->onDoubleClick(function (?string $rowId) {
            if ($rowId === null) return;
            $row = $this->rowMeta[$rowId] ?? null;
            if ($row === null) return;
            if ($row['isDir']) $this->navigate($row['path']);
            else $this->openExternal($row['path']);
        });

        $this->files->onSelect(function (array $rowIds) {
            $this->updateSelectionStatus(count($rowIds));
        });

        $this->bindContextMenu();
    }

    private function buildStatusBar(): void
    {
        $this->status = new Label($this->windowId, [
            'text'   => 'Ready',
            'font'   => 'Arial 10',
            'fg'     => '#333',
            'bg'     => '#f1f1f1',
            'relief' => 'sunken',
            'padx'   => 8,
            'pady'   => 4,
            'anchor' => 'w',
        ]);
        $this->status->pack(['side' => 'bottom', 'fill' => 'x']);
    }

    private function setSortableHeading(string $col, string $label): void
    {
        // Tk's heading -command runs a callback; we wire it to setSort($col).
        $cbId = 'sort_' . $col . '_' . uniqid();
        $this->tcl->registerCallback($cbId, fn() => $this->setSort($col));
        $this->tcl->evalTcl(
            $this->files->getTclPath() . ' heading ' . $col
            . ' -text ' . $this->tclQ($label)
            . ' -command {php::executeCallback ' . $cbId . '}'
        );
    }

    // ---------------------------------------------------------------------
    //  Navigation
    // ---------------------------------------------------------------------

    private function navigate(string $path): void
    {
        $path = FileSystem::canonical($path);
        if (!is_dir($path)) {
            $this->setStatus('Not a directory: ' . $path);
            return;
        }
        if ($path === $this->cwd) { $this->refresh(); return; }
        $this->cwd = $path;
        $this->history->push($path);
        $this->filter = '';
        $this->search->setValue('');
        $this->refresh();
    }

    private function goBack(): void
    {
        $p = $this->history->back();
        if ($p !== null && is_dir($p)) {
            $this->cwd = $p;
            $this->refresh();
        }
    }

    private function goForward(): void
    {
        $p = $this->history->forward();
        if ($p !== null && is_dir($p)) {
            $this->cwd = $p;
            $this->refresh();
        }
    }

    private function goUp(): void
    {
        $parent = dirname($this->cwd);
        if ($parent !== $this->cwd) $this->navigate($parent);
    }

    private function refresh(): void
    {
        $this->address->setValue($this->cwd);
        $this->setWindowTitle('PHP File Manager — ' . $this->cwd);

        // Re-populate the file list.
        $this->files->clear();
        $this->rowMeta = [];

        $rows = FileSystem::listDir($this->cwd, $this->showHidden);

        if ($this->filter !== '') {
            $needle = mb_strtolower($this->filter);
            $rows = array_values(array_filter(
                $rows,
                fn(array $r) => str_contains(mb_strtolower($r['name']), $needle)
            ));
        }

        FileSystem::sort($rows, $this->sortBy, $this->sortAsc);

        $cutSet = [];
        if ($this->clipboard->hasContent() && $this->clipboard->isCut()) {
            $cutSet = array_flip($this->clipboard->paths());
        }

        $files = 0;
        $dirs = 0;
        $totalBytes = 0;
        foreach ($rows as $row) {
            $tags = [$row['isDir'] ? 'dir' : 'file'];
            if (isset($cutSet[$row['path']])) $tags[] = 'cut';

            $rowId = $this->files->insert(null, [
                'name'     => $row['icon'] . '  ' . $row['name'],
                'size'     => $row['isDir'] ? '' : $row['size'],
                'modified' => $row['modified'],
                'kind'     => $row['kind'],
            ], ['tags' => $tags]);

            $this->rowMeta[$rowId] = $row;
            if ($row['isDir']) $dirs++; else { $files++; $totalBytes += $row['sizeBytes']; }
        }

        $this->setButtonState($this->btnBack, $this->history->canBack());
        $this->setButtonState($this->btnFwd,  $this->history->canForward());
        $this->setButtonState($this->btnUp,   dirname($this->cwd) !== $this->cwd);

        $this->setStatus(sprintf(
            '%d folders, %d files (%s)   •   %s',
            $dirs, $files, FileSystem::formatSize($totalBytes), FileSystem::diskFree($this->cwd)
        ));
    }

    private function setWindowTitle(string $title): void
    {
        // Window doesn't expose setTitle directly — drive it via wm.
        $this->tcl->evalTcl('wm title ' . $this->window->getTclPath() . ' ' . $this->tclQ($title));
    }

    private function setButtonState(Button $btn, bool $enabled): void
    {
        $this->tcl->evalTcl(
            $btn->getTclPath() . ' configure -state ' . ($enabled ? 'normal' : 'disabled')
        );
    }

    // ---------------------------------------------------------------------
    //  Sidebar
    // ---------------------------------------------------------------------

    private function populateSidebar(): void
    {
        $home = $this->homeDir();

        $places = [
            ['Home',      $home],
            ['Desktop',   $home . '/Desktop'],
            ['Documents', $home . '/Documents'],
            ['Downloads', $home . '/Downloads'],
            ['Pictures',  $home . '/Pictures'],
            ['Music',     $home . '/Music'],
            ['Videos',    $home . '/Videos'],
        ];
        foreach ($places as [$label, $path]) {
            if (!is_dir($path)) continue;
            $rid = $this->sidebar->insert(null, [], [
                'text' => Icons::placeIcon($label) . '  ' . $label,
                'open' => true,
            ]);
            $this->sidebarPaths[$rid] = $path;
        }

        // Devices header.
        $devicesRoot = $this->sidebar->insert(null, [], [
            'text' => '— Devices —', 'open' => true,
        ]);

        $rid = $this->sidebar->insert($devicesRoot, [], [
            'text' => Icons::placeIcon('root') . '  Filesystem',
        ]);
        $this->sidebarPaths[$rid] = '/';

        foreach (['/mnt', '/media'] as $mountRoot) {
            if (!is_dir($mountRoot)) continue;
            foreach (FileSystem::listDir($mountRoot, false) as $row) {
                if (!$row['isDir']) continue;
                $rid = $this->sidebar->insert($devicesRoot, [], [
                    'text' => '💾  ' . $row['name'],
                ]);
                $this->sidebarPaths[$rid] = $row['path'];
            }
        }
    }

    // ---------------------------------------------------------------------
    //  Context menu (right-click on file list)
    // ---------------------------------------------------------------------

    private function bindContextMenu(): void
    {
        $cbId = 'ctx_' . uniqid();
        $varRow = 'phpgui_ctx_row_' . $cbId;
        $varX   = 'phpgui_ctx_x_'   . $cbId;
        $varY   = 'phpgui_ctx_y_'   . $cbId;

        $this->tcl->registerCallback($cbId, function () use ($varRow, $varX, $varY) {
            $rowId = trim($this->tcl->getVar($varRow));
            $x = (int) trim($this->tcl->getVar($varX));
            $y = (int) trim($this->tcl->getVar($varY));

            if ($rowId !== '') {
                // Right-click on a row → make it the (sole) selection if not already selected.
                $sel = $this->files->getSelected();
                if (!in_array($rowId, $sel, true)) {
                    $this->files->setSelected([$rowId]);
                }
                $this->showContextMenu($x, $y, false);
            } else {
                // Right-click on empty space → folder-context menu.
                $this->files->setSelected([]);
                $this->showContextMenu($x, $y, true);
            }
        });

        $treePath = $this->files->getTclPath();
        $this->tcl->evalTcl(
            "bind {$treePath} <Button-3> "
            . '{ set ::' . $varRow . ' [' . $treePath . ' identify row %x %y]; '
            . 'set ::' . $varX . ' %X; set ::' . $varY . ' %Y; '
            . 'php::executeCallback ' . $cbId . ' }'
        );
    }

    private function showContextMenu(int $x, int $y, bool $emptyArea): void
    {
        $menu = new Menu($this->windowId, ['type' => 'normal']);

        if ($emptyArea) {
            $menu->addCommand('New Folder', fn() => $this->promptNewFolder());
            $menu->addCommand('New File',   fn() => $this->promptNewFile());
            $menu->addSeparator();
            if ($this->clipboard->hasContent()) {
                $menu->addCommand('Paste', fn() => $this->pasteHere());
                $menu->addSeparator();
            }
            $menu->addCommand('Open in Terminal', fn() => $this->openInTerminal($this->cwd));
            $menu->addCommand('Refresh',          fn() => $this->refresh());
            $menu->addCommand('Toggle Hidden',    fn() => $this->toggleHidden());
        } else {
            $sel = $this->files->getSelected();
            $singleRow = count($sel) === 1 ? ($this->rowMeta[$sel[0]] ?? null) : null;

            if ($singleRow !== null && $singleRow['isDir']) {
                $menu->addCommand('Open',             fn() => $this->navigate($singleRow['path']));
                $menu->addCommand('Open in Terminal', fn() => $this->openInTerminal($singleRow['path']));
            } elseif ($singleRow !== null) {
                $menu->addCommand('Open', fn() => $this->openExternal($singleRow['path']));
            }
            $menu->addSeparator();
            $menu->addCommand('Copy', fn() => $this->copySelected());
            $menu->addCommand('Cut',  fn() => $this->cutSelected());
            if ($this->clipboard->hasContent()) {
                $menu->addCommand('Paste', fn() => $this->pasteHere());
            }
            $menu->addSeparator();
            if ($singleRow !== null) {
                $menu->addCommand('Rename', fn() => $this->renameSelected());
            }
            $menu->addCommand('Delete', fn() => $this->deleteSelected(), ['foreground' => 'red']);
            $menu->addSeparator();
            if ($singleRow !== null) {
                $menu->addCommand('Properties', fn() => $this->showProperties($singleRow));
            }
        }

        $path = $menu->getTclPath();
        $this->tcl->evalTcl("tk_popup {$path} {$x} {$y}");
        // The menu lifetime is bound to the window for cleanup. We don't
        // destroy() it immediately — Tk needs it to live for the duration
        // of the popup interaction.
    }

    // ---------------------------------------------------------------------
    //  Operations
    // ---------------------------------------------------------------------

    private function selectedPaths(): array
    {
        $paths = [];
        foreach ($this->files->getSelected() as $rid) {
            if (isset($this->rowMeta[$rid])) $paths[] = $this->rowMeta[$rid]['path'];
        }
        return $paths;
    }

    private function copySelected(): void
    {
        $paths = $this->selectedPaths();
        if ($paths === []) { $this->setStatus('Nothing selected to copy.'); return; }
        $this->clipboard->copy($paths);
        $this->setStatus(count($paths) . ' item(s) copied.');
        $this->refresh();
    }

    private function cutSelected(): void
    {
        $paths = $this->selectedPaths();
        if ($paths === []) { $this->setStatus('Nothing selected to cut.'); return; }
        $this->clipboard->cut($paths);
        $this->setStatus(count($paths) . ' item(s) cut.');
        $this->refresh();
    }

    private function pasteHere(): void
    {
        if (!$this->clipboard->hasContent()) {
            $this->setStatus('Clipboard is empty.');
            return;
        }
        $err = Operations::paste($this->clipboard->paths(), $this->cwd, $this->clipboard->isCut());
        if ($err !== '') {
            TopLevel::messageBox($err, 'ok');
            $this->setStatus($err);
            return;
        }
        if ($this->clipboard->isCut()) $this->clipboard->clear();
        $this->setStatus('Paste complete.');
        $this->refresh();
    }

    private function deleteSelected(): void
    {
        $paths = $this->selectedPaths();
        if ($paths === []) return;

        $msg = count($paths) === 1
            ? 'Permanently delete "' . basename($paths[0]) . '"?'
            : 'Permanently delete ' . count($paths) . ' items?';

        $answer = TopLevel::messageBox($msg, 'yesno');
        if ($answer !== 'yes') return;

        $err = Operations::delete($paths);
        if ($err !== '') {
            TopLevel::messageBox($err, 'ok');
            $this->setStatus($err);
        } else {
            $this->setStatus(count($paths) . ' item(s) deleted.');
        }
        $this->refresh();
    }

    private function renameSelected(): void
    {
        $sel = $this->files->getSelected();
        if (count($sel) !== 1) return;
        $row = $this->rowMeta[$sel[0]] ?? null;
        if ($row === null) return;

        $new = $this->promptString('Rename', 'Enter new name:', $row['name']);
        if ($new === null || $new === '' || $new === $row['name']) return;
        if (str_contains($new, '/')) {
            TopLevel::messageBox('Name cannot contain "/".', 'ok');
            return;
        }
        $err = Operations::rename($row['path'], $new);
        if ($err !== '') TopLevel::messageBox($err, 'ok');
        $this->refresh();
    }

    private function promptNewFolder(): void
    {
        $name = $this->promptString('New Folder', 'Folder name:', 'New folder');
        if ($name === null || $name === '') return;
        $err = Operations::mkdir($this->cwd, $name);
        if ($err !== '') TopLevel::messageBox($err, 'ok');
        $this->refresh();
    }

    private function promptNewFile(): void
    {
        $name = $this->promptString('New File', 'File name:', 'untitled.txt');
        if ($name === null || $name === '') return;
        $err = Operations::touch($this->cwd, $name);
        if ($err !== '') TopLevel::messageBox($err, 'ok');
        $this->refresh();
    }

    private function selectAll(): void
    {
        $ids = array_keys($this->rowMeta);
        $this->files->setSelected($ids);
        $this->updateSelectionStatus(count($ids));
    }

    private function toggleHidden(): void
    {
        $this->showHidden = !$this->showHidden;
        $this->setStatus($this->showHidden ? 'Showing hidden files.' : 'Hiding hidden files.');
        $this->refresh();
    }

    private function setSort(string $col): void
    {
        if ($this->sortBy === $col) $this->sortAsc = !$this->sortAsc;
        else { $this->sortBy = $col; $this->sortAsc = true; }
        $this->setStatus('Sorted by ' . $col . ' ' . ($this->sortAsc ? '↑' : '↓'));
        $this->refresh();
    }

    private function showProperties(array $row): void
    {
        $top = new TopLevel(['title' => 'Properties — ' . $row['name'], 'width' => 420, 'height' => 360]);

        $rows = [
            ['Name',     $row['name']],
            ['Path',     $row['path']],
            ['Type',     $row['kind'] . ($row['isLink'] ? ' (symlink)' : '')],
            ['Size',     $row['isDir'] ? $this->dirSizeSummary($row['path']) : $row['size'] . ' (' . number_format($row['sizeBytes']) . ' bytes)'],
            ['Modified', $row['modified'] ?: '—'],
            ['Permissions', $this->permString($row['path'])],
            ['Owner',    $this->ownerString($row['path'])],
        ];

        foreach ($rows as $i => [$key, $val]) {
            (new Label($top->getId(), [
                'text' => $key . ':', 'font' => 'Arial 10 bold', 'fg' => '#444',
            ]))->grid(['row' => $i, 'column' => 0, 'sticky' => 'w', 'padx' => 12, 'pady' => 4]);

            (new Label($top->getId(), [
                'text' => $val, 'font' => 'Arial 10', 'fg' => '#222',
                'wraplength' => 260, 'justify' => 'left',
            ]))->grid(['row' => $i, 'column' => 1, 'sticky' => 'w', 'padx' => 12, 'pady' => 4]);
        }

        (new Button($top->getId(), [
            'text' => 'Close', 'command' => fn() => $top->destroy(),
        ]))->grid(['row' => count($rows), 'column' => 0, 'columnspan' => 2, 'pady' => 12]);
    }

    private function dirSizeSummary(string $path): string
    {
        // Top-level only — a full recursive scan can take a long time on big trees.
        $items = @scandir($path);
        if ($items === false) return '—';
        $count = count($items) - 2; // Discount '.' and '..'.
        return $count . ' item(s) (top level)';
    }

    private function permString(string $path): string
    {
        $perms = @fileperms($path);
        if ($perms === false) return '—';
        return substr(sprintf('%o', $perms), -4);
    }

    private function ownerString(string $path): string
    {
        $uid = @fileowner($path);
        $gid = @filegroup($path);
        if ($uid === false) return '—';
        if (function_exists('posix_getpwuid')) {
            $u = posix_getpwuid($uid)['name'] ?? (string) $uid;
            $g = posix_getgrgid($gid)['name'] ?? (string) $gid;
            return $u . ':' . $g;
        }
        return $uid . ':' . $gid;
    }

    // ---------------------------------------------------------------------
    //  Misc helpers
    // ---------------------------------------------------------------------

    private function openExternal(string $path): void
    {
        // Best-effort: Linux (xdg-open), macOS (open), Windows (start).
        $cmd = match (PHP_OS_FAMILY) {
            'Darwin'  => 'open ',
            'Windows' => 'start "" ',
            default   => 'xdg-open ',
        };
        @exec($cmd . escapeshellarg($path) . ' >/dev/null 2>&1 &');
        $this->setStatus('Opening: ' . basename($path));
    }

    private function openInTerminal(string $path): void
    {
        // Try a few common terminals.
        $candidates = [
            'gnome-terminal --working-directory=' . escapeshellarg($path),
            'konsole --workdir ' . escapeshellarg($path),
            'xfce4-terminal --working-directory=' . escapeshellarg($path),
            'xterm -e "cd ' . escapeshellarg($path) . ' && exec $SHELL"',
        ];
        foreach ($candidates as $cmd) {
            $bin = strtok($cmd, ' ');
            if ($bin !== false && $this->which($bin) !== null) {
                @exec($cmd . ' >/dev/null 2>&1 &');
                $this->setStatus('Opened terminal in ' . $path);
                return;
            }
        }
        $this->setStatus('No supported terminal found.');
    }

    private function which(string $bin): ?string
    {
        $out = @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
        $out = $out === null ? '' : trim($out);
        return $out !== '' ? $out : null;
    }

    private function bindKeys(): void
    {
        $win = $this->window->getTclPath();
        $bindings = [
            '<Alt-Left>'         => fn() => $this->goBack(),
            '<Alt-Right>'        => fn() => $this->goForward(),
            '<Alt-Up>'           => fn() => $this->goUp(),
            '<F5>'               => fn() => $this->refresh(),
            '<F2>'               => fn() => $this->renameSelected(),
            '<Delete>'           => fn() => $this->deleteSelected(),
            '<Control-c>'        => fn() => $this->copySelected(),
            '<Control-x>'        => fn() => $this->cutSelected(),
            '<Control-v>'        => fn() => $this->pasteHere(),
            '<Control-a>'        => fn() => $this->selectAll(),
            '<Control-h>'        => fn() => $this->toggleHidden(),
            '<Control-n>'        => fn() => $this->promptNewFile(),
            '<Control-Shift-N>'  => fn() => $this->promptNewFolder(),
            '<Control-q>'        => fn() => exit(),
            '<Control-l>'        => fn() => $this->tcl->evalTcl('focus ' . $this->address->getTclPath()),
        ];
        foreach ($bindings as $seq => $cb) {
            $cbId = 'kb_' . uniqid();
            $this->tcl->registerCallback($cbId, $cb);
            $this->tcl->evalTcl("bind {$win} {$seq} {php::executeCallback {$cbId}}");
        }
    }

    private function promptString(string $title, string $prompt, string $default = ''): ?string
    {
        // Modal-ish dialog using a TopLevel + Input + OK/Cancel.
        $top = new TopLevel(['title' => $title, 'width' => 360, 'height' => 140]);
        $top->focus();

        (new Label($top->getId(), ['text' => $prompt, 'font' => 'Arial 10']))
            ->pack(['anchor' => 'w', 'padx' => 12, 'pady' => 8]);

        $input = new Input($top->getId(), ['text' => $default]);
        $input->pack(['fill' => 'x', 'padx' => 12]);

        $btnRow = new Frame($top->getId());
        $btnRow->pack(['fill' => 'x', 'padx' => 12, 'pady' => 12]);

        $result = null;
        $done = false;

        $finish = function (?string $value) use (&$result, &$done, $top) {
            if ($done) return;
            $result = $value;
            $done = true;
            $top->destroy();
        };

        (new Button($btnRow->getId(), [
            'text' => 'Cancel', 'command' => fn() => $finish(null),
        ]))->pack(['side' => 'right', 'padx' => 4]);

        (new Button($btnRow->getId(), [
            'text' => 'OK',
            'bg' => '#1976D2', 'fg' => 'white',
            'command' => fn() => $finish($input->getValue()),
        ]))->pack(['side' => 'right', 'padx' => 4]);

        $input->onEnter(fn() => $finish($input->getValue()));

        // Override the dialog's window-close button so it cancels the prompt
        // instead of triggering ::exit_app (which would quit the whole app).
        $closeId = 'dlg_close_' . uniqid();
        $this->tcl->registerCallback($closeId, fn() => $finish(null));
        $this->tcl->evalTcl(
            'wm protocol ' . $top->getTclPath()
            . ' WM_DELETE_WINDOW {php::executeCallback ' . $closeId . '}'
        );

        // Pump Tk's event loop AND drain the PHP callback queue. Without the
        // drain, php::executeCallback only enqueues an id — the closure that
        // sets $done never fires and the loop spins forever.
        while (!$done) {
            $this->tcl->evalTcl('update');
            $this->tcl->drainPendingCallbacks();
            if ($this->tcl->shouldQuit()) { $done = true; $result = null; break; }
            usleep(20_000);
        }
        $this->tcl->unregisterCallback($closeId);
        return $result;
    }

    private function setStatus(string $msg): void
    {
        $this->status->setText($msg);
    }

    private function updateSelectionStatus(int $count): void
    {
        if ($count === 0) { $this->refresh(); return; }
        $bytes = 0; $files = 0; $dirs = 0;
        foreach ($this->files->getSelected() as $rid) {
            $r = $this->rowMeta[$rid] ?? null;
            if ($r === null) continue;
            if ($r['isDir']) $dirs++; else { $files++; $bytes += $r['sizeBytes']; }
        }
        $this->setStatus(sprintf(
            '%d selected (%d folders, %d files, %s)',
            $count, $dirs, $files, FileSystem::formatSize($bytes)
        ));
    }

    private function homeDir(): string
    {
        return $_SERVER['HOME'] ?? getenv('HOME') ?: '/';
    }

    /** Quote a string as a Tcl literal (braces escape everything inside). */
    private function tclQ(string $s): string
    {
        // Brace-quoting fails if the value contains unbalanced { or }.
        // For status / titles / labels that's vanishingly rare, but be safe.
        if (strpbrk($s, '{}\\') === false) return '{' . $s . '}';
        return '"' . addcslashes($s, "\"\\\$[]") . '"';
    }
}
