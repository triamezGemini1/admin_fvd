<?php

declare(strict_types=1);

namespace Fvd\Ui;

/**
 * Tarjeta modular FVD (título pastel + degradado + ítems de navegación).
 * Uso en vistas PHP: echo FvdCard::render([...]);
 */
final class FvdCard
{
    /** @var list<string> */
    public const TONES = ['servicios', 'supervision', 'operaciones', 'finanzas', 'delegado'];

    /**
     * @param array{
     *   tone?: string,
     *   title: string,
     *   titleId?: string,
     *   items?: list<array{
     *     label: string,
     *     icon?: string,
     *     accent?: string,
     *     href?: string,
     *     type?: string,
     *     attrs?: array<string, string>
     *   }>,
     *   extraClass?: string
     * } $opts
     */
    public static function render(array $opts): string
    {
        $tone = self::normalizeTone($opts['tone'] ?? 'servicios');
        $title = self::esc((string) ($opts['title'] ?? ''));
        $titleId = isset($opts['titleId']) ? self::esc((string) $opts['titleId']) : '';
        $idAttr = $titleId !== '' ? ' id="' . $titleId . '"' : '';
        $extra = isset($opts['extraClass']) ? ' ' . self::escClass((string) $opts['extraClass']) : '';
        $items = is_array($opts['items'] ?? null) ? $opts['items'] : [];

        $itemsHtml = '';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemsHtml .= self::renderItem($item);
        }

        return '<section class="fvd-card fvd-card--tone-' . $tone . $extra . '" aria-labelledby="' . ($titleId !== '' ? $titleId : 'fvd-card-h') . '">'
            . '<header class="fvd-card__head"><h2 class="fvd-card__title"' . $idAttr . '>' . $title . '</h2></header>'
            . '<div class="fvd-card__body">' . $itemsHtml . '</div>'
            . '</section>';
    }

    /**
     * @param list<array<string, mixed>> $cards
     */
    public static function renderGrid(array $cards, string $gridClass = ''): string
    {
        $cls = 'fvd-card-grid' . ($gridClass !== '' ? ' ' . self::escClass($gridClass) : '');
        $inner = '';
        foreach ($cards as $card) {
            if (is_array($card)) {
                $inner .= self::render($card);
            }
        }

        return '<div class="' . $cls . '">' . $inner . '</div>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function renderItem(array $item): string
    {
        $label = (string) ($item['label'] ?? '');
        $icon = isset($item['icon']) ? self::esc((string) $item['icon']) : '';
        $accent = self::normalizeAccent((string) ($item['accent'] ?? 'neutral'));
        $href = isset($item['href']) ? trim((string) $item['href']) : '';
        $type = isset($item['type']) ? strtolower((string) $item['type']) : 'button';
        $attrs = is_array($item['attrs'] ?? null) ? $item['attrs'] : [];
        $attrStr = self::buildAttrs($attrs);
        $cls = 'fvd-card__item fvd-card__item--accent-' . $accent;

        $inner = '<span class="fvd-card__item-label">' . $label . '</span>'
            . ($icon !== '' ? '<span class="fvd-card__item-ic" aria-hidden="true">' . $icon . '</span>' : '');

        if ($href !== '') {
            return '<a class="' . $cls . ' fvd-card__item--link" href="' . self::esc($href) . '"' . $attrStr . '>' . $inner . '</a>';
        }

        $tag = $type === 'button' ? 'button' : 'button';
        $btnType = $type === 'submit' ? 'submit' : 'button';

        return '<' . $tag . ' type="' . $btnType . '" class="' . $cls . '"' . $attrStr . '>' . $inner . '</' . $tag . '>';
    }

    private static function normalizeTone(string $tone): string
    {
        $t = preg_replace('/[^a-z]/', '', strtolower($tone)) ?? 'servicios';

        return in_array($t, self::TONES, true) ? $t : 'servicios';
    }

    private static function normalizeAccent(string $accent): string
    {
        $a = preg_replace('/[^a-z0-9]/', '', strtolower($accent)) ?? 'neutral';
        if ($a === '' || $a === 'neutral') {
            return 'sup3';
        }

        return $a;
    }

    /**
     * @param array<string, string> $attrs
     */
    private static function buildAttrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $k => $v) {
            $key = preg_replace('/[^a-z0-9_-]/i', '', (string) $k) ?? '';
            if ($key === '') {
                continue;
            }
            $out .= ' ' . $key . '="' . self::esc((string) $v) . '"';
        }

        return $out;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function escClass(string $s): string
    {
        return preg_replace('/[^a-zA-Z0-9_\\-\\s]/', '', $s) ?? '';
    }
}
