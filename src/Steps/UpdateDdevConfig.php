<?php

namespace CraftCms\Prepper\Console\Steps;

use CraftCms\Prepper\Console\NextStepSection;
use CraftCms\Prepper\Console\Project;
use Laravel\Prompts\Support\Logger;

class UpdateDdevConfig implements Step
{
    const PHP_VERSION = '8.5';

    public function label(Project $project): string
    {
        return 'Updating DDEV configuration';
    }

    public function shouldRun(Project $project): bool
    {
        return $project->isDdev();
    }

    public function run(Project $project, Logger $logger): void
    {
        $ddevConfigPath = $project->path('.ddev/config.yaml');
        $ddevConfig = file_get_contents($ddevConfigPath);

        $this->updateProjectType($logger, $ddevConfig);
        $this->updatePhpVersion($logger, $ddevConfig);

        if ($project->renamePublicPath) {
            $this->updateDocroot($logger, $ddevConfig);
        }

        file_put_contents($ddevConfigPath, $ddevConfig);
        $logger->success('.ddev/config.yaml updated');

        $project->suggest(NextStepSection::Ddev, 'Run <options=bold>ddev restart</> to pick up the new project type and configuration');
    }

    private function updateProjectType(Logger $logger, string &$ddevConfig): void
    {
        if (! preg_match('/^type:\s+[\'"]?([\w\d]+)[\'"]?\s*$/m', $ddevConfig, $match, PREG_OFFSET_CAPTURE)) {
            $logger->warning('No project type detected in .ddev/config.yaml.');

            return;
        }

        if ($match[1][0] === 'laravel') {
            $logger->success('Project type already set to Laravel.');

            return;
        }

        // use str_replace() instead of symfony/yaml so we don't lose the comments
        $ddevConfig = substr($ddevConfig, 0, $match[0][1]).
            'type: laravel'.
            substr($ddevConfig, $match[0][1] + strlen($match[0][0]));
        $logger->success('Project type updated.');
    }

    private function updatePhpVersion(Logger $logger, string &$ddevConfig): void
    {
        if (! preg_match('/^php_version:\s+[\'"]?([\d\.]+)[\'"]?\s*$/m', $ddevConfig, $match, PREG_OFFSET_CAPTURE)) {
            $logger->warning('No php_version detected in .ddev/config.yaml.');

            return;
        }

        if ($match[1][0] === self::PHP_VERSION) {
            $logger->success('PHP version already set to '.self::PHP_VERSION.'.');

            return;
        }

        // use str_replace() instead of symfony/yaml so we don't lose the comments
        $ddevConfig = substr($ddevConfig, 0, $match[0][1]).
            sprintf('php_version: "%s"', self::PHP_VERSION).
            substr($ddevConfig, $match[0][1] + strlen($match[0][0]));

        $logger->success('PHP version updated to '.self::PHP_VERSION.'.');
    }

    private function updateDocroot(Logger $logger, string &$ddevConfig): void
    {
        if (! preg_match('/^docroot:\s+[\'"]?([\w+\/]+)[\'"]?\s*$/m', $ddevConfig, $match, PREG_OFFSET_CAPTURE)) {
            $logger->warning('No docroot detected in .ddev/config.yaml.');

            return;
        }

        // use str_replace() instead of symfony/yaml so we don't lose the comments
        $ddevConfig = substr($ddevConfig, 0, $match[0][1]).
            'docroot: public'.
            substr($ddevConfig, $match[0][1] + strlen($match[0][0]));

        $logger->success('Docroot updated in .ddev/config.yaml.');
    }
}
