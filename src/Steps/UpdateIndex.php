<?php

namespace CraftCms\Prepper\Console\Steps;

use CraftCms\Prepper\Console\Project;
use Laravel\Prompts\Support\Logger;

class UpdateIndex implements Step
{
    public function label(Project $project): string
    {
        return 'Updating index.php';
    }

    public function shouldRun(Project $project): bool
    {
        return true;
    }

    public function run(Project $project, Logger $logger): void
    {
        $contents = <<<PHP
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists(\$maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require \$maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application \$app */
\$app = require_once __DIR__.'/../bootstrap/app.php';

\$app->handleRequest(Request::capture());

PHP;

        file_put_contents($project->path("{$project->publicPath}/index.php"), $contents);

        $logger->success("/{$project->publicPath}/index.php updated");
    }
}
