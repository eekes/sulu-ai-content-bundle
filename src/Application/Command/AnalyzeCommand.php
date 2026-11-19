<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Command;

use Eekes\Sulu\AiContentBundle\Application\Catalog\CatalogAnalyzer;
use Eekes\Sulu\AiContentBundle\Application\Catalog\CatalogFile;
use Eekes\Sulu\AiContentBundle\Application\Model\ModelRegistry;
use Eekes\Sulu\AiContentBundle\Domain\Repository\WebspaceContextRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'eekes:ai-content:analyze',
    description: 'Describes the templates and blocks of this project for the AI assistant, in a YAML file you can fine-tune',
)]
final class AnalyzeCommand extends Command
{
    /**
     * @param list<string> $formKeys
     */
    public function __construct(
        private readonly CatalogAnalyzer $analyzer,
        private readonly CatalogFile $catalogFile,
        private readonly ModelRegistry $modelRegistry,
        private readonly WebspaceContextRepository $webspaceContextRepository,
        private readonly array $formKeys,
        private readonly string $defaultLocale,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'The locale whose field labels are read', $this->defaultLocale)
            ->addOption('webspace', null, InputOption::VALUE_REQUIRED, 'Read the templates of this webspace, and its AI context for what the website is')
            ->addOption('form', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these forms (page, article, snippet)')
            ->addOption('no-ai', null, InputOption::VALUE_NONE, 'Only add what is new, with empty descriptions to write yourself')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Describe everything again, also text you edited (excluded fields are kept)')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Remove templates and blocks that no longer exist')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without calling the model or writing the file')
            ->setHelp(<<<'HELP'
                Reads every template and block through Sulu's form metadata and asks the model what each one
                is for. The result goes to the catalog file, which the assistant reads on every request:

                    <info>%command.full_name%</info>

                Then open the file and fine-tune it. Running the command again only describes what is new or
                changed, and never overwrites a text you edited unless you pass <info>--force</info>.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $describe = !$input->getOption('no-ai');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($describe && !$dryRun && !$this->modelRegistry->isConfigured()) {
            $io->error('No model is configured under "eekes_sulu_ai_content.models". Configure one, or run with --no-ai.');

            return Command::FAILURE;
        }

        $locale = $input->getOption('locale');
        $locale = \is_string($locale) && $locale !== '' ? $locale : $this->defaultLocale;
        $webspace = \is_string($input->getOption('webspace')) ? $input->getOption('webspace') : null;
        /** @var list<string> $forms */
        $forms = $input->getOption('form');
        $forms = $forms === [] ? $this->formKeys : $forms;
        $siteDescription = $webspace === null ? null : $this->webspaceContextRepository->findOneByWebspace($webspace, $locale)?->siteDescription;

        $io->title('Analyzing templates and blocks');
        $io->text(\sprintf('Catalog: <info>%s</info>', $this->catalogFile->getPath()));

        if ($describe && !$dryRun) {
            $io->text(\sprintf('Model: <info>%s</info>', $this->modelRegistry->getForCatalog()->model));
        }

        $io->newLine();

        $report = $this->analyzer->analyze(
            $forms,
            $locale,
            $webspace,
            $describe,
            (bool) $input->getOption('force'),
            (bool) $input->getOption('prune'),
            $siteDescription,
            static function (string $kind, string $key, string $status, ?string $detail) use ($io): void {
                $io->writeln(\sprintf('  %-8s %-40s %s%s', $kind, $key, self::status($status), $detail === null ? '' : ' <comment>('.$detail.')</comment>'));
            },
            $dryRun,
        );

        $counts = array_count_values(array_column($report, 'status'));
        $io->newLine();

        if (($counts[CatalogAnalyzer::FAILED] ?? 0) > 0) {
            $io->warning(\sprintf('%d descriptions failed. Run the command again to retry them.', $counts[CatalogAnalyzer::FAILED]));
        }

        if ($dryRun) {
            $io->note('Dry run: nothing was written.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('The catalog is up to date. Fine-tune it in %s.', $this->catalogFile->getPath()));

        return ($counts[CatalogAnalyzer::FAILED] ?? 0) > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private static function status(string $status): string
    {
        return match ($status) {
            CatalogAnalyzer::DESCRIBED, CatalogAnalyzer::REDESCRIBED, CatalogAnalyzer::ADDED => '<info>'.$status.'</info>',
            CatalogAnalyzer::FAILED => '<error>'.$status.'</error>',
            CatalogAnalyzer::KEPT_EDITED, CatalogAnalyzer::STALE, CatalogAnalyzer::REMOVED => '<comment>'.$status.'</comment>',
            default => $status,
        };
    }
}
