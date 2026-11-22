<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Content\EntryQuery;
use Prime\Hooks\Hooks;
use Prime\Kernel\Application;
use Prime\Media\MediaRepository;
use Prime\Settings\Settings;
use Prime\Site\Menu\MenuRenderer;
use Prime\Site\PageContext;
use Prime\Site\Seo\SeoManager;
use Prime\Site\TemplateResolver;
use Prime\Site\UrlGenerator;
use Prime\Site\View\EntryViewFactory;
use Prime\Site\View\SiteView;
use Prime\Site\Zone\DynamicBlockRegistry;
use Prime\Site\Zone\ZoneRepository;
use Prime\Themes\ThemeAssetPublisher;
use Prime\Themes\ThemeAssetRegistry;
use Prime\Themes\ThemeManager;
use Prime\Themes\ThemeOptions;

/** Reuse the same site identity resolution as the CMS theme, once per request. */
final class SiteIdentity
{
    private ?SiteView $site = null;

    private ?string $logo = null;

    public function __construct(private readonly Application $app)
    {
    }

    public function invalidate(): void
    {
        $this->site = null;
        $this->logo = null;
    }

    /** @param array<string, mixed> $settings */
    public function title(array $settings): string
    {
        return self::titleMarkup($settings, $this->site()->rawName());
    }

    /** @param array<string, mixed> $settings */
    public function logo(array $settings): string
    {
        $site = $this->site();
        if ($this->logo === null) {
            // SiteView checks that the selected media is an image and delegates
            // escaping, variants and intrinsic dimensions to MediaHelper.
            $this->logo = $site->logo(['class' => 'pb-site-logo__image', 'loading' => 'lazy', 'use_focal' => false]);
        }
        return self::logoMarkup($settings, $site->rawName(), $this->logo);
    }

    /** @param array<string, mixed> $settings */
    public static function titleMarkup(array $settings, string $name): string
    {
        $tag = is_string($settings['tag'] ?? null) && in_array($settings['tag'], ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $settings['tag'] : 'p';
        $text = Renderer::escape($name);
        if (($settings['link_home'] ?? 'show') !== 'hide') {
            $text = '<a href="/">' . $text . '</a>';
        }
        return '<' . $tag . ' class="pb-site-title__text">' . $text . '</' . $tag . '>';
    }

    /**
     * The logo markup must be the safe HTML returned by SiteView::logo(), not user input.
     *
     * @param array<string, mixed> $settings
     */
    public static function logoMarkup(array $settings, string $name, string $logo): string
    {
        $content = $logo !== '' ? $logo : '<span class="pb-site-logo__fallback">' . Renderer::escape($name) . '</span>';
        if (($settings['link_home'] ?? 'show') !== 'hide') {
            return '<a class="pb-site-logo__link" href="/">' . $content . '</a>';
        }
        return '<span class="pb-site-logo__unlinked">' . $content . '</span>';
    }

    private function site(): SiteView
    {
        if ($this->site !== null) {
            return $this->site;
        }
        // SiteRenderer constructs SiteView with these same public services. Its
        // identity methods are independent of the current page context.
        $themes = $this->app->get(ThemeManager::class);
        $active = $themes->active();
        $parent = $themes->activeParent();
        $directories = [$active->path];
        if ($parent !== null) {
            $directories[] = $parent->path;
        }
        $this->site = new SiteView(
            context: new PageContext(type: PageContext::TYPE_FRONT),
            activeTheme: $active,
            parentTheme: $parent,
            themeBaseDirs: $directories,
            themeOptions: $this->app->get(ThemeOptions::class),
            assets: $this->app->get(ThemeAssetRegistry::class),
            publisher: $this->app->get(ThemeAssetPublisher::class),
            templates: $this->app->get(TemplateResolver::class),
            urls: $this->app->get(UrlGenerator::class),
            menuRenderer: $this->app->get(MenuRenderer::class),
            zones: $this->app->get(ZoneRepository::class),
            dynamicBlocks: $this->app->get(DynamicBlockRegistry::class),
            seo: $this->app->get(SeoManager::class),
            entryFactory: $this->app->get(EntryViewFactory::class),
            entryQuery: $this->app->get(EntryQuery::class),
            settings: $this->app->get(Settings::class),
            media: $this->app->get(MediaRepository::class),
            hooks: $this->app->get(Hooks::class),
        );
        return $this->site;
    }
}
