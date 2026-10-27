<?php

namespace Tests\Feature;

use App\Install\WebInstaller;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Installing without a terminal: the page that checks the server and
 * writes .env, the finishing step, and uploaded files served by Booklet
 * where the host doesn't allow the storage link.
 */
class InstallTest extends TestCase
{
    use RefreshDatabase;

    protected string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/install-'.uniqid());
        foreach (['storage/framework', 'storage/logs', 'bootstrap/cache', 'database'] as $dir) {
            File::ensureDirectoryExists($this->base.'/'.$dir);
        }
        file_put_contents($this->base.'/.env.example', "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost\n\nDB_CONNECTION=sqlite\n# DB_HOST=127.0.0.1\n# DB_PASSWORD=\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        @unlink(base_path(WebInstaller::TOKEN_FILE));
        parent::tearDown();
    }

    protected function server(array $more = []): array
    {
        return $more + ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'kopi.example', 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/', 'HTTPS' => 'on'];
    }

    public function test_the_page_shows_the_checks_and_installs_with_a_database_file(): void
    {
        $installer = new WebInstaller($this->base);
        $this->assertFileDoesNotExist($this->base.'/.env');

        $page = $installer->handle($this->server(), []);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Install Booklet', $page['body']);
        $this->assertStringContainsString('https://kopi.example', $page['body']);
        $this->assertStringContainsString('https://kopi.example/booklet/admin.css', $page['body']);
        $this->assertTrue($installer->ready($installer->checks()), 'this server has what Booklet needs: '.json_encode(array_values(array_filter($installer->checks(), fn ($c) => ! $c['ok'] && $c['needed']))));

        $done = $installer->handle($this->server(['REQUEST_METHOD' => 'POST']), ['action' => 'install', 'driver' => 'sqlite']);
        $this->assertSame(303, $done['status']);
        $this->assertMatchesRegularExpression('#^https://kopi\.example/install/finish\?token=[0-9a-f]{40}$#', $done['headers']['Location']);

        $env = Dotenv::parse((string) file_get_contents($this->base.'/.env'));
        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('https://kopi.example', $env['APP_URL']);
        $this->assertSame('sqlite', $env['DB_CONNECTION']);
        $this->assertSame(32, strlen(base64_decode(substr($env['APP_KEY'], 7))));
        $this->assertFileExists($this->base.'/database/database.sqlite');
        $token = substr($done['headers']['Location'], strrpos($done['headers']['Location'], '=') + 1);
        $this->assertSame(hash('sha256', $token), json_decode((string) file_get_contents($this->base.'/'.WebInstaller::TOKEN_FILE), true)['token']);

        // Never again: once .env is there, the page isn't reached (and installing refuses).
        $again = $installer->handle($this->server(['REQUEST_METHOD' => 'POST']), ['action' => 'install', 'driver' => 'sqlite']);
        $this->assertStringContainsString('already installed', $again['body']);
    }

    public function test_mysql_details_are_asked_for_and_problems_said_plainly(): void
    {
        $installer = new WebInstaller($this->base);
        if (! in_array('mysql', $installer->drivers(), true)) {
            $this->markTestSkipped('No MySQL driver in this PHP.');
        }
        $page = $installer->handle($this->server(['REQUEST_METHOD' => 'POST']), ['action' => 'install', 'driver' => 'mysql', 'host' => 'localhost', 'database' => '']);
        $this->assertStringContainsString('Fill in the database’s name', html_entity_decode($page['body'], ENT_QUOTES));
        $this->assertFileDoesNotExist($this->base.'/.env');

        $page = $installer->handle($this->server(['REQUEST_METHOD' => 'POST']), ['action' => 'install', 'driver' => 'mysql', 'host' => 'localhost', 'database' => 'bad name;', 'username' => 'u']);
        $this->assertStringContainsString('letters, numbers and _ only', $page['body']);
        $this->assertFileDoesNotExist($this->base.'/.env');
    }

    public function test_settings_with_odd_characters_are_read_back_exactly(): void
    {
        foreach (['plain-pass', 'with space', "it's \$HOME", 'p@ss w#rd"x', '${APP_KEY}', 'back\\slash'] as $value) {
            $env = WebInstaller::withValues("DB_PASSWORD=\n", ['DB_PASSWORD' => $value]);
            $this->assertSame($value, Dotenv::parse($env)['DB_PASSWORD'], 'read back: '.$value);
        }
        // A commented-out line is used; a missing one is added.
        $env = WebInstaller::withValues("# DB_HOST=127.0.0.1\n", ['DB_HOST' => 'localhost', 'DB_PORT' => '3306']);
        $this->assertSame(['DB_HOST' => 'localhost', 'DB_PORT' => '3306'], Dotenv::parse($env));
    }

    public function test_the_site_address_is_worked_out_however_the_host_is_set_up(): void
    {
        $installer = new WebInstaller($this->base);
        // The domain points at public/.
        $this->assertSame('https://kopi.example', $installer->siteUrl($this->server()));
        // Uploaded into public_html, opened at /public/.
        $this->assertSame('http://kopi.example/public', $installer->siteUrl(['HTTP_HOST' => 'kopi.example', 'SCRIPT_NAME' => '/public/index.php', 'REQUEST_URI' => '/public/']));
        // Uploaded into public_html, everything sent to public/ by Booklet's .htaccess.
        $this->assertSame('http://kopi.example', $installer->siteUrl(['HTTP_HOST' => 'kopi.example', 'SCRIPT_NAME' => '/public/index.php', 'REQUEST_URI' => '/']));
        // In a folder of its own, behind a proxy that speaks https.
        $this->assertSame('https://kopi.example/blog', $installer->siteUrl(['HTTP_HOST' => 'kopi.example', 'HTTP_X_FORWARDED_PROTO' => 'https', 'SCRIPT_NAME' => '/blog/public/index.php', 'REQUEST_URI' => '/blog/']));
        // A made-up host can't sneak markup in.
        $this->assertSame('http://kopi.examplescript', $installer->siteUrl(['HTTP_HOST' => 'kopi.example"><script>', 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/']));
    }

    public function test_finishing_makes_the_tables_once_then_hands_over_to_first_setup(): void
    {
        $token = bin2hex(random_bytes(20));
        File::ensureDirectoryExists(dirname(base_path(WebInstaller::TOKEN_FILE)));
        file_put_contents(base_path(WebInstaller::TOKEN_FILE), json_encode(['token' => hash('sha256', $token), 'at' => time()]));

        $this->get('/install/finish?token=wrong')->assertNotFound();
        $this->get('/install/finish?token='.$token)->assertRedirect(route('setup'));
        $this->assertFileDoesNotExist(base_path(WebInstaller::TOKEN_FILE));
        $this->get('/install/finish?token='.$token)->assertNotFound();

        // An old code doesn't work.
        file_put_contents(base_path(WebInstaller::TOKEN_FILE), json_encode(['token' => hash('sha256', $token), 'at' => time() - 7200]));
        $this->get('/install/finish?token='.$token)->assertNotFound();
    }

    public function test_uploaded_files_are_served_where_the_storage_link_isnt_allowed(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/kopi.txt', 'Kopi');
        Storage::disk('public')->put('media/drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->get('/storage/media/kopi.txt')->assertOk();
        $this->get('/storage/media/drawing.svg')->assertOk()->assertHeader('Content-Security-Policy');
        $this->get('/storage/media/missing.txt')->assertNotFound();
        $this->get('/storage/media/../../.env')->assertNotFound();
    }
}
