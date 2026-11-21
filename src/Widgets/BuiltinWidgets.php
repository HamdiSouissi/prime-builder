<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use PrimeBuilder\Document\DocumentValidator;
use PrimeBuilder\Dynamic\DynamicTags;

final class BuiltinWidgets
{
    public static function registerAll(WidgetRegistry $registry): void
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $u = static fn (mixed $v): string => htmlspecialchars(DocumentValidator::sanitizeUrl((string) $v), ENT_QUOTES, 'UTF-8');

        // 1. Base widgets
        $registry->register(new WidgetDefinition(
            type: 'heading',
            title: 'Titre',
            category: 'basic',
            icon: 'type',
            defaults: ['text' => 'Titre de section', 'tag' => 'h2'],
            renderer: static function (array $s, array $ctx) use ($e): string {
                $tag = in_array($s['tag'] ?? 'h2', ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span'], true) ? (string) $s['tag'] : 'h2';
                $text = DynamicTags::resolve((string) ($s['text'] ?? 'Titre'), $ctx);

                return "<{$tag} class=\"pb-heading\">{$text}</{$tag}>";
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'text-editor',
            title: 'Éditeur de texte',
            category: 'basic',
            icon: 'align-left',
            defaults: ['text' => '<p>Votre texte ici.</p>'],
            renderer: static fn (array $s, array $ctx): string => '<div class="pb-text-editor">' . DynamicTags::resolve((string) ($s['text'] ?? ''), $ctx) . '</div>',
        ));

        $registry->register(new WidgetDefinition(
            type: 'image',
            title: 'Image',
            category: 'basic',
            icon: 'image',
            defaults: ['url' => '', 'alt' => '', 'caption' => ''],
            renderer: static function (array $s) use ($e, $u): string {
                $src = $u($s['url'] ?? '');
                if ($src === '') {
                    return '<div class="pb-image pb-image--empty"></div>';
                }
                $alt = $e($s['alt'] ?? '');
                $w = max(0, (int) ($s['width_attr'] ?? 800));
                $h = max(0, (int) ($s['height_attr'] ?? 450));
                $caption = trim((string) ($s['caption'] ?? ''));
                $img = "<img src=\"{$src}\" alt=\"{$alt}\" loading=\"lazy\" width=\"{$w}\" height=\"{$h}\" class=\"pb-image__img\">";

                return $caption !== ''
                    ? "<figure class=\"pb-image\">{$img}<figcaption class=\"pb-image__caption\">" . $e($caption) . '</figcaption></figure>'
                    : "<figure class=\"pb-image\">{$img}</figure>";
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'video',
            title: 'Vidéo',
            category: 'media',
            icon: 'video',
            scripts: ['video-consent.js'],
            defaults: ['url' => '', 'provider' => 'youtube'],
            renderer: static function (array $s) use ($e, $u): string {
                $url = (string) ($s['url'] ?? '');
                if ($url === '') {
                    return '<div class="pb-video pb-video--empty"></div>';
                }
                $safe = $u($url);

                return "<div class=\"pb-video\" data-pb-video=\"{$safe}\"><button type=\"button\" class=\"pb-video__consent\" data-pb-video-play>Lire la vidéo</button></div>";
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'button',
            title: 'Bouton',
            category: 'basic',
            icon: 'mouse-pointer-click',
            defaults: ['text' => 'En savoir plus', 'url' => '#'],
            renderer: static function (array $s, array $ctx) use ($e, $u): string {
                $text = DynamicTags::resolve((string) ($s['text'] ?? 'Bouton'), $ctx);
                $href = $u($s['url'] ?? '#');
                $target = !empty($s['new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '';

                return "<div class=\"pb-button-wrap\"><a href=\"{$href}\"{$target} class=\"pb-button\">{$text}</a></div>";
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'divider',
            title: 'Séparateur',
            category: 'basic',
            icon: 'minus',
            renderer: static fn (): string => '<hr class="pb-divider">',
        ));

        $registry->register(new WidgetDefinition(
            type: 'spacer',
            title: 'Espaceur',
            category: 'basic',
            icon: 'move-vertical',
            defaults: ['height' => '40px'],
            renderer: static function (array $s) use ($e): string {
                return '<div class="pb-spacer" aria-hidden="true" style="height:' . $e($s['height'] ?? '40px') . '"></div>';
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'icon',
            title: 'Icône',
            category: 'basic',
            icon: 'star',
            defaults: ['name' => 'star', 'label' => ''],
            renderer: static function (array $s) use ($e): string {
                return '<span class="pb-icon" aria-label="' . $e($s['label'] ?? '') . '" data-pb-icon="' . $e($s['name'] ?? 'star') . '">★</span>';
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'icon-box',
            title: 'Boîte icône',
            category: 'basic',
            icon: 'box',
            defaults: ['title' => 'Titre', 'description' => 'Description courte.'],
            renderer: static function (array $s) use ($e): string {
                return '<div class="pb-icon-box"><div class="pb-icon-box__icon" aria-hidden="true">★</div><h3 class="pb-icon-box__title">' . $e($s['title'] ?? '') . '</h3><p class="pb-icon-box__desc">' . $e($s['description'] ?? '') . '</p></div>';
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'image-box',
            title: 'Boîte image',
            category: 'basic',
            icon: 'layout-template',
            defaults: ['url' => '', 'title' => 'Titre', 'description' => 'Description.'],
            renderer: static function (array $s) use ($e, $u): string {
                return '<div class="pb-image-box">' . (($u($s['url'] ?? '') !== '') ? '<img src="' . $u($s['url'] ?? '') . '" alt="' . $e($s['alt'] ?? '') . '" loading="lazy" class="pb-image-box__img">' : '') . '<h3 class="pb-image-box__title">' . $e($s['title'] ?? '') . '</h3><p class="pb-image-box__desc">' . $e($s['description'] ?? '') . '</p></div>';
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'icon-list',
            title: 'Liste d’icônes',
            category: 'basic',
            icon: 'list',
            defaults: ['items' => [['text' => 'Élément 1'], ['text' => 'Élément 2']]],
            renderer: static function (array $s) use ($e): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                $li = '';
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $li .= '<li class="pb-icon-list__item"><span aria-hidden="true">✓</span> ' . $e($item['text'] ?? '') . '</li>';
                    }
                }

                return "<ul class=\"pb-icon-list\">{$li}</ul>";
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'counter',
            title: 'Compteur',
            category: 'basic',
            icon: 'hash',
            defaults: ['number' => '100', 'title' => 'Projets'],
            renderer: static function (array $s) use ($e): string {
                return '<div class="pb-counter"><span class="pb-counter__value">' . $e($s['number'] ?? '0') . '</span><span class="pb-counter__title">' . $e($s['title'] ?? '') . '</span></div>';
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'progress',
            title: 'Barre de progression',
            category: 'basic',
            icon: 'sliders',
            defaults: ['title' => 'Progression', 'percent' => 75],
            renderer: static function (array $s) use ($e): string {
                $pct = max(0, min(100, (int) ($s['percent'] ?? 50)));

                return '<div class="pb-progress"><div class="pb-progress__label">' . $e($s['title'] ?? '') . ' <span>' . $pct . '%</span></div><div class="pb-progress__track" role="progressbar" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100"><div class="pb-progress__bar" style="width:' . $pct . '%"></div></div></div>';
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'testimonial',
            title: 'Témoignage',
            category: 'basic',
            icon: 'message-square-quote',
            defaults: ['quote' => 'Un outil remarquable.', 'author' => 'Camille', 'role' => 'Design'],
            renderer: static function (array $s) use ($e): string {
                return '<figure class="pb-testimonial"><blockquote class="pb-testimonial__quote">' . $e($s['quote'] ?? '') . '</blockquote><figcaption class="pb-testimonial__author"><strong>' . $e($s['author'] ?? '') . '</strong> <span>' . $e($s['role'] ?? '') . '</span></figcaption></figure>';
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'tabs',
            title: 'Onglets',
            category: 'basic',
            icon: 'folder-kanban',
            scripts: ['tabs.js'],
            defaults: ['items' => [['title' => 'Onglet 1', 'content' => 'Contenu 1'], ['title' => 'Onglet 2', 'content' => 'Contenu 2']]],
            renderer: static function (array $s) use ($e): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                $tabs = '';
                $panels = '';
                foreach ($items as $i => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $sel = $i === 0 ? 'true' : 'false';
                    $hid = $i === 0 ? '' : ' hidden';
                    $tabs .= "<button type=\"button\" role=\"tab\" aria-selected=\"{$sel}\" class=\"pb-tabs__tab\" data-pb-tab=\"{$i}\">" . $e($item['title'] ?? '') . '</button>';
                    $panels .= "<div role=\"tabpanel\" class=\"pb-tabs__panel\" data-pb-panel=\"{$i}\"{$hid}>" . $e($item['content'] ?? '') . '</div>';
                }

                return "<div class=\"pb-tabs\" data-pb-tabs><div role=\"tablist\" class=\"pb-tabs__list\">{$tabs}</div>{$panels}</div>";
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'accordion',
            title: 'Accordéon',
            category: 'basic',
            icon: 'chevrons-up-down',
            scripts: ['accordion.js'],
            defaults: ['items' => [['title' => 'Question 1', 'content' => 'Réponse 1']]],
            renderer: static function (array $s) use ($e): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                $html = '';
                foreach ($items as $i => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $exp = $i === 0 ? 'true' : 'false';
                    $hid = $i === 0 ? '' : ' hidden';
                    $html .= '<div class="pb-accordion__item"><button type="button" class="pb-accordion__trigger" aria-expanded="' . $exp . '" data-pb-acc-trigger>' . $e($item['title'] ?? '') . '</button><div class="pb-accordion__panel"' . $hid . '>' . $e($item['content'] ?? '') . '</div></div>';
                }

                return "<div class=\"pb-accordion\" data-pb-accordion>{$html}</div>";
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'toggle',
            title: 'Bascule',
            category: 'basic',
            icon: 'toggle-left',
            scripts: ['accordion.js'],
            defaults: ['title' => 'Afficher plus', 'content' => 'Détails'],
            renderer: static function (array $s) use ($e): string {
                return '<div class="pb-toggle" data-pb-accordion><button type="button" class="pb-accordion__trigger" aria-expanded="false" data-pb-acc-trigger>' . $e($s['title'] ?? '') . '</button><div class="pb-accordion__panel" hidden>' . $e($s['content'] ?? '') . '</div></div>';
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'alert',
            title: 'Alerte',
            category: 'basic',
            icon: 'alert-circle',
            defaults: ['variant' => 'info', 'title' => 'Information', 'text' => 'Message important.'],
            renderer: static function (array $s) use ($e): string {
                $v = in_array($s['variant'] ?? 'info', ['info', 'success', 'warning', 'danger'], true) ? (string) $s['variant'] : 'info';

                return "<div class=\"pb-alert pb-alert--{$v}\" role=\"status\"><strong>" . $e($s['title'] ?? '') . '</strong><p>' . $e($s['text'] ?? '') . '</p></div>';
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'social-icons',
            title: 'Réseaux sociaux',
            category: 'basic',
            icon: 'share-2',
            defaults: ['items' => [['network' => 'GitHub', 'url' => 'https://github.com']]],
            renderer: static function (array $s) use ($e, $u): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                $links = '';
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $links .= '<a href="' . $u($item['url'] ?? '#') . '" target="_blank" rel="noopener noreferrer" class="pb-social__link">' . $e($item['network'] ?? '') . '</a>';
                    }
                }

                return "<nav class=\"pb-social\" aria-label=\"Réseaux sociaux\">{$links}</nav>";
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'map',
            title: 'Carte (OpenStreetMap)',
            category: 'basic',
            icon: 'map-pin',
            defaults: ['lat' => 48.8566, 'lon' => 2.3522, 'zoom' => 13],
            renderer: static function (array $s): string {
                $lat = (float) ($s['lat'] ?? 48.8566);
                $lon = (float) ($s['lon'] ?? 2.3522);
                $bbox = sprintf('%f,%f,%f,%f', $lon - 0.02, $lat - 0.02, $lon + 0.02, $lat + 0.02);
                $src = 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode($bbox) . '&layer=mapnik';

                return '<div class="pb-map"><iframe title="Carte OpenStreetMap" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" loading="lazy" class="pb-map__frame"></iframe></div>';
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'star-rating',
            title: 'Note par étoiles',
            category: 'basic',
            icon: 'star',
            defaults: ['rating' => 5, 'max' => 5],
            renderer: static function (array $s): string {
                $max = max(1, min(10, (int) ($s['max'] ?? 5)));
                $rating = max(0, min($max, (float) ($s['rating'] ?? 5)));
                $stars = str_repeat('★', (int) round($rating)) . str_repeat('☆', $max - (int) round($rating));

                return "<div class=\"pb-stars\" role=\"img\" aria-label=\"{$rating} sur {$max}\">{$stars}</div>";
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'image-gallery',
            title: 'Galerie d’images',
            category: 'media',
            icon: 'grid',
            scripts: ['lightbox.js'],
            defaults: ['items' => []],
            renderer: static function (array $s) use ($e, $u): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                $html = '';
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $src = $u($item['url'] ?? '');
                        if ($src !== '') {
                            $html .= "<a href=\"{$src}\" class=\"pb-gallery__item\" data-pb-lightbox><img src=\"{$src}\" alt=\"" . $e($item['alt'] ?? '') . '" loading="lazy"></a>';
                        }
                    }
                }

                return "<div class=\"pb-gallery\" data-pb-gallery>{$html}</div>";
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'image-carousel',
            title: 'Carrousel d’images',
            category: 'media',
            icon: 'gallery-horizontal',
            scripts: ['carousel.js'],
            defaults: ['items' => []],
            renderer: static function (array $s) use ($e, $u): string {
                $items = is_array($s['items'] ?? null) ? $s['items'] : [];
                $slides = '';
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $src = $u($item['url'] ?? '');
                        if ($src !== '') {
                            $slides .= "<div class=\"pb-carousel__slide\"><img src=\"{$src}\" alt=\"" . $e($item['alt'] ?? '') . '" loading="lazy"></div>';
                        }
                    }
                }

                return "<div class=\"pb-carousel\" data-pb-carousel role=\"region\" aria-roledescription=\"carousel\"><div class=\"pb-carousel__track\">{$slides}</div><button type=\"button\" class=\"pb-carousel__prev\" data-pb-prev aria-label=\"Précédent\">‹</button><button type=\"button\" class=\"pb-carousel__next\" data-pb-next aria-label=\"Suivant\">›</button></div>";
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'menu-anchor',
            title: 'Ancre de menu',
            category: 'basic',
            icon: 'anchor',
            defaults: ['anchor' => 'section'],
            renderer: static function (array $s) use ($e): string {
                $anchor = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($s['anchor'] ?? 'section')) ?? 'section';

                return '<div id="' . $e($anchor) . '" class="pb-anchor"></div>';
            },
        ));

        $registry->register(new WidgetDefinition(
            type: 'html',
            title: 'HTML personnalisé',
            category: 'basic',
            icon: 'code',
            defaults: ['html' => ''],
            renderer: static fn (array $s): string => '<div class="pb-html">' . (string) ($s['html'] ?? '') . '</div>',
        ));

        $registry->register(new WidgetDefinition(
            type: 'theme-zone',
            title: 'Zone du thème',
            category: 'site',
            icon: 'layout',
            defaults: ['zone' => 'sidebar'],
            renderer: static function (array $s) use ($e): string {
                return '<div class="pb-theme-zone" data-pb-zone="' . $e($s['zone'] ?? 'sidebar') . '"></div>';
            },
        ));

        // 2. Pro & Dynamic widgets
        $proSimple = [
            ['posts', 'Grille d’articles', 'pro', 'newspaper', []],
            ['portfolio', 'Portfolio filtrable', 'pro', 'briefcase', ['portfolio.js']],
            ['slides', 'Diaporama', 'pro', 'presentation', ['carousel.js']],
            ['media-carousel', 'Carrousel de médias', 'pro', 'film', ['carousel.js']],
            ['testimonial-carousel', 'Carrousel de témoignages', 'pro', 'messages-square', ['carousel.js']],
            ['reviews', 'Avis clients', 'pro', 'award', []],
            ['form', 'Formulaire', 'forms', 'mail', ['form.js']],
            ['nav-menu', 'Menu de navigation', 'site', 'menu', ['nav-menu.js']],
            ['cta', 'Appel à l’action', 'pro', 'megaphone', []],
            ['flip-box', 'Boîte retournable', 'pro', 'refresh-cw', []],
            ['price-table', 'Table de prix', 'pro', 'credit-card', []],
            ['price-list', 'Liste de prix', 'pro', 'list-ordered', []],
            ['countdown', 'Compte à rebours', 'pro', 'timer', ['countdown.js']],
            ['animated-headline', 'Titre animé', 'pro', 'sparkles', ['animated-headline.js']],
            ['blockquote', 'Citation', 'pro', 'quote', []],
            ['share-buttons', 'Boutons de partage', 'pro', 'share', []],
            ['toc', 'Sommaire automatique', 'pro', 'list-tree', ['toc.js']],
            ['code-highlight', 'Code coloré', 'pro', 'terminal', []],
            ['breadcrumbs', 'Fil d’Ariane', 'site', 'chevrons-right', []],
            ['search-form', 'Formulaire de recherche', 'site', 'search', []],
            ['site-logo', 'Logo du site', 'site', 'image', []],
            ['site-title', 'Titre du site', 'site', 'home', []],
            ['post-title', 'Titre du contenu', 'dynamic', 'heading', []],
            ['post-excerpt', 'Extrait du contenu', 'dynamic', 'file-text', []],
            ['post-content', 'Contenu principal', 'dynamic', 'align-justify', []],
            ['post-featured-image', 'Image à la une', 'dynamic', 'image', []],
            ['post-info', 'Informations du contenu', 'dynamic', 'info', []],
            ['post-navigation', 'Navigation précédent / suivant', 'dynamic', 'arrow-left-right', []],
            ['author-box', 'Boîte auteur', 'dynamic', 'user', []],
            ['archive-title', 'Titre d’archive', 'dynamic', 'folder', []],
            ['archive-posts', 'Liste d’archive', 'dynamic', 'layers', []],
            ['global-widget', 'Widget global', 'pro', 'globe', []],
            ['prime-block', 'Bloc Prime Editor', 'basic', 'blocks', []],
        ];

        foreach ($proSimple as [$type, $title, $cat, $icon, $scripts]) {
            $registry->register(new WidgetDefinition(
                type: $type,
                title: $title,
                category: $cat,
                icon: $icon,
                scripts: $scripts,
                isPro: $cat === 'pro',
                renderer: static function (array $s, array $ctx) use ($type, $e, $u): string {
                    return match ($type) {
                        'form' => self::renderForm($s, $e),
                        'cta' => '<div class="pb-cta"><h3 class="pb-cta__title">' . $e($s['title'] ?? 'Passez à l’action') . '</h3><p class="pb-cta__desc">' . $e($s['description'] ?? '') . '</p><a href="' . $u($s['url'] ?? '#') . '" class="pb-button">' . $e($s['button_text'] ?? 'Découvrir') . '</a></div>',
                        'price-table' => '<div class="pb-price-table"><h3 class="pb-price-table__title">' . $e($s['title'] ?? 'Offre') . '</h3><div class="pb-price-table__price">' . $e($s['price'] ?? '29 €') . '</div><a href="' . $u($s['url'] ?? '#') . '" class="pb-button">' . $e($s['button_text'] ?? 'Choisir') . '</a></div>',
                        'countdown' => '<div class="pb-countdown" data-pb-countdown="' . $e($s['target_date'] ?? '') . '"><span class="pb-countdown__val">--j --h --m --s</span></div>',
                        'animated-headline' => '<h2 class="pb-animated-headline" data-pb-animated-headline>' . $e($s['before'] ?? '') . ' <span class="pb-animated-headline__word">' . $e($s['highlight'] ?? 'rapide') . '</span></h2>',
                        'toc' => '<nav class="pb-toc" data-pb-toc aria-label="Sommaire"></nav>',
                        'code-highlight' => '<pre class="pb-code"><code>' . $e($s['code'] ?? '') . '</code></pre>',
                        'site-title' => '<div class="pb-site-title">' . $e($ctx['site_title'] ?? 'Prime CMS') . '</div>',
                        'post-title' => '<h1 class="pb-post-title">' . $e($ctx['entry_title'] ?? '') . '</h1>',
                        'post-excerpt' => '<p class="pb-post-excerpt">' . $e($ctx['entry_excerpt'] ?? '') . '</p>',
                        'search-form' => '<form class="pb-search-form" action="/search" method="get" role="search"><label class="sr-only" for="pb-q">Rechercher</label><input id="pb-q" type="search" name="q" placeholder="Rechercher…"><button type="submit" class="pb-button">OK</button></form>',
                        default => '<div class="pb-widget pb-widget--' . $e($type) . '" data-pb-widget="' . $e($type) . '">' . $e($s['title'] ?? $s['text'] ?? '') . '</div>',
                    };
                },
            ));
        }
    }

    /**
     * @param array<string, mixed> $s
     * @param callable(mixed): string $e
     */
    private static function renderForm(array $s, callable $e): string
    {
        $formId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($s['form_id'] ?? 'contact')) ?? 'contact';
        $fields = is_array($s['fields'] ?? null) ? $s['fields'] : [
            ['name' => 'name', 'label' => 'Nom', 'type' => 'text', 'required' => true],
            ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true],
            ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
        ];
        $inputs = '';
        foreach ($fields as $f) {
            if (!is_array($f)) {
                continue;
            }
            $name = $e($f['name'] ?? 'field');
            $label = $e($f['label'] ?? $name);
            $type = $e($f['type'] ?? 'text');
            $req = !empty($f['required']) ? ' required' : '';
            if ($type === 'textarea') {
                $inputs .= "<div class=\"pb-form__field\"><label>{$label}</label><textarea name=\"{$name}\"{$req}></textarea></div>";
            } else {
                $inputs .= "<div class=\"pb-form__field\"><label>{$label}</label><input type=\"{$type}\" name=\"{$name}\"{$req}></div>";
            }
        }
        $ts = time();

        return "<form class=\"pb-form\" data-pb-form=\"{$e($formId)}\" action=\"/api/prime-builder/v1/forms/{$e($formId)}/submit\" method=\"post\">"
            . $inputs
            . '<div class="pb-form__hp" aria-hidden="true"><input type="text" name="_pb_hp" tabindex="-1" autocomplete="off"></div>'
            . "<input type=\"hidden\" name=\"_pb_ts\" value=\"{$ts}\">"
            . '<button type="submit" class="pb-button pb-form__submit">' . $e($s['submit_label'] ?? 'Envoyer') . '</button>'
            . '<div class="pb-form__status" role="status" aria-live="polite"></div>'
            . '</form>';
    }
}
