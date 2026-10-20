<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Doctrine\Common\Collections\ArrayCollection;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Utility\ObjectAccess;
use PHPUnit\Framework\Assert;
use Sandstorm\E2ETestTools\ResponsiveImage\LabelledJpeg;
use Sandstorm\E2ETestTools\ResponsiveImage\SrcsetVerdict;

/**
 * Checks that the browser loads the right srcset image - i.e. that the `sizes` lists tell the browser the width an
 * image is really shown with. The rule (fitting / one smaller / one larger / capped / error): {@see SrcsetVerdict},
 * README "ResponsiveImageTrait".
 *
 * Only pixels are compared, not bytes - so the image content doesn't matter (generated images showing their id).
 * Images are identified by their alt text. Needs {@see PlaywrightTrait} and {@see PersistentResourceTrait} (used by
 * {@see FusionRenderingTrait}) in the same context. Chromium only, like `the screen is ... with pixel ratio ...`.
 */
trait ResponsiveImageTrait
{
    abstract public function getObjectManager(): ObjectManagerInterface;

    /**
     * Images, 1600 x 1000, showing their asset id - so you see which image is where when debugging, and every image
     * has different content: Neos stores files by content hash, identical images would share their thumbnail files,
     * and the browser could reuse an already loaded larger image of another slot.
     * Asset ids: image-1 ... image-n
     */
    #[Given('I have :count generated test images')]
    public function iHaveGeneratedTestImages(int $count): void
    {
        for ($number = 1; $number <= $count; $number++) {
            $id = sprintf('image-%d', $number);
            $this->responsiveImage_importGeneratedImage($id, LabelledJpeg::render(1600, 1000, $id));
        }
        $this->callResourcePersistedHook();
    }

    /**
     * An image with the given aspect ratio (width:height, e.g. "1:1" like an image the editor cropped square, "3:4"
     * portrait), the longer side 1600 px.
     */
    #[Given('I have a generated test image with id :id and aspect ratio :ratio')]
    public function iHaveAGeneratedTestImageWithIdAndAspectRatio(string $id, string $ratio): void
    {
        [$width, $height] = array_map('intval', explode(':', $ratio)) + [1, 1];
        Assert::assertGreaterThan(0, $width * $height, sprintf('Invalid aspect ratio "%s", expected e.g. "3:4".', $ratio));
        $scale = 1600 / max($width, $height);
        $this->responsiveImage_importGeneratedImage($id, LabelledJpeg::render((int)round($width * $scale), (int)round($height * $scale), $id));
        $this->callResourcePersistedHook();
    }

    /**
     * Comma-separated asset ids, e.g. "gallery-1, gallery-2" - the collection's identifier is what e.g. a gallery's
     * collection property refers to.
     */
    #[Given('the asset collection :id contains the images :ids')]
    public function theAssetCollectionContainsTheImages(string $id, string $ids): void
    {
        $collection = new AssetCollection($id);
        // setProperty() takes the subject by reference - a second variable keeps $collection typed
        $subject = $collection;
        ObjectAccess::setProperty($subject, 'Persistence_Object_Identifier', $id, true);
        $this->getObjectManager()->get(AssetCollectionRepository::class)->add($collection);
        foreach (array_map('trim', explode(',', $ids)) as $assetId) {
            $asset = $this->persistentResource_assetRepository->findByIdentifier($assetId);
            Assert::assertInstanceOf(Asset::class, $asset, sprintf('There is no image "%s".', $assetId));
            $asset->setAssetCollections(new ArrayCollection([$collection]));
            $collection->addAsset($asset);
            $this->persistentResource_assetRepository->update($asset);
        }
        $this->persistentResource_persistenceManager->persistAll();
    }

    /**
     * Images are loaded lazily - switch them to eager (the browser loads them right away, the srcset image it picks
     * stays the same), then wait until every image is loaded. Faster than scrolling through the page.
     */
    #[When('I load all images of the page')]
    public function iLoadAllImagesOfThePage(): void
    {
        $this->playwrightRun(<<<'JS'
            await vars.page.evaluate(() => document.querySelectorAll('img[loading="lazy"]').forEach((image) => image.loading = 'eager'));
            // thumbnails are generated on their first request - up to 15 seconds
            await expect.poll(
                () => vars.page.evaluate(() => [...document.images].filter((image) => !image.complete).map((image) => image.currentSrc || image.src)),
                {message: 'Images of the page that are not loaded yet', timeout: 15000, intervals: [50]}
            ).toEqual([]);
        JS);
    }

    /**
     * For debugging (e.g. before `I pause for debugging`): puts the result on every image, judged with the default
     * expectation (fitting or capped). The checks below do it too, so it also shows on the error screenshots.
     */
    #[When('I mark the image sizes on the page')]
    public function iMarkTheImageSizesOnThePage(): void
    {
        $this->responsiveImage_verdicts([]);
    }

