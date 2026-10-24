<?php

declare(strict_types=1);

namespace Packeton\Tests\Unit\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Controller\WebController;
use Packeton\Entity\Package;
use Packeton\Form\Model\SearchQuery;
use Packeton\Repository\PackageRepository;
use Packeton\Service\SubRepositoryHelper;
use Pagerfanta\Pagerfanta;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WebControllerSearchPaginationTest extends TestCase
{
    public function testSearchUsesRequestedPageAndConfiguredPageSize(): void
    {
        $repository = new InMemoryPackageRepository();
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getRepository')->with(Package::class)->willReturn($repository);

        $subRepositoryHelper = $this->getMockBuilder(SubRepositoryHelper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['allowedPackageIds'])
            ->getMock();
        $subRepositoryHelper->method('allowedPackageIds')->willReturn(null);

        $searchQuery = new SearchQuery();
        $searchQuery->setQuery('package');
        $form = $this->createMock(FormInterface::class);
        $form->method('handleRequest')->willReturn($form);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('getData')->willReturn($searchQuery);

        $controller = new TestableWebController($registry, $subRepositoryHelper, $form);
        $controller->searchAction(Request::create('/search.html', 'GET', ['page' => 2, 'search_query' => ['query' => 'package']]));

        self::assertNotNull($controller->renderedPaginator);
        self::assertSame(2, $controller->renderedPaginator->getCurrentPage());
        self::assertSame(15, $controller->renderedPaginator->getMaxPerPage());
        self::assertSame(range(16, 30), iterator_to_array($controller->renderedPaginator, false));
        self::assertSame([['query' => 'package', 'limit' => 15, 'page' => 1]], $repository->searchCalls);
        self::assertSame([null], $repository->countCalls);
    }
}

final class InMemoryPackageRepository extends PackageRepository
{
    public array $searchCalls = [];
    public array $countCalls = [];

    public function __construct()
    {
    }

    public function countPackageNames(?array $allowed = null): int
    {
        $this->countCalls[] = $allowed;

        return 30;
    }

    public function searchPackage(string $search, int $limit = 10, int $page = 0, ?array $allowed = null): array
    {
        $this->searchCalls[] = ['query' => $search, 'limit' => $limit, 'page' => $page];

        return array_slice(range(1, 30), $page * $limit, $limit);
    }
}

final class TestableWebController extends WebController
{
    public ?Pagerfanta $renderedPaginator = null;

    public function __construct(
        ManagerRegistry $registry,
        SubRepositoryHelper $subRepositoryHelper,
        private FormInterface $form,
    ) {
        parent::__construct($registry, $subRepositoryHelper);
    }

    protected function createForm(string $type, mixed $data = null, array $options = []): FormInterface
    {
        return $this->form;
    }

    protected function isGranted(mixed $attribute, mixed $subject = null): bool
    {
        return true;
    }

    protected function render(string $view, array $parameters = [], ?Response $response = null): Response
    {
        $this->renderedPaginator = $parameters['packages'];

        return $response ?? new Response();
    }
}
