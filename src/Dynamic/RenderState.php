<?php

declare(strict_types=1);

namespace PrimeBuilder\Dynamic;

/** Request-scoped cache dependencies, including nested HTTP dispatches. */
final class RenderState
{
    /** @var array<int, bool> */
    private array $frames = [];

    public function begin(): void
    {
        $this->frames[] = false;
    }

    public function markTimeDependent(): void
    {
        $last = count($this->frames) - 1;
        if ($last >= 0) {
            $this->frames[$last] = true;
        }
    }

    public function isTimeDependent(): bool
    {
        return $this->frames === [] ? false : $this->frames[count($this->frames) - 1];
    }

    public function end(): void
    {
        $marked = array_pop($this->frames);
        // A nested response can become part of its parent's HTML.
        if ($marked === true) {
            $this->markTimeDependent();
        }
    }
}
