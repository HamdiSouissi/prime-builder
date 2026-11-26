<?php

declare(strict_types=1);

namespace PrimeBuilder\Dynamic;

use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Content\Entry;
use Prime\Content\Fields\Field;
use Prime\Content\Fields\FieldRegistry;
use Prime\Content\TaxonomyRegistry;
use Prime\Http\Request;
use Prime\I18n\LocaleFormatter;
use Prime\Media\MediaConfig;
use Prime\Media\MediaRepository;
use Prime\Plugins\PluginContext;
use Prime\Site\PageContext;
use Prime\Site\UrlGenerator;
use PrimeBuilder\Configuration;
use PrimeBuilder\Widgets\Content;
use PrimeBuilder\Widgets\SiteIdentity;
use Throwable;

/** One resolver per render: no visitor-specific values are retained between requests. */
final class Context
{
    private ?Entry $entry = null;
    private ?User $user = null;
    /** @var array<string, string> */
    private array $values = [];

    public function __construct(
        private readonly PluginContext $plugin,
        ?PageContext $page,
        ?Entry $entry,
        ?Request $request,
        bool $trustedPreview = false,
    ) {
        $app = $plugin->app();
        $apiUser = $request?->attribute('api.user');
        $this->user = $apiUser instanceof User ? $apiUser : $app->get(Auth::class)->user($request);
        if ($this->user !== null && !$this->user->isActive()) {
            $this->user = null;
        }
        $candidate = $page !== null ? ($page->entry ?? $entry) : $entry;
        if ($candidate === null) {
            return;
        }
        // A preview is explicitly capability-checked by the calling controller.
        // Otherwise never expose drafts, private content or a page under a private ancestor.
        if ($trustedPreview || (($page === null || (!$page->isRedirect() && $page->httpStatus === 200))
            && $app->get(Content::class)->publiclyAccessibleEntry($candidate))) {
            $this->entry = $candidate;
        }
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function value(string $name): string
    {
        if (array_key_exists($name, $this->values)) {
            return $this->values[$name];
        }
        try {
            return $this->values[$name] = $this->resolve($name);
        } catch (Throwable) {
            // A missing dependency or invalid data never makes a page public by accident.
            return $this->values[$name] = '';
        }
    }

    private function resolve(string $name): string
    {
        $app = $this->plugin->app();
        if ($name === 'user.name') {
            return $this->user !== null ? $this->user->displayName : '';
        }
        if ($name === 'site.title' || $name === 'site.tagline') {
            $identity = $app->get(SiteIdentity::class);
            return $name === 'site.title' ? $identity->rawName() : $identity->rawTagline();
        }
        $formatter = $app->get(LocaleFormatter::class);
        if ($name === 'today') {
            return $formatter->date(time());
        }
        $entry = $this->entry;
        if ($entry === null) {
            return '';
        }
        if (str_starts_with($name, 'entry.field.')) {
            return $this->field(substr($name, strlen('entry.field.')), $entry);
        }
        return match ($name) {
            'entry.title' => $entry->title,
            'entry.excerpt' => $entry->excerpt ?? '',
            'entry.date' => $entry->publishedAt !== null && $entry->publishedAt !== '' ? $formatter->date($entry->publishedAt) : '',
            'entry.author' => $this->author($entry),
            'entry.featured_image' => $this->image($entry->featuredMediaId()),
            'entry.terms' => $this->terms($entry),
            'entry.url' => $app->get(UrlGenerator::class)->entryUrl($entry),
            default => '',
        };
    }

    private function author(Entry $entry): string
    {
        $author = $entry->authorId !== null ? $this->plugin->app()->get(UserRepository::class)->find($entry->authorId) : null;
        return $author !== null && $author->isActive() ? $author->displayName : '';
    }

    private function image(?int $id): string
    {
        if ($id === null || $id < 1) {
            return '';
        }
        $app = $this->plugin->app();
        $media = $app->get(MediaRepository::class)->find($id, adminDetails: false);
        return $media !== null && $media->isImage() ? $media->url($app->get(MediaConfig::class)->urlPrefix()) : '';
    }

    private function terms(Entry $entry): string
    {
        $taxonomies = $this->plugin->app()->get(TaxonomyRegistry::class);
        $names = [];
        foreach ($entry->terms as $taxonomy => $terms) {
            if ($taxonomies->get($taxonomy)?->public !== true) {
                continue;
            }
            foreach ($terms as $term) {
                $names[] = $term->name;
                if (count($names) === 20) {
                    break 2;
                }
            }
        }
        return implode(', ', $names);
    }

    private function field(string $name, Entry $entry): string
    {
        $allowed = PublicFields::parse(Configuration::get($this->plugin->settings(), 'public_fields', ''));
        if ($allowed === null || !in_array($entry->type . '.' . $name, $allowed, true)) {
            return '';
        }
        $field = $this->plugin->app()->get(FieldRegistry::class)->fieldsForContentType($entry->type)[$name] ?? null;
        // Relation IDs may point to private entries. Arrays/multiple values are not exposed.
        if (!$field instanceof Field || $field->multiple || $field->type === 'entry') {
            return '';
        }
        $value = $entry->meta($name);
        if ($field->type === 'media') {
            return (is_int($value) || (is_string($value) && ctype_digit($value))) ? $this->image((int) $value) : '';
        }
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            return '';
        }
        $value = (string) $value;
        return strlen($value) <= 2048 ? $value : '';
    }
}
