<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\ResponsiveImage;

use Neos\Flow\Annotations as Flow;

/**
 * Which srcset image the browser loaded compared to the fitting one, and why - the rule of {@see \Sandstorm\E2ETestTools\Behat\ResponsiveImageTrait}.
 *
 * - needed width = width the image content is shown with (CSS px) x device pixel ratio
 * - fitting = the smallest srcset width that is at least the needed width (above the largest: the largest)
 *
 * Results: fitting, capped (needed more than the largest, loaded the largest), one smaller / one larger (the srcset
 * width right below / above the fitting one), too small / too large (anything else). If `sizes` differs from the
 * shown width by at most {@see self::TOLERANCE}, the result is fitting, whatever was loaded.
 *
 * @Flow\Proxy(false)
 */
final readonly class SrcsetVerdict
{
    /**
     * `sizes` may deviate from the shown width this much (rem rounding, scrollbar) - then whatever the browser loads is
     * OK. Without it, a 1 px deviation crossing a srcset step would be an error.
     */
    public const TOLERANCE = 0.02;

    /**
     * The results an image may be expected to have - too small and too large are always errors.
     */
    public const EXPECTABLE = ['fitting', 'one smaller', 'one larger', 'capped'];

    /**
     * Expected when nothing else is declared for an image.
     */
    public const DEFAULT_EXPECTED = ['fitting', 'capped'];

    /**
     * @param 'fitting'|'capped'|'one smaller'|'one larger'|'too small'|'too large' $result
     * @param list<string> $explanation what the image needs, what `sizes` says, what was loaded vs. expected
     */
    private function __construct(
        public string $result,
        public array $explanation,
    ) {
    }

    /**
     * @param array<mixed> $image as collected in the browser: widths (of the srcset), loaded (srcset width of
     *     currentSrc), ratio (device pixel ratio), sizesWidth (the width `sizes` claims), boxWidth, boxHeight,
     *     aspectRatio (of the image), objectFit
     */
    public static function judge(array $image): self
    {
        $widths = array_values(array_filter(is_array($image['widths'] ?? null) ? $image['widths'] : [], is_int(...)));
        sort($widths);
        $loaded = self::intValue($image['loaded'] ?? 0);
        $ratio = self::floatValue($image['ratio'] ?? 1);
        $sizesWidth = self::floatValue($image['sizesWidth'] ?? 0);
        $shown = self::shownWidth($image);
        $needed = (int)ceil($shown * $ratio);

        // above the largest srcset image: the largest one
        $fitting = $widths === [] ? 0 : $widths[array_key_last($widths)];
        foreach ($widths as $width) {
            if ($width >= $needed) {
                $fitting = $width;
                break;
            }
        }
        $fittingIndex = array_search($fitting, $widths, true);
        $oneSmaller = is_int($fittingIndex) && $fittingIndex > 0 ? $widths[$fittingIndex - 1] : null;
        $oneLarger = is_int($fittingIndex) ? $widths[$fittingIndex + 1] ?? null : null;
        $sizesDeviation = $shown > 0 ? ($sizesWidth - $shown) / $shown : 0;

        $result = match (true) {
            $loaded === $fitting && $loaded < $needed => 'capped',
            $loaded === $fitting => 'fitting',
            // `sizes` is right within the tolerance - whatever the browser picked from it is fine
            abs($sizesDeviation) <= self::TOLERANCE => 'fitting',
            $loaded === $oneSmaller => 'one smaller',
            $loaded === $oneLarger => 'one larger',
            $loaded > $fitting => 'too large',
            default => 'too small',
        };

        return new self($result, [
            sprintf('needs %d px (shown %d px × ratio %s)', $needed, (int)round($shown), rtrim(rtrim(sprintf('%.2f', $ratio), '0'), '.')),
            sprintf('sizes says %d px%s', (int)round($sizesWidth), match (true) {
                $sizesDeviation > self::TOLERANCE => ' -> too wide',
                $sizesDeviation < -self::TOLERANCE => ' -> too narrow',
                default => '',
            }),
            match ($result) {
                'capped' => sprintf('loaded %dw, the largest srcset image -> capped', $loaded),
                'fitting' => $loaded === $fitting
                    ? sprintf('loaded %dw = expected %dw', $loaded, $fitting)
                    : sprintf('loaded %dw, expected %dw - OK: sizes is within %d %%', $loaded, $fitting, self::TOLERANCE * 100),
                'one smaller' => sprintf('loaded %dw, expected %dw -> one size smaller (slightly blurry)', $loaded, $fitting),
                'one larger' => sprintf('loaded %dw, expected %dw -> one size larger (more bytes)', $loaded, $fitting),
                'too large' => sprintf('loaded %dw, expected %dw -> too large', $loaded, $fitting),
                'too small' => sprintf('loaded %dw, expected %dw -> too small (blurry)', $loaded, $fitting),
            },
        ]);
    }

    /**
     * The `expected` cell of a step table, e.g. "fitting or one smaller".
     *
     * @return list<string>
     */
    public static function parseExpected(string $expected): array
    {
        $results = array_map('trim', explode(' or ', $expected));
        $unknown = array_diff($results, self::EXPECTABLE);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown expected result "%s" - use one of: %s (combined with "or").',
                implode('", "', $unknown),
                implode(', ', self::EXPECTABLE)
            ));
        }
        return $results;
    }

    /**
     * The width the image content is shown with - with `object-fit: cover` wider than its box (the image is scaled to
     * the box height and cut at the sides), with `contain` maybe narrower.
     *
     * @param array<mixed> $image
     */
    private static function shownWidth(array $image): float
    {
        $boxWidth = self::floatValue($image['boxWidth'] ?? 0);
        $boxHeight = self::floatValue($image['boxHeight'] ?? 0);
        $aspectRatio = self::floatValue($image['aspectRatio'] ?? 0);
        return match ($image['objectFit'] ?? 'fill') {
            'cover' => max($boxWidth, $boxHeight * $aspectRatio),
            'contain' => min($boxWidth, $boxHeight * $aspectRatio),
            default => $boxWidth,
        };
    }

    private static function intValue(mixed $value): int
    {
        return is_int($value) || is_float($value) ? (int)$value : 0;
    }

    private static function floatValue(mixed $value): float
    {
        return is_int($value) || is_float($value) ? (float)$value : 0.0;
    }
}
