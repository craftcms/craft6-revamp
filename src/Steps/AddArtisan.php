<?php

namespace CraftCms\Revamp\Steps;

use CraftCms\Revamp\Project;
use Laravel\Prompts\Support\Logger;

class AddArtisan implements Step
{
    public function label(Project $project): string
    {
        return 'Creating the artisan executable';
    }

    public function shouldRun(Project $project): bool
    {
        return ! file_exists($project->path('artisan'));
    }

    public function run(Project $project, Logger $logger): void
    {
        $contents = <<<PHP
#!/usr/bin/env php
<?php

use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

// Register the Composer autoloader...
require __DIR__.'/vendor/autoload.php';

// Bootstrap Laravel and handle the command...
/** @var Application \$app */
\$app = require_once __DIR__.'/bootstrap/app.php';

\$status = \$app->handleCommand(new ArgvInput);

exit(\$status);

PHP;

        file_put_contents($project->path('artisan'), $contents);
        @chmod($project->path('artisan'), 0755);

        $logger->success('Added Artisan executable.');
    }
}
