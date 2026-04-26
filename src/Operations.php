<?php

declare(strict_types=1);

namespace FileManager;

/**
 * Filesystem mutation primitives. All methods return a result string —
 * empty on success, or a human-readable error message that callers can show
 * to the user via TopLevel::messageBox.
 */
final class Operations
{
    public static function mkdir(string $parentDir, string $name): string
    {
        $target = rtrim($parentDir, '/') . '/' . $name;
        if (file_exists($target)) return "A file or folder named \"{$name}\" already exists.";
        if (!@mkdir($target, 0755)) return "Could not create folder \"{$name}\".";
        return '';
    }

    public static function touch(string $parentDir, string $name): string
    {
        $target = rtrim($parentDir, '/') . '/' . $name;
        if (file_exists($target)) return "A file or folder named \"{$name}\" already exists.";
        if (!@touch($target)) return "Could not create file \"{$name}\".";
        return '';
    }

    public static function rename(string $oldPath, string $newName): string
    {
        $newPath = dirname($oldPath) . '/' . $newName;
        if ($newPath === $oldPath) return '';
        if (file_exists($newPath)) return "A file or folder named \"{$newName}\" already exists.";
        if (!@rename($oldPath, $newPath)) return "Could not rename \"" . basename($oldPath) . "\".";
        return '';
    }

    /** @param list<string> $paths */
    public static function delete(array $paths): string
    {
        foreach ($paths as $p) {
            $err = self::deleteOne($p);
            if ($err !== '') return $err;
        }
        return '';
    }

    private static function deleteOne(string $path): string
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path) ? '' : "Could not delete \"" . basename($path) . "\".";
        }
        if (is_dir($path)) {
            $items = @scandir($path);
            if ($items === false) return "Could not read \"" . basename($path) . "\".";
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $err = self::deleteOne($path . '/' . $item);
                if ($err !== '') return $err;
            }
            return @rmdir($path) ? '' : "Could not delete folder \"" . basename($path) . "\".";
        }
        return ''; // Nothing to do — silently ignore missing entries.
    }

    /**
     * Copy or move sources into $destDir. If $move is true, sources are deleted
     * after a successful copy. Auto-renames on collision ("name (copy).ext").
     *
     * @param list<string> $paths
     */
    public static function paste(array $paths, string $destDir, bool $move): string
    {
        foreach ($paths as $src) {
            if (!file_exists($src)) continue;

            // Refuse to move/copy a directory into itself or a descendant.
            if (is_dir($src) && (
                $destDir === $src ||
                str_starts_with(rtrim($destDir, '/') . '/', rtrim($src, '/') . '/')
            )) {
                return 'Cannot move or copy a folder into itself.';
            }

            $dest = self::resolveCollision($destDir, basename($src));

            if ($move && dirname($src) !== $destDir) {
                if (!@rename($src, $dest)) {
                    // rename() fails across filesystems — fall back to copy + delete.
                    $err = self::copyRecursive($src, $dest);
                    if ($err !== '') return $err;
                    $err = self::deleteOne($src);
                    if ($err !== '') return $err;
                }
            } elseif (!$move) {
                $err = self::copyRecursive($src, $dest);
                if ($err !== '') return $err;
            }
        }
        return '';
    }

    private static function resolveCollision(string $destDir, string $name): string
    {
        $base = rtrim($destDir, '/') . '/' . $name;
        if (!file_exists($base)) return $base;

        $info = pathinfo($name);
        $stem = $info['filename'] ?? $name;
        $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';

        for ($i = 1; $i < 1000; $i++) {
            $candidate = sprintf('%s/%s (copy %d)%s', rtrim($destDir, '/'), $stem, $i, $ext);
            if (!file_exists($candidate)) return $candidate;
        }
        return $base; // Give up — caller's copy/rename will fail and surface an error.
    }

    private static function copyRecursive(string $src, string $dest): string
    {
        if (is_link($src)) {
            $target = readlink($src);
            return @symlink($target, $dest) ? '' : "Could not copy link \"" . basename($src) . "\".";
        }
        if (is_file($src)) {
            return @copy($src, $dest) ? '' : "Could not copy \"" . basename($src) . "\".";
        }
        if (is_dir($src)) {
            if (!@mkdir($dest, 0755)) return "Could not create \"" . basename($dest) . "\".";
            $items = @scandir($src);
            if ($items === false) return "Could not read \"" . basename($src) . "\".";
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $err = self::copyRecursive($src . '/' . $item, $dest . '/' . $item);
                if ($err !== '') return $err;
            }
            return '';
        }
        return '';
    }
}
