<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Functional\Controller;

use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Utility\ObjectAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Controller\NodeExportController;
use Sandstorm\E2ETestTools\Service\NodeExportService;
use Sandstorm\E2ETestTools\Service\NodeNotFoundException;

/**
 * The export endpoint can read content of any workspace - it must only be usable by backend users.
 */
class NodeExportControllerTest extends FunctionalTestCase
{
    protected $testableSecurityEnabled = true;

    private const UNKNOWN_NODE_ADDRESS = '{"contentRepositoryId":"default","workspaceName":"live","dimensionSpacePoint":{},"aggregateId":"does-not-exist"}';

    #[Test]
    public function anonymousUsersAreNotGrantedTheExport(): void
    {
        self::assertFalse($this->privilegeManager->isPrivilegeTargetGranted('Sandstorm.E2ETestTools:NodeExport'));
    }

    #[Test]
    #[DataProvider('backendRoles')]
    public function backendUsersAreGrantedTheExport(string $role): void
    {
        $this->authenticateRoles([$role]);

        self::assertTrue($this->privilegeManager->isPrivilegeTargetGranted('Sandstorm.E2ETestTools:NodeExport'));
    }

    public static function backendRoles(): iterable
    {
        yield 'editor' => ['Neos.Neos:Editor'];
        yield 'restricted editor' => ['Neos.Neos:RestrictedEditor'];
        yield 'administrator' => ['Neos.Neos:Administrator'];
    }

    #[Test]
    #[DataProvider('nonEditorRoles')]
    public function rolesThatCantEditContentAreNotGrantedTheExport(string $role): void
    {
        $this->authenticateRoles([$role]);

        self::assertFalse($this->privilegeManager->isPrivilegeTargetGranted('Sandstorm.E2ETestTools:NodeExport'));
    }

    public static function nonEditorRoles(): iterable
    {
        yield 'live publisher' => ['Neos.Neos:LivePublisher'];
        yield 'user manager' => ['Neos.Neos:UserManager'];
    }

    #[Test]
    public function anonymousRequestGetsNoExport(): void
    {
        $response = $this->browser->request($this->exportUri(self::UNKNOWN_NODE_ADDRESS));

        self::assertNotSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('nodes:', (string)$response->getBody());
        self::assertStringNotContainsString('does-not-exist', (string)$response->getBody(), 'the request must be denied before the node is looked up');
    }

    #[Test]
    #[DataProvider('malformedNodeAddresses')]
    public function malformedNodeAddressIsABadRequest(string $nodeAddress): void
    {
        $this->authenticateRoles(['Neos.Neos:Editor']);

        $response = $this->browser->request($this->exportUri($nodeAddress));

        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
    }

    public static function malformedNodeAddresses(): iterable
    {
        yield 'not json' => ['homepage'];
        yield 'json without required keys' => ['{"aggregateId":"homepage"}'];
        yield 'empty' => [''];
    }

    #[Test]
    public function unknownNodeIsNotFound(): void
    {
        // e.g. the node was deleted between selecting it in the backend and clicking "Export Node"
        $this->authenticateRoles(['Neos.Neos:Editor']);
        $nodeExportService = $this->createMock(NodeExportService::class);
        $nodeExportService->method('exportNodeTree')->willThrowException(new NodeNotFoundException('Node "does-not-exist" not found'));
        $this->objectManager->setInstance(NodeExportService::class, $nodeExportService);
        // the controller is a singleton that may already hold the real service
        $controller = $this->objectManager->get(NodeExportController::class);
        ObjectAccess::setProperty($controller, 'nodeExportService', $nodeExportService, true);

        $response = $this->browser->request($this->exportUri(self::UNKNOWN_NODE_ADDRESS));

        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('does-not-exist', (string)$response->getBody());
    }

    private function exportUri(string $nodeAddress): string
    {
        return 'http://localhost/api/export-node?node=' . rawurlencode($nodeAddress);
    }
}
