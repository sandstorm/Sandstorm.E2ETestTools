<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\StepGenerator;

use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Media\Domain\Model\ImageInterface;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\StepGenerator\ImageTable;
use Sandstorm\E2ETestTools\StepGenerator\PersistentResourceFixtures;

class ImageTableTest extends UnitTestCase
{
    #[Test]
    public function imageRowHasImageAndResourceColumnsWithPathRelativeToThePackagesDirectory(): void
    {
        $output = $this->printedTable(new PersistentResourceFixtures(FLOW_PATH_PACKAGES . 'Sites/Vendor.Site/Fixtures'));

        self::assertMatchesRegularExpression('/\| Image ID +\| Width +\| Height +\| Filename +\| Collection +\| Relative Publication Path +\| Path +\|/', $output);
        self::assertStringContainsString('| Sites/Vendor.Site/Fixtures/abc123.jpg |', $output);
    }

    #[Test]
    public function defaultImageAndPersistentResourcePropertiesBecomeColumns(): void
    {
        $output = $this->printedTable(
            new PersistentResourceFixtures(FLOW_PATH_PACKAGES . 'Sites/Vendor.Site/Fixtures/', ['Resource Extra' => 'r']),
            ['Copyright Notice' => '© Sandstorm']
        );

        self::assertMatchesRegularExpression('/\| Copyright Notice +\| Resource Extra +\|/', $output);
        self::assertMatchesRegularExpression('/\| © Sandstorm +\| r +\|/', $output);
    }

    /**
     * @param array<string,string> $defaultImageProperties
     */
    private function printedTable(PersistentResourceFixtures $persistentResourceFixtures, array $defaultImageProperties = []): string
    {
        $resource = $this->createMock(PersistentResource::class);
        $resource->method('getSha1')->willReturn('abc123');
        $resource->method('getFileExtension')->willReturn('jpg');
        $resource->method('getFilename')->willReturn('cat.jpg');
        $resource->method('getCollectionName')->willReturn('persistent');
        $resource->method('getRelativePublicationPath')->willReturn('');
        $image = $this->createMock(ImageInterface::class);
        $image->method('getResource')->willReturn($resource);
        $image->method('getWidth')->willReturn(640);
        $image->method('getHeight')->willReturn(480);

        $table = new ImageTable($persistentResourceFixtures, $defaultImageProperties);
        $table->addImage('3a28c97c-58f1-45c5-b1ad-2f491c904467', $image);
        ob_start();
        $table->print();
        return (string)ob_get_clean();
    }
}