    /**
     * For debugging: scrolls the first image with this alt text to the top of the viewport.
     */
    #[When('I scroll to the image :alt')]
    public function iScrollToTheImage(string $alt): void
    {
        $this->playwrightRun(<<<'JS'
            await vars.page.getByAltText(%s, {exact: true}).first().evaluate((image) => image.scrollIntoView({block: 'start'}));
        JS, $alt);
    }

    /**
     * Every image has to load the fitting srcset image - or the largest one, if even that is too small (capped).
     */
    #[Then('every image should load the fitting srcset image')]
    public function everyImageShouldLoadTheFittingSrcsetImage(): void
    {
        $this->responsiveImage_assert([]);
    }

    /**
     * Like above, but the listed images (by alt text) may load what the table says instead, e.g.
     *   | image     | expected               |
     *   | gallery-1 | fitting or one smaller |
     *   | teaser    | fitting or one larger  |
     * Results: fitting, one smaller, one larger, capped - the table replaces the default (fitting or capped).
     */
    #[Then('every image should load the fitting srcset image, except:')]
    public function everyImageShouldLoadTheFittingSrcsetImageExcept(TableNode $exceptions): void
    {
        $expected = [];
        foreach ($exceptions->getColumnsHash() as $row) {
            Assert::assertArrayHasKey('image', $row, 'The table needs the columns "image" and "expected".');
            Assert::assertArrayHasKey('expected', $row, 'The table needs the columns "image" and "expected".');
            $expected[$row['image']] = SrcsetVerdict::parseExpected($row['expected']);
        }
        $this->responsiveImage_assert($expected);
    }

    /**
     * The asset title is the id too - e.g. for a gallery sorting by title or using it as alt text.
     */
    private function responsiveImage_importGeneratedImage(string $id, string $jpeg): void
    {
        $resource = $this->persistentResource_resourceManager->importResourceFromContent($jpeg, $id . '.jpg');
        $image = new Image($resource);
        $image->setTitle($id);
        $image->refresh();
        // setProperty() takes the subject by reference - a second variable keeps $image typed as Image
        $subject = $image;
        ObjectAccess::setProperty($subject, 'Persistence_Object_Identifier', $id, true);
        $this->persistentResource_assetRepository->add($image);
        $this->persistentResource_persistenceManager->persistAll();
        $this->publishResource($resource);
    }

    /**
     * @param array<string, list<string>> $expected results per image (alt text), the others: fitting or capped
     */
    private function responsiveImage_assert(array $expected): void
    {
        $lines = ['error' => [], 'one smaller' => [], 'one larger' => [], 'capped' => [], 'fitting' => []];
        $seen = [];
        foreach ($this->responsiveImage_verdicts($expected) as [$verdict, $explanation, $alt]) {
            $lines[$verdict][] = implode(' · ', $explanation);
            $seen[] = $alt;
        }
        Assert::assertNotSame([], $seen, 'No image with a srcset was found on the page.');
        Assert::assertSame([], array_values(array_diff(array_keys($expected), $seen)), 'These images of the table are not on the page.');

        $report = sprintf("Page %s:\n", self::playwrightString($this->playwrightRun('return new URL(vars.page.url()).pathname;')));
        $titles = [
            'error' => 'ERRORS',
            'one smaller' => 'One srcset image smaller (expected)',
            'one larger' => 'One srcset image larger (expected)',
            'capped' => 'Capped (needs more than the largest srcset image)',
        ];
        foreach ($titles as $verdict => $title) {
            if ($lines[$verdict] !== []) {
                $report .= sprintf("%s:\n- %s\n", $title, implode("\n- ", $lines[$verdict]));
            }
        }
        Assert::assertSame([], $lines['error'], "Images load the wrong srcset image:\n" . $report);
        if ($lines['one smaller'] !== [] || $lines['one larger'] !== [] || $lines['capped'] !== []) {
            echo $report;
        }
    }

