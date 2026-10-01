<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Functional\Controller;

use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Neos\Ui\Domain\Service\ConfigurationRenderingService;
use Neos\Utility\ObjectAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Controller\NodeExportController;
use Sandstorm\E2ETestTools\Service\NodeExportService;
use Sandstorm\E2ETestTools\Service\NodeNotFoundException;

/**
 * The export endpoint can read content of any workspace - only administrators (i.e. the developers writing tests)
 * may use it. The UI asks the same privilege target to enable or disable the button.
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
    public function administratorsAreGrantedTheExport(): void
    {
        $this->authenticateRoles(['Neos.Neos:Administrator']);

        self::assertTrue($this->privilegeManager->isPrivilegeTargetGranted('Sandstorm.E2ETestTools:NodeExport'));
    }

    #[Test]
    #[DataProvider('nonAdministratorRoles')]
    public function otherBackendRolesAreNotGrantedTheExport(array $roles): void
    {
        $this->authenticateRoles($roles);

        self::assertFalse($this->privilegeManager->isPrivilegeTargetGranted('Sandstorm.E2ETestTools:NodeExport'));
    }

    public static function nonAdministratorRoles(): iterable
    {
        yield 'editor' => [['Neos.Neos:Editor']];
        yield 'restricted editor' => [['Neos.Neos:RestrictedEditor']];
        yield 'live publisher' => [['Neos.Neos:LivePublisher']];
        yield 'user manager' => [['Neos.Neos:UserManager']];
        yield 'editor + user manager' => [['Neos.Neos:Editor', 'Neos.Neos:UserManager']];
    }

    #[Test]
    #[DataProvider('buttonStates')]
    public function exportButtonIsOnlyEnabledForUsersGrantedTheExport(array $roles, bool $expectedEnabled): void
    {
        if ($roles !== []) {
            $this->authenticateRoles($roles);
        }
        $frontendConfiguration = $this->objectManager->get(ConfigurationManager::class)
            ->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.Neos.Ui.frontendConfiguration')['Sandstorm.E2ETestTools:ExportNodeButton'];

        $computed = $this->objectManager->get(ConfigurationRenderingService::class)->computeConfiguration($frontendConfiguration, []);

        self::assertSame($expectedEnabled, $computed['enabled']);
    }

    public static function buttonStates(): iterable
    {
        yield 'anonymous' => [[], false];
        yield 'editor' => [['Neos.Neos:Editor'], false];
        yield 'administrator' => [['Neos.Neos:Administrator'], true];
    }

    #[Test]
    public function editorRequestIsForbidden(): void
    {
        $this->authenticateRoles(['Neos.Neos:Editor']);

        $response = $this->browser->request($this->exportUri(self::UNKNOWN_NODE_ADDRESS));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('does-not-exist', (string)$response->getBody(), 'the request must be denied before the node is looked up');
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
        $this->authenticateRoles(['Neos.Neos:Administrator']);

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
        $this->authenticateRoles(['Neos.Neos:Administrator']);
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
