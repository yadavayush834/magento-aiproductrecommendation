<?php
namespace Custom\AiProductRecommendation\Console\Command;

use Custom\AiProductRecommendation\Model\OpenSearchClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SetupIndexCommand extends Command
{
    private OpenSearchClient $openSearchClient;

    public function __construct(OpenSearchClient $openSearchClient)
    {
        $this->openSearchClient = $openSearchClient;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('ai:recommendation:setup')
            ->setDescription('Create the OpenSearch k-NN index for AI product recommendations');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->openSearchClient->indexExists()) {
            $output->writeln('<comment>Index already exists — nothing to do.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln('Creating OpenSearch k-NN index...');
        $success = $this->openSearchClient->createIndex();

        if (!$success) {
            $output->writeln('<error>Failed to create the index. Check var/log/system.log for details.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Index created successfully.</info>');
        $output->writeln('Next: run "bin/magento ai:recommendation:index"');

        return Command::SUCCESS;
    }
}