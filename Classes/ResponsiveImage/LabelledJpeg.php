<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\ResponsiveImage;

use Jcupitt\Vips\Image as VipsImage;
use Neos\Flow\Annotations as Flow;

/**
 * Grey JPEG with the label in large white letters - test images for {@see \Sandstorm\E2ETestTools\Behat\ResponsiveImageTrait}.
 * Every label gives different content: Neos stores files by content hash, identical images would share their
 * thumbnails.
 *
 * Rendered with php-vips (jcupitt/vips via FFI, smooth text) if available, else with ext-gd (built-in font scaled up,
 * pixelated).
 *
 * @Flow\Proxy(false)
 */
final class LabelledJpeg
{
    private const BACKGROUND = [90, 100, 115];

    public static function render(int $width, int $height, string $label): string
    {
        if (class_exists(VipsImage::class) && extension_loaded('ffi')) {
            try {
                return self::vips($width, $height, $label);
            } catch (\Throwable $error) {
                // e.g. libvips missing - GD still works
                if (!extension_loaded('gd')) {
                    throw new \RuntimeException(sprintf('Generating a test image with php-vips failed and ext-gd is not loaded: %s', $error->getMessage()), 1760947200, $error);
                }
            }
        }
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('Generating test images needs php-vips (jcupitt/vips with ext-ffi) or ext-gd.', 1760947201);
        }
        return self::gd($width, $height, $label);
    }

    /**
     * The jcupitt/vips library (FFI) - not the functions of the vips PHP extension, which e.g. a CI image for PHPStan
     * might not have.
     */
    public static function vips(int $width, int $height, string $label): string
    {
        // render the text once to measure it, then again at the size that fills 25 % of the image width
        $measured = VipsImage::text($label, ['dpi' => 300, 'font' => 'sans bold']);
        $text = VipsImage::text($label, ['dpi' => (int)(300 * 0.25 * $width / $measured->width), 'font' => 'sans bold']);
        $mask = $text->gravity('centre', $width, $height);
        $background = VipsImage::black($width, $height, ['bands' => 3])->linear([1.0, 1.0, 1.0], array_map('floatval', self::BACKGROUND))->cast('uchar');
        return $mask->ifthenelse([255, 255, 255], $background, ['blend' => true])->writeToBuffer('.jpg', ['Q' => 90]);
    }

    /**
     * The built-in GD font (no font file needed), drawn small and scaled up to 25 % of the image width.
     */
    public static function gd(int $width, int $height, string $label): string
    {
        $font = 5;
        $textWidth = max(1, strlen($label) * imagefontwidth($font));
        $textHeight = imagefontheight($font);
        $text = self::gdCanvas($textWidth, $textHeight);
        imagestring($text, $font, 0, 0, $label, self::gdColor($text, 255, 255, 255));

        $image = self::gdCanvas($width, $height);
        $scale = 0.25 * $width / $textWidth;
        $scaledWidth = (int)round($textWidth * $scale);
        $scaledHeight = (int)round($textHeight * $scale);
        imagecopyresized($image, $text, intdiv($width - $scaledWidth, 2), intdiv($height - $scaledHeight, 2), 0, 0, $scaledWidth, $scaledHeight, $textWidth, $textHeight);

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();
        if (!is_string($jpeg) || $jpeg === '') {
            throw new \RuntimeException('GD could not write the test image as JPEG.', 1760947202);
        }
        return $jpeg;
    }

    private static function gdCanvas(int $width, int $height): \GdImage
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException(sprintf('A test image needs a size of at least 1 x 1 px, not %d x %d.', $width, $height), 1760947205);
        }
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new \RuntimeException(sprintf('GD could not create a %d x %d image.', $width, $height), 1760947203);
        }
        imagefill($image, 0, 0, self::gdColor($image, ...self::BACKGROUND));
        return $image;
    }

    /**
     * @param int<0, 255> $red
     * @param int<0, 255> $green
     * @param int<0, 255> $blue
     */
    private static function gdColor(\GdImage $image, int $red, int $green, int $blue): int
    {
        $color = imagecolorallocate($image, $red, $green, $blue);
        if ($color === false) {
            throw new \RuntimeException('GD could not allocate a color.', 1760947204);
        }
        return $color;
    }
}
