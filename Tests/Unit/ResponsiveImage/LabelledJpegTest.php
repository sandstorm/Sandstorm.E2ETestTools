<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\ResponsiveImage;

use Jcupitt\Vips\Image as VipsImage;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\ResponsiveImage\LabelledJpeg;

/**
 * Both renderers where available - the development distribution has ext-gd, projects mostly php-vips.
 */
class LabelledJpegTest extends UnitTestCase
{
    #[Test]
    public function gdRendersAJpegOfTheGivenSize(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is not loaded.');
        }
        self::assertJpegOfSize(LabelledJpeg::gd(1600, 1000, 'image-1'), 1600, 1000);
    }

    #[Test]
    public function vipsRendersAJpegOfTheGivenSize(): void
    {
        if (!class_exists(VipsImage::class) || !extension_loaded('ffi')) {
            self::markTestSkipped('php-vips (jcupitt/vips with ext-ffi) is not available.');
        }
        self::assertJpegOfSize(LabelledJpeg::vips(1600, 1000, 'image-1'), 1600, 1000);
    }

    #[Test]
    public function differentLabelsGiveDifferentContent(): void
    {
        if (!extension_loaded('gd') && !(class_exists(VipsImage::class) && extension_loaded('ffi'))) {
            self::markTestSkipped('Neither ext-gd nor php-vips is available.');
        }
        self::assertNotSame(LabelledJpeg::render(400, 250, 'image-1'), LabelledJpeg::render(400, 250, 'image-2'));
    }

    private static function assertJpegOfSize(string $jpeg, int $width, int $height): void
    {
        $size = getimagesizefromstring($jpeg);
        self::assertIsArray($size);
        self::assertSame([$width, $height, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);
    }
}
