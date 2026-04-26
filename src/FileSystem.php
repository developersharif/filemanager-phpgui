<?php

declare(strict_types=1);

namespace FileManager;

final class FileSystem
{
    /**
     * List entries inside $dir. Returns rows ready for the Treeview:
     *   ['path' => string, 'name' => string, 'icon' => string,
     *    'size' => string, 'sizeBytes' => int, 'modified' => string,
     *    'mtime' => int, 'kind' => string, 'isDir' => bool, 'isLink' => bool]
     */
    public static function listDir(string $dir, bool $showHidden = false): array
    {
        $rows = [];
        $handle = @opendir($dir);
        if ($handle === false) {
            return $rows;
        }
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') continue;
            if (!$showHidden && str_starts_with($entry, '.')) continue;

            $full = rtrim($dir, '/') . '/' . $entry;
            $isLink = is_link($full);
            $isDir = is_dir($full);

            [$icon, $kind] = Icons::forFile($full, $isDir, $isLink);

            $sizeBytes = 0;
            $sizeStr = '';
            if (!$isDir) {
                $sizeBytes = @filesize($full) ?: 0;
                $sizeStr = self::formatSize($sizeBytes);
            }

            $mtime = @filemtime($full) ?: 0;

            $rows[] = [
                'path'      => $full,
                'name'      => $entry,
                'icon'      => $icon,
                'size'      => $sizeStr,
                'sizeBytes' => $sizeBytes,
                'modified'  => $mtime ? date('Y-m-d H:i', $mtime) : '',
                'mtime'     => $mtime,
                'kind'      => $kind,
                'isDir'     => $isDir,
                'isLink'    => $isLink,
            ];
        }
        closedir($handle);
        return $rows;
    }

    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
        $val = $bytes / 1024;
        $i = 0;
        while ($val >= 1024 && $i < count($units) - 1) {
            $val /= 1024;
            $i++;
        }
        return number_format($val, $val < 10 ? 2 : ($val < 100 ? 1 : 0)) . ' ' . $units[$i];
    }

    /**
     * Sort rows in place. $by ∈ name|size|modified|kind.
     */
    public static function sort(array &$rows, string $by, bool $asc, bool $foldersFirst = true): void
    {
        usort($rows, function (array $a, array $b) use ($by, $asc, $foldersFirst): int {
            if ($foldersFirst && $a['isDir'] !== $b['isDir']) {
                return $a['isDir'] ? -1 : 1;
            }
            $cmp = match ($by) {
                'size'     => $a['sizeBytes'] <=> $b['sizeBytes'],
                'modified' => $a['mtime'] <=> $b['mtime'],
                'kind'     => strcasecmp($a['kind'], $b['kind']),
                default    => strnatcasecmp($a['name'], $b['name']),
            };
            return $asc ? $cmp : -$cmp;
        });
    }

    public static function diskFree(string $path): string
    {
        $free = @disk_free_space($path) ?: 0;
        $total = @disk_total_space($path) ?: 0;
        if ($total === 0.0 || $total === 0) return '';
        return sprintf('%s free of %s',
            self::formatSize((int) $free),
            self::formatSize((int) $total)
        );
    }

    /** Resolve to a canonical absolute path. Falls back to manual normalization if file doesn't exist. */
    public static function canonical(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) return $real;

        // Manual normalization for paths that don't exist yet.
        if ($path === '' || $path[0] !== '/') {
            $path = (getcwd() ?: '/') . '/' . $path;
        }
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') array_pop($parts);
            else $parts[] = $seg;
        }
        return '/' . implode('/', $parts);
    }
}
