<?php

declare(strict_types=1);

namespace PrimeBuilder\Controller;

use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Plugins\PluginContext;
use PrimeBuilder\Style\CssCompiler;

final class PublicController
{
    public function __construct(
        private readonly PluginContext $context,
    ) {
    }

    public function css(Request $request): Response
    {
        $file = (string) $request->routeParam('file', '');
        $compiler = new CssCompiler(
            (int) $this->context->settings()->get('breakpoint_tablet', 1024),
            (int) $this->context->settings()->get('breakpoint_mobile', 767),
        );

        if (str_starts_with($file, 'kit-')) {
            $kit = json_decode((string) $this->context->settings()->get('kit', '{}'), true);
            $css = $compiler->compileKit(is_array($kit) ? $kit : []);

            return new Response($css, 200, [
                'Content-Type' => 'text/css; charset=utf-8',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        }

        return new Response('/* Prime Builder */', 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
