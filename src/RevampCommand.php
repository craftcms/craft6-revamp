<?php

namespace CraftCms\Revamp;

use CraftCms\Revamp\Advisors\Advisor;
use CraftCms\Revamp\Advisors\CraftConfigAdvisor;
use CraftCms\Revamp\Advisors\FilesystemDisksAdvisor;
use CraftCms\Revamp\Advisors\FinalCommandsAdvisor;
use CraftCms\Revamp\Advisors\MailerAdvisor;
use CraftCms\Revamp\Advisors\OAuthKeySettingsAdvisor;
use CraftCms\Revamp\Checks\Check;
use CraftCms\Revamp\Checks\CraftVersionCheck;
use CraftCms\Revamp\Checks\EntrypointsCheck;
use CraftCms\Revamp\Checks\GitStatusCheck;
use CraftCms\Revamp\Checks\LegacyElementGroupsCheck;
use CraftCms\Revamp\Checks\PartialRunCheck;
use CraftCms\Revamp\Checks\PluginCompatibilityCheck;
use CraftCms\Revamp\Checks\PublicPathCheck;
use CraftCms\Revamp\Steps;
use CraftCms\Revamp\Steps\Step;
use CraftCms\Revamp\Support\Text;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Support\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

use function Laravel\Prompts\info;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\task;
use function Laravel\Prompts\title;

class RevampCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('revamp')
            ->setDescription('Prepares a Craft 5 project for Craft 6')
            ->addArgument('path', InputArgument::OPTIONAL);
    }

    /**
     * Run in order before anything changes. Any of them may cancel the upgrade.
     *
     * @return Check[]
     */
    protected function checks(): array
    {
        return [
            new CraftVersionCheck,
            new GitStatusCheck,
            new PartialRunCheck,
            new PluginCompatibilityCheck,
            new LegacyElementGroupsCheck,
            new PublicPathCheck,
            new EntrypointsCheck,
        ];
    }

    /**
     * @return Step[]
     */
    protected function steps(): array
    {
        return [
            new Steps\UpdateComposer,
            new Steps\UpdateDdevConfig,
            new Steps\MoveOAuthKeys,
            new Steps\UpdateEnvVars,
            new Steps\AddArtisan,
            new Steps\AddBootstrap,
            new Steps\AddFrameworkFolders,
            new Steps\MoveConfigDirectory,
            new Steps\PublishLaravelConfig,
            new Steps\UpdateGeneralConfig,
            new Steps\RenameTranslations,
            new Steps\MoveTemplates,
            new Steps\RenamePublicPath,
            new Steps\UpdateIndex,
            new Steps\RemoveCraftExecutable,
            new Steps\RemoveOldBootstrap,
            new Steps\UpdateGitignore,
        ];
    }

    /**
     * @return Advisor[]
     */
    protected function advisors(): array
    {
        return [
            new CraftConfigAdvisor,
            new MailerAdvisor,
            new OAuthKeySettingsAdvisor,
            new FilesystemDisksAdvisor,
            new FinalCommandsAdvisor,
        ];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Prompt::setOutput($output);

        title('Craft 6 Revamp');

        $project = new Project(str_replace('\\', '/', $input->getArgument('path') ?? getcwd()));

        foreach ($this->checks() as $check) {
            if (! $check->passes($project)) {
                return self::FAILURE;
            }
        }

        foreach ($this->steps() as $step) {
            if (! $step->shouldRun($project)) {
                // Rendered like a finished task, since nothing needing to change is a success
                $output->writeln(sprintf(' <fg=green>✔</> %s... <fg=gray>Nothing to do</>', $step->label($project)));

                continue;
            }

            task(
                $step->label($project).'...',
                fn (Logger $logger) => $step->run($project, $logger),
                keepSummary: true,
            );
        }

        outro('Finished preparing your project for Craft 6!');

        foreach ($this->advisors() as $advisor) {
            $advisor->advise($project);
        }

        $nextSteps = $project->nextSteps();

        // Leave room for the table’s indent, borders, padding, and the # column
        $width = max(40, (new Terminal)->getWidth() - 8 - strlen((string) count($nextSteps)));

        info('Next steps');
        table(
            ['#', 'Step'],
            collect($nextSteps)
                ->map(fn (string $step, int $index) => [$index + 1, Text::wrap($step, $width)])
                ->all(),
        );

        return self::SUCCESS;
    }
}
