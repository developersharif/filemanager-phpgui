<?php

declare(strict_types=1);

namespace FileManager;

/**
 * Maps file extensions / kinds to a unicode glyph + a human-readable kind label.
 * The php-gui Treeview can't render external icons inline (Image is a separate
 * widget), so we lean on emoji/unicode glyphs that render inside the row text.
 */
final class Icons
{
    private const EXT_MAP = [
        'pdf'  => ['📕', 'PDF document'],
        'doc'  => ['📄', 'Word document'],
        'docx' => ['📄', 'Word document'],
        'odt'  => ['📄', 'OpenDocument'],
        'rtf'  => ['📄', 'Rich text'],
        'txt'  => ['📝', 'Text file'],
        'md'   => ['📝', 'Markdown'],
        'log'  => ['📝', 'Log file'],

        'xls'  => ['📊', 'Spreadsheet'],
        'xlsx' => ['📊', 'Spreadsheet'],
        'csv'  => ['📊', 'CSV file'],
        'ods'  => ['📊', 'Spreadsheet'],

        'ppt'  => ['📽', 'Presentation'],
        'pptx' => ['📽', 'Presentation'],
        'key'  => ['📽', 'Keynote'],
        'odp'  => ['📽', 'Presentation'],

        'png'  => ['🖼', 'PNG image'],
        'jpg'  => ['🖼', 'JPEG image'],
        'jpeg' => ['🖼', 'JPEG image'],
        'gif'  => ['🖼', 'GIF image'],
        'bmp'  => ['🖼', 'Bitmap image'],
        'svg'  => ['🖼', 'SVG image'],
        'webp' => ['🖼', 'WebP image'],
        'ico'  => ['🖼', 'Icon'],

        'mp3'  => ['🎵', 'Audio'],
        'wav'  => ['🎵', 'Audio'],
        'flac' => ['🎵', 'Audio'],
        'ogg'  => ['🎵', 'Audio'],
        'm4a'  => ['🎵', 'Audio'],

        'mp4'  => ['🎬', 'Video'],
        'mkv'  => ['🎬', 'Video'],
        'mov'  => ['🎬', 'Video'],
        'avi'  => ['🎬', 'Video'],
        'webm' => ['🎬', 'Video'],

        'zip'  => ['🗜', 'Zip archive'],
        'tar'  => ['🗜', 'Tar archive'],
        'gz'   => ['🗜', 'Gzip archive'],
        'bz2'  => ['🗜', 'Bzip2 archive'],
        'xz'   => ['🗜', 'Xz archive'],
        '7z'   => ['🗜', '7-Zip archive'],
        'rar'  => ['🗜', 'RAR archive'],

        'php'  => ['🐘', 'PHP source'],
        'js'   => ['📜', 'JavaScript'],
        'ts'   => ['📜', 'TypeScript'],
        'jsx'  => ['📜', 'React source'],
        'tsx'  => ['📜', 'React source'],
        'py'   => ['🐍', 'Python source'],
        'rb'   => ['💎', 'Ruby source'],
        'go'   => ['📜', 'Go source'],
        'rs'   => ['📜', 'Rust source'],
        'java' => ['☕', 'Java source'],
        'c'    => ['📜', 'C source'],
        'h'    => ['📜', 'C header'],
        'cpp'  => ['📜', 'C++ source'],
        'cs'   => ['📜', 'C# source'],
        'sh'   => ['💻', 'Shell script'],
        'bash' => ['💻', 'Bash script'],
        'zsh'  => ['💻', 'Zsh script'],
        'sql'  => ['🗃', 'SQL'],

        'html' => ['🌐', 'HTML'],
        'htm'  => ['🌐', 'HTML'],
        'css'  => ['🎨', 'CSS'],
        'json' => ['📦', 'JSON'],
        'xml'  => ['📦', 'XML'],
        'yml'  => ['📦', 'YAML'],
        'yaml' => ['📦', 'YAML'],
        'toml' => ['📦', 'TOML'],
        'ini'  => ['⚙', 'Config'],
        'conf' => ['⚙', 'Config'],
        'env'  => ['⚙', 'Env file'],

        'iso'  => ['💿', 'Disc image'],
        'img'  => ['💿', 'Disc image'],
        'deb'  => ['📦', 'Debian package'],
        'rpm'  => ['📦', 'RPM package'],
        'apk'  => ['📦', 'Android package'],
        'exe'  => ['⚙', 'Executable'],
        'dll'  => ['⚙', 'Library'],
        'so'   => ['⚙', 'Shared object'],

        'ttf'  => ['🔤', 'Font'],
        'otf'  => ['🔤', 'Font'],
        'woff' => ['🔤', 'Font'],
        'woff2'=> ['🔤', 'Font'],
    ];

    public static function forFile(string $path, bool $isDir, bool $isLink = false): array
    {
        if ($isLink) {
            return ['🔗', $isDir ? 'Folder link' : 'Link'];
        }
        if ($isDir) {
            return ['📁', 'Folder'];
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return self::EXT_MAP[$ext] ?? ['📄', $ext === '' ? 'File' : strtoupper($ext) . ' file'];
    }

    public static function placeIcon(string $name): string
    {
        return match (strtolower($name)) {
            'home'      => '🏠',
            'desktop'   => '🖥',
            'documents' => '📄',
            'downloads' => '⬇',
            'pictures'  => '🖼',
            'music'     => '🎵',
            'videos'    => '🎬',
            'trash'     => '🗑',
            'root', '/' => '💽',
            default     => '📁',
        };
    }
}
