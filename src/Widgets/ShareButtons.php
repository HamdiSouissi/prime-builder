<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

/** Ordinary links: no external SDK, tracking request, or popup before a visitor acts. */
final class ShareButtons
{
    /** @param array<string, mixed> $settings */
    public static function render(array $settings, string $url): string
    {
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            return '<p class="pb-share-message">' . Renderer::escape(t('plugin::prime-builder.ui.share_unavailable')) . '</p>';
        }
        // Never persist tracking parameters, preview tokens, or fragments in a share link.
        // entryUrl() normally has none, but the configured base URL may contain them.
        $url = substr($url, 0, strcspn($url, '?#'));
        $label = self::plain($settings['label'] ?? null);
        $message = self::plain($settings['message'] ?? null);
        $label = $label !== '' ? $label : t('plugin::prime-builder.ui.share_default_label');
        $message = $message !== '' ? $message : t('plugin::prime-builder.ui.share_default_message');
        $encodedUrl = rawurlencode($url);
        $encodedMessage = rawurlencode($message);
        $links = [
            'facebook' => ['https://www.facebook.com/sharer/sharer.php?u=' . $encodedUrl, 'share_facebook'],
            'linkedin' => ['https://www.linkedin.com/sharing/share-offsite/?url=' . $encodedUrl, 'share_linkedin'],
            'x' => ['https://twitter.com/intent/tweet?url=' . $encodedUrl . '&text=' . $encodedMessage, 'share_x'],
            'email' => ['mailto:?subject=' . $encodedMessage . '&body=' . $encodedUrl, 'share_email'],
        ];
        $items = '';
        foreach ($links as $network => [$href, $translation]) {
            if (($settings[$network] ?? 'show') === 'hide') {
                continue;
            }
            $items .= '<li><a data-pb-share-service="' . $network . '" href="' . Renderer::escape($href) . '" rel="noreferrer">'
                . Renderer::escape(t('plugin::prime-builder.ui.' . $translation)) . '</a></li>';
        }
        if ($items === '') {
            return '<p class="pb-share-message">' . Renderer::escape(t('plugin::prime-builder.ui.share_none')) . '</p>';
        }
        return '<nav class="pb-share" aria-label="' . Renderer::escape($label) . '" data-pb-share data-pb-share-message="'
            . Renderer::escape($message) . '"><ul class="pb-share__list">' . $items . '</ul></nav>';
    }

    private static function plain(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        return trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', strip_tags($value)) ?? '');
    }
}
