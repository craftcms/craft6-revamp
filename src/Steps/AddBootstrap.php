<?php

namespace CraftCms\Prepper\Console\Steps;

use CraftCms\Prepper\Console\Project;
use Laravel\Prompts\Support\Logger;

class AddBootstrap implements Step
{
    public function label(Project $project): string
    {
        return 'Creating Laravel bootstrap files';
    }

    public function shouldRun(Project $project): bool
    {
        return ! file_exists($project->path('bootstrap/app.php')) || ! file_exists($project->path('bootstrap/cache/.gitignore'));
    }

    public function run(Project $project, Logger $logger): void
    {
        foreach (['bootstrap', 'bootstrap/cache'] as $dir) {
            if (! file_exists($project->path($dir))) {
                mkdir($project->path($dir), recursive: true);
            }
        }

        if (! file_exists($project->path('bootstrap/cache/.gitignore'))) {
            file_put_contents($project->path('bootstrap/cache/.gitignore'), "*\n!.gitignore");
        }

        $appPath = $project->path('bootstrap/app.php');

        if (file_exists($appPath)) {
            $logger->success('bootstrap/app.php already exists.');

            return;
        }

        $contents = <<<PHP
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        health: '/up',
    )
    ->withMiddleware(function (Middleware \$middleware): void {
        //
    })
    ->withExceptions(function (Exceptions \$exceptions): void {
        //
    })

PHP;
        $publicPath = $project->targetPublicPath();
        if ($publicPath !== 'public') {
            $contents .= <<<PHP
    ->booted(function (Application \$app) {
        \$app->usePublicPath(base_path('$publicPath'));
    })

PHP;
        }

        $contents .= <<<'PHP'
    ->create();

PHP;

        file_put_contents($appPath, $contents);

        $logger->success('Laravel Bootstrap created.');
    }
}
