<?php

declare(strict_types=1);

namespace QueryBuilder\Command;

use QueryBuilder\Query\RuntimeContext;
use QueryBuilder\Service\ProductSelector;
use QueryBuilder\Service\RuntimeContextFactory;
use QueryBuilder\Service\SqlBuilder;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thelia\Command\ContainerAwareCommand;

/**
 * Debug command: compiles a react-querybuilder JSON tree into SQL and
 * optionally executes it.
 */
class CompileCommand extends ContainerAwareCommand
{
    public function __construct(
        private readonly SqlBuilder $sqlBuilder,
        private readonly RuntimeContextFactory $runtimeContextFactory,
        private readonly ProductSelector $productSelector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('querybuilder:compile')
            ->setDescription('Compile a react-querybuilder JSON condition tree into SQL')
            ->addArgument('tree', InputArgument::REQUIRED, 'JSON condition tree, or @/path/to/file.json')
            ->addOption('customer', null, InputOption::VALUE_REQUIRED, 'Customer id for runtime placeholders')
            ->addOption('product', null, InputOption::VALUE_REQUIRED, 'Product id for runtime placeholders')
            ->addOption('cart-products', null, InputOption::VALUE_REQUIRED, 'Comma-separated product ids of the cart')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale', 'fr_FR')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'LIMIT applied to the query')
            ->addOption('ordered', null, InputOption::VALUE_NONE, 'Apply the product ranking (order providers, then product id), without the promoted ids, the rotation nor the family mixing of the selections')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Execute the query and print the product ids');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawTree = (string) $input->getArgument('tree');

        if (str_starts_with($rawTree, '@')) {
            $rawTree = (string) file_get_contents(substr($rawTree, 1));
        }

        $conditionTree = json_decode($rawTree, true);

        if (!\is_array($conditionTree)) {
            $output->writeln('<error>Invalid JSON condition tree.</error>');

            return self::FAILURE;
        }

        $cartProducts = (string) $input->getOption('cart-products');

        $runtimeContext = $this->runtimeContextFactory->withProviderParameters(new RuntimeContext(
            customerId: $input->getOption('customer') !== null ? (int) $input->getOption('customer') : null,
            productId: $input->getOption('product') !== null ? (int) $input->getOption('product') : null,
            cartProductIds: $cartProducts !== ''
                ? array_map('intval', explode(',', $cartProducts))
                : [],
            locale: (string) $input->getOption('locale'),
        ));

        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $orderBy = $input->getOption('ordered') ? $this->productSelector->getOrderBy($runtimeContext) : [];

        $compiledQuery = $this->sqlBuilder->compile($conditionTree, $runtimeContext, $limit, [], $orderBy);

        $output->writeln('<info>SQL</info>');
        $output->writeln($compiledQuery->sql);
        $output->writeln('');
        $output->writeln('<info>Parameters</info>');
        $output->writeln((string) json_encode($compiledQuery->parameters, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

        if ($input->getOption('execute')) {
            $productIds = $this->sqlBuilder->getProductIds($conditionTree, $runtimeContext, $limit, [], $orderBy);

            $output->writeln('');
            $output->writeln(sprintf('<info>%d product(s)</info>', \count($productIds)));
            $output->writeln(implode(', ', \array_slice($productIds, 0, 50)));
        }

        return self::SUCCESS;
    }
}
