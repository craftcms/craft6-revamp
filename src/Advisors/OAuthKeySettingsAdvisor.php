<?php

namespace CraftCms\Prepper\Console\Advisors;

use CraftCms\Prepper\Console\NextStepSection;
use CraftCms\Prepper\Console\Project;

/**
 * Suggests replacing Craft’s OAuth key settings with Laravel Passport’s.
 */
class OAuthKeySettingsAdvisor implements Advisor
{
    public function advise(Project $project): void
    {
        if ($this->hasOAuthKeySettings($project)) {
            $project->suggest(NextStepSection::OAuth, 'Replace the <options=bold>privateKey</> and <options=bold>publicKey</> OAuth settings (<options=bold>CRAFT_OAUTH_PRIVATE_KEY</> and <options=bold>CRAFT_OAUTH_PUBLIC_KEY</>) with <options=bold>PASSPORT_PRIVATE_KEY</> and <options=bold>PASSPORT_PUBLIC_KEY</> in each environment, set to the key contents, so existing OAuth tokens keep working');
        }
    }

    private function hasOAuthKeySettings(Project $project): bool
    {
        foreach (['CRAFT_OAUTH_PRIVATE_KEY', 'CRAFT_OAUTH_PUBLIC_KEY'] as $name) {
            if ($project->env($name) !== null) {
                return true;
            }
        }

        $configPath = $project->path('config/craft/oauth.php');

        return file_exists($configPath) && preg_match('/\b(privateKey|publicKey)\b/', file_get_contents($configPath));
    }
}
