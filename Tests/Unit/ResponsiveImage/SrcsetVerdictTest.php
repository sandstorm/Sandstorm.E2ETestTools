<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\ResponsiveImage;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\ResponsiveImage\SrcsetVerdict;

class SrcsetVerdictTest extends UnitTestCase
{
    private const WIDTHS = [200, 300, 400, 500, 600, 800, 1000];

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function images(): iterable
    {
        // shown 358 px at ratio 1 -> needs 358 px -> 400w fitting, 300w one smaller, 500w one larger
        yield 'fitting' => [['boxWidth' => 358, 'sizesWidth' => 400, 'loaded' => 400], 'fitting'];
        yield 'exactly the needed width' => [['boxWidth' => 400, 'sizesWidth' => 400, 'loaded' => 400], 'fitting'];
        yield 'one smaller' => [['boxWidth' => 358, 'sizesWidth' => 300, 'loaded' => 300], 'one smaller'];
        yield 'one larger' => [['boxWidth' => 358, 'sizesWidth' => 500, 'loaded' => 500], 'one larger'];
        yield 'too large' => [['boxWidth' => 358, 'sizesWidth' => 600, 'loaded' => 600], 'too large'];
        yield 'too small' => [['boxWidth' => 358, 'sizesWidth' => 200, 'loaded' => 200], 'too small'];
        yield 'capped' => [['boxWidth' => 1200, 'sizesWidth' => 1000, 'loaded' => 1000], 'capped'];
        yield 'one smaller than the largest is not capped' => [['boxWidth' => 1200, 'sizesWidth' => 800, 'loaded' => 800], 'one smaller'];
        // sizes 1 % too narrow crosses the srcset step 400 -> 300w - within the tolerance
        yield 'sizes within the tolerance' => [['boxWidth' => 303, 'sizesWidth' => 300, 'loaded' => 300], 'fitting'];
        yield 'sizes outside the tolerance' => [['boxWidth' => 310, 'sizesWidth' => 300, 'loaded' => 300], 'one smaller'];
        yield 'pixel ratio' => [['boxWidth' => 358, 'ratio' => 2, 'sizesWidth' => 358, 'loaded' => 800], 'fitting'];
        yield 'pixel ratio, loaded for ratio 1' => [['boxWidth' => 358, 'ratio' => 2, 'sizesWidth' => 200, 'loaded' => 400], 'too small'];
        // box 300 x 300, image 16:10 -> shown 480 px wide
        yield 'object-fit cover' => [['boxWidth' => 300, 'boxHeight' => 300, 'aspectRatio' => 1.6, 'objectFit' => 'cover', 'sizesWidth' => 300, 'loaded' => 300], 'too small'];
        yield 'object-fit cover, fitting' => [['boxWidth' => 300, 'boxHeight' => 300, 'aspectRatio' => 1.6, 'objectFit' => 'cover', 'sizesWidth' => 480, 'loaded' => 500], 'fitting'];
        // box 300 x 300, image 1:2 -> shown 150 px wide
        yield 'object-fit contain' => [['boxWidth' => 300, 'boxHeight' => 300, 'aspectRatio' => 0.5, 'objectFit' => 'contain', 'sizesWidth' => 150, 'loaded' => 200], 'fitting'];
        yield 'unsorted srcset' => [['widths' => [1000, 200, 600, 400], 'boxWidth' => 358, 'sizesWidth' => 600, 'loaded' => 600], 'one larger'];
    }

    /**
     * @param array<string, mixed> $image
     */
    #[Test]
    #[DataProvider('images')]
    public function judgesTheLoadedSrcsetImage(array $image, string $expected): void
    {
        $verdict = SrcsetVerdict::judge($image + ['widths' => self::WIDTHS, 'ratio' => 1, 'boxHeight' => 200, 'aspectRatio' => 1.6, 'objectFit' => 'fill']);

        self::assertSame($expected, $verdict->result, implode(' · ', $verdict->explanation));
    }

    #[Test]
    public function explainsWhatToCompare(): void
    {
        $verdict = SrcsetVerdict::judge(['widths' => self::WIDTHS, 'ratio' => 1, 'boxWidth' => 384, 'boxHeight' => 240, 'objectFit' => 'fill', 'sizesWidth' => 1248, 'loaded' => 1000]);

        self::assertSame([
            'needs 384 px (shown 384 px × ratio 1)',
            'sizes says 1248 px -> too wide',
            'loaded 1000w, expected 400w -> too large',
        ], $verdict->explanation);
    }

    #[Test]
    public function explainsOneLarger(): void
    {
        $verdict = SrcsetVerdict::judge(['widths' => self::WIDTHS, 'ratio' => 1.25, 'boxWidth' => 300, 'boxHeight' => 200, 'objectFit' => 'fill', 'sizesWidth' => 400, 'loaded' => 500]);

        self::assertSame([
            'needs 375 px (shown 300 px × ratio 1.25)',
            'sizes says 400 px -> too wide',
            'loaded 500w, expected 400w -> one size larger (more bytes)',
        ], $verdict->explanation);
    }

    #[Test]
    public function parsesTheExpectedResults(): void
    {
        self::assertSame(['fitting', 'one larger'], SrcsetVerdict::parseExpected('fitting or one larger'));
        self::assertSame(['fitting', 'one smaller', 'capped'], SrcsetVerdict::parseExpected(' fitting or  one smaller or capped '));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownResults(): iterable
    {
        yield 'typo' => ['fitting or one large'];
        yield 'too large is never expected' => ['too large'];
        yield 'too small is never expected' => ['fitting or too small'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('unknownResults')]
    public function unknownExpectedResultsFail(string $expected): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('use one of: fitting, one smaller, one larger, capped');

        SrcsetVerdict::parseExpected($expected);
    }
}
