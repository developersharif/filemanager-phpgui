<?php

declare(strict_types=1);

namespace FileManager;

/**
 * Browser-style back/forward navigation stack.
 * `current()` is the active entry; `back()`/`forward()` move the cursor.
 * `push()` truncates any forward history (just like a real browser).
 */
final class History
{
    /** @var list<string> */
    private array $stack = [];
    private int $cursor = -1;

    public function push(string $path): void
    {
        // Drop forward history.
        if ($this->cursor < count($this->stack) - 1) {
            $this->stack = array_slice($this->stack, 0, $this->cursor + 1);
        }
        // Avoid duplicate consecutive entries.
        if ($this->stack !== [] && end($this->stack) === $path) {
            return;
        }
        $this->stack[] = $path;
        $this->cursor = count($this->stack) - 1;
    }

    public function current(): ?string
    {
        return $this->cursor >= 0 ? $this->stack[$this->cursor] : null;
    }

    public function canBack(): bool { return $this->cursor > 0; }
    public function canForward(): bool { return $this->cursor < count($this->stack) - 1; }

    public function back(): ?string
    {
        if (!$this->canBack()) return null;
        $this->cursor--;
        return $this->stack[$this->cursor];
    }

    public function forward(): ?string
    {
        if (!$this->canForward()) return null;
        $this->cursor++;
        return $this->stack[$this->cursor];
    }
}
