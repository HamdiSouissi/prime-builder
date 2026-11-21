<?php

declare(strict_types=1);

namespace PrimeBuilder\Controller;

use Prime\Content\EntryRepository;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Plugins\PluginContext;
use Prime\Security\Csrf;
use PrimeBuilder\Widgets\WidgetRegistry;

final class EditorController
{
    public function __construct(
        private readonly PluginContext $context,
        private readonly WidgetRegistry $widgets,
    ) {
    }

    public function edit(Request $request): Response
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return new Response(t('plugin::prime-builder.errors.not_found'), 404);
        }

        $entries = $this->context->app()->get(EntryRepository::class);
        $entry = $entries->find($id);
        if ($entry === null) {
            return new Response(t('plugin::prime-builder.errors.not_found'), 404);
        }

        $contentJson = (string) ($entry->content ?? '{"version":1,"blocks":[]}');
        $decoded = json_decode($contentJson, true);
        $pbDoc = ['version' => 1, 'elements' => []];
        if (is_array($decoded) && is_array($decoded['blocks'] ?? null)) {
            foreach ($decoded['blocks'] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'prime-builder/layout' && is_array($block['data'] ?? null)) {
                    $pbDoc = $block['data'];
                    break;
                }
            }
        }

        $csrf = $this->context->app()->get(Csrf::class);
        $previewUrl = '/' . ltrim((string) ($entry->slug ?? ''), '/');

        $html = $this->context->views()->render('prime-builder::editor/workspace', [
            'entry' => $entry,
            'document' => $pbDoc,
            'widgets' => $this->widgets->metaList(),
            'csrfToken' => method_exists($csrf, 'token') ? $csrf->token() : '',
            'previewUrl' => $previewUrl,
            'pluginUrl' => $this->context->url(''),
        ]);

        return new Response($html);
    }
}