    /**
     * Checks every image and puts the verdict as a badge on it: green fitting, lime one smaller / amber one larger
     * (expected), grey capped, red error (a result that is not expected for this image).
     *
     * @param array<string, list<string>> $expected results per image (alt text), the others: fitting or capped
     * @return list<array{'fitting'|'one smaller'|'one larger'|'capped'|'error', list<string>, string}>
     */
    private function responsiveImage_verdicts(array $expected): array
    {
        $verdicts = [];
        $badges = [];
        foreach ($this->responsiveImage_loadedSrcsetImages() as $image) {
            $alt = self::playwrightString($image['element'] ?? '');
            $judged = SrcsetVerdict::judge($image);
            $explanation = $judged->explanation;
            $allowed = $expected[$alt] ?? SrcsetVerdict::DEFAULT_EXPECTED;
            if (in_array($judged->result, $allowed, true) && $judged->result !== 'too large' && $judged->result !== 'too small') {
                $verdict = $judged->result;
                array_unshift($explanation, sprintf('%s · %s', strtoupper($verdict), $alt));
            } else {
                $verdict = 'error';
                array_unshift($explanation, sprintf('ERROR · %s · %s, not expected here (expected: %s)', $alt, $judged->result, implode(' or ', $allowed)));
            }
            $verdicts[] = [$verdict, $explanation, $alt];
            $index = $image['index'] ?? 0;
            $badges[] = ['index' => is_int($index) ? $index : 0, 'verdict' => $verdict, 'text' => implode("\n", $explanation)];
        }
        $this->playwrightRun(<<<'JS'
            await vars.page.evaluate((badges) => {
                document.querySelectorAll('.image-size-badge').forEach((badge) => badge.remove());
                const colors = {'fitting': '#16a34a', 'one smaller': '#65a30d', 'one larger': '#d97706', 'capped': '#6b7280', 'error': '#dc2626'};
                for (const {index, verdict, text} of badges) {
                    const box = document.querySelector(`[data-image-size-check="${index}"]`).getBoundingClientRect();
                    const badge = document.createElement('div');
                    badge.className = 'image-size-badge';
                    badge.textContent = text;
                    // CSSOM, not a style attribute - allowed by a content security policy
                    Object.assign(badge.style, {
                        position: 'absolute', zIndex: 10000, left: `${box.left + scrollX}px`, top: `${box.top + scrollY}px`,
                        maxWidth: `${box.width}px`, padding: '2px 4px', font: '11px/1.3 monospace', color: 'white', whiteSpace: 'pre-line',
                        background: colors[verdict], pointerEvents: 'none',
                    });
                    document.body.append(badge);
                }
            }, %s);
        JS, $badges);
        return $verdicts;
    }

    /**
     * Every shown content image with a srcset (not static resources like a logo): its box, object-fit, the srcset
     * widths and which of them was loaded (the srcset entry of `currentSrc`). `naturalWidth` is the width `sizes`
     * claims - the browser divides the loaded image width by the density it picked.
     *
     * Fails for `sizes="auto"`: Chromium then uses the real width and ignores the rest of `sizes` - the check would pass
     * without checking the `sizes` other browsers use.
     *
     * @return list<array<mixed>>
     */
    private function responsiveImage_loadedSrcsetImages(): array
    {
        $images = array_values(array_filter($this->playwrightArray(<<<'JS'
            return await vars.page.evaluate(() => [...document.images]
                .filter((image) => image.currentSrc && !image.currentSrc.includes('/_Resources/Static/'))
                .filter((image) => image.getBoundingClientRect().width > 0)
                .map((image, index) => {
                    const element = [image, ...(image.closest('picture')?.querySelectorAll('source') ?? [])]
                        .map((element) => ({element, candidates: (element.getAttribute('srcset') ?? '').split(',')
                            .map((entry) => entry.trim().split(/\s+/))
                            .filter(([url, descriptor]) => url && descriptor?.endsWith('w'))
                            .map(([url, descriptor]) => ({url: new URL(url, location.href).href, width: parseInt(descriptor, 10)}))}))
                        .find(({candidates}) => candidates.some((candidate) => candidate.url === image.currentSrc));
                    if (!element) {
                        return null;
                    }
                    const {candidates} = element;
                    const box = image.getBoundingClientRect();
                    image.dataset.imageSizeCheck = index;
                    return {
                        index,
                        element: image.alt,
                        sizes: element.element.getAttribute('sizes') ?? '',
                        boxWidth: box.width,
                        boxHeight: box.height,
                        objectFit: getComputedStyle(image).objectFit,
                        aspectRatio: image.naturalWidth / image.naturalHeight,
                        sizesWidth: image.naturalWidth,
                        ratio: window.devicePixelRatio,
                        widths: candidates.map((candidate) => candidate.width),
                        loaded: candidates.find((candidate) => candidate.url === image.currentSrc).width,
                    };
                })
                .filter((image) => image !== null));
        JS), is_array(...)));

        $auto = [];
        foreach ($images as $image) {
            if (str_starts_with(strtolower(trim(self::playwrightString($image['sizes'] ?? ''))), 'auto')) {
                $auto[] = self::playwrightString($image['element'] ?? '');
            }
        }
        Assert::assertSame([], $auto, 'These images have sizes="auto" - Chromium then uses the real width and ignores the rest of `sizes`, so the check proves nothing. Switch "auto" off in the context of the system under test (see README "ResponsiveImageTrait").');
        return $images;
    }
}
