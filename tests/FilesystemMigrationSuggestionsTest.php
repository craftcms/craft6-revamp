<?php

namespace CraftCms\Prepper\Console\Tests;

use CraftCms\Prepper\Console\FilesystemMigrationSuggestions;
use PHPUnit\Framework\TestCase;

class FilesystemMigrationSuggestionsTest extends TestCase
{
    private string $projectConfigPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectConfigPath = tempnam(sys_get_temp_dir(), 'craft-project-config-');
        file_put_contents(
            $this->projectConfigPath,
            <<<'YAML'
fs:
  s3Assets:
    type: craft\awss3\Fs
    hasUrls: true
    url: 'https://cdn.example.com'
    settings:
      keyId: $AWS_ACCESS_KEY_ID
      secret: $AWS_SECRET_ACCESS_KEY
      region: us-east-2
      bucket: craft-assets
      subfolder: uploads
      makeUploadsPublic: false
  spacesAssets:
    type: vaersaagod\dospaces\Fs
    settings:
      keyId: literal-key
      secret: literal-secret
      region: fra1
      bucket: craft-spaces
      endpoint: 'https://fra1.digitaloceanspaces.com'
  googleAssets:
    type: craft\googlecloud\Fs
    settings:
      projectId: $GOOGLE_CLOUD_PROJECT
      keyFileContents: $GOOGLE_CLOUD_KEY_FILE
      bucket: google-assets
      visibility: private
  azureAssets:
    type: craft\azureblob\Fs
    settings:
      connectionString: inline-connection-string
      container: craft-assets
      subfolder: media
  r2Assets:
    type: jrrdnx\cloudflarer2\Fs
    settings:
      accountId: $R2_ACCOUNT_ID
      keyId: $R2_ACCESS_KEY_ID
      secret: $R2_SECRET_ACCESS_KEY
      bucketSelectionMode: manual
      manualBucket: craft-r2
  linodeAssets:
    type: mwikala\linodes3\Fs
    settings:
      keyId: $LINODE_ACCESS_KEY_ID
      secret: $LINODE_SECRET_ACCESS_KEY
      region: eu-central-1
      bucket: craft-linode
      endpoint: 'https://eu-central-1.linodeobjects.com'
YAML,
        );
    }

    protected function tearDown(): void
    {
        unlink($this->projectConfigPath);

        parent::tearDown();
    }

    public function test_it_suggests_laravel_disks_for_supported_filesystems(): void
    {
        $suggestions = implode("\n\n", (new FilesystemMigrationSuggestions)->get($this->projectConfigPath));

        self::assertStringContainsString("'s3Assets' => [", $suggestions);
        self::assertStringContainsString("'key' => env('AWS_ACCESS_KEY_ID'),", $suggestions);
        self::assertStringContainsString("'visibility' => 'private',", $suggestions);
        self::assertStringContainsString("'spacesAssets' => [", $suggestions);
        self::assertStringContainsString("'key' => env('SPACES_ASSETS_KEY_ID'),", $suggestions);
        self::assertStringContainsString('SPACES_ASSETS_KEY_ID', $suggestions);
        self::assertStringContainsString('SPACES_ASSETS_SECRET', $suggestions);
        self::assertStringNotContainsString('literal-secret', $suggestions);
        self::assertStringContainsString("'googleAssets' => [", $suggestions);
        self::assertStringContainsString('composer require spatie/laravel-google-cloud-storage', $suggestions);
        self::assertStringContainsString("'key_file' => json_decode((string) env('GOOGLE_CLOUD_KEY_FILE'), true),", $suggestions);
        self::assertStringContainsString("'azureAssets' => [", $suggestions);
        self::assertStringContainsString('composer require azure-oss/storage-blob-laravel', $suggestions);
        self::assertStringContainsString("'driver' => 'azure-storage-blob',", $suggestions);
        self::assertStringContainsString("'connection_string' => env('AZURE_ASSETS_CONNECTION_STRING'),", $suggestions);
        self::assertStringContainsString('AZURE_ASSETS_CONNECTION_STRING', $suggestions);
        self::assertStringNotContainsString('inline-connection-string', $suggestions);
        self::assertStringContainsString("'r2Assets' => [", $suggestions);
        self::assertStringContainsString("'driver' => 'r2',", $suggestions);
        self::assertStringContainsString("'endpoint' => sprintf('https://%s.r2.cloudflarestorage.com', env('R2_ACCOUNT_ID')),", $suggestions);
        self::assertStringContainsString("'bucket' => 'craft-r2',", $suggestions);
        self::assertStringContainsString("'linodeAssets' => [", $suggestions);
        self::assertStringContainsString("'endpoint' => 'https://eu-central-1.linodeobjects.com',", $suggestions);
    }

    public function test_it_resolves_craft_aliases_and_environment_variables(): void
    {
        file_put_contents($this->projectConfigPath, <<<'YAML'
fs:
  uploads:
    type: craft\fs\Local
    hasUrls: true
    url: '@web/uploads/'
    settings:
      path: '@webroot/uploads'
  private:
    type: craft\fs\Local
    settings:
      path: '$STORAGE_ROOT/private'
  custom:
    type: craft\fs\Local
    hasUrls: true
    url: '${ASSETS_HOST}/custom'
    settings:
      path: '@assetBasePath/custom'
  s3:
    type: craft\awss3\Fs
    settings:
      bucket: $S3_BUCKET
      subfolder: '${S3_PREFIX}/assets'
YAML);

        $suggestions = (new FilesystemMigrationSuggestions)->get($this->projectConfigPath);

        self::assertSame(<<<'PHP'
'uploads' => [
    'driver' => 'local',
    'root' => public_path('uploads'),
    'url' => env('APP_URL') . '/uploads/',
],
PHP, $suggestions[0]);
        self::assertStringContainsString("'root' => env('STORAGE_ROOT') . '/private',", $suggestions[1]);
        self::assertStringContainsString("'root' => '@assetBasePath/custom',", $suggestions[2]);
        self::assertStringContainsString("'url' => env('ASSETS_HOST') . '/custom',", $suggestions[2]);
        self::assertStringContainsString('Replace <options=bold>@assetBasePath</> with a real path or URL', $suggestions[2]);
        self::assertStringContainsString("'bucket' => env('S3_BUCKET'),", $suggestions[3]);
        self::assertStringContainsString("'root' => env('S3_PREFIX') . '/assets',", $suggestions[3]);

        // A web root that isn't renamed to public/ needs base_path(), since public_path() isn't updated until the app boots
        $suggestions = (new FilesystemMigrationSuggestions)->get($this->projectConfigPath, 'web');
        self::assertStringContainsString("'root' => base_path('web/uploads'),", $suggestions[0]);
    }
}
