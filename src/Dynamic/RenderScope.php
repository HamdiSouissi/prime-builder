<?php

declare(strict_types=1);

namespace PrimeBuilder\Dynamic;

use Prime\Http\Request;
use Prime\Site\PageContext;

/** Explicitly scope a zone rendered from a Builder theme widget, never a zone save. */
final class RenderScope
{
    private ?PageContext $page = null;
    private ?Request $request = null;

    public function page(): ?PageContext
    {
        return $this->page;
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    /** @param callable(): string $render */
    public function within(PageContext $page, callable $render, ?Request $request = null): string
    {
        $previous = $this->page;
        $previousRequest = $this->request;
        $this->page = $page;
        $this->request = $request;
        try {
            return $render();
        } finally {
            $this->page = $previous;
            $this->request = $previousRequest;
        }
    }
}
