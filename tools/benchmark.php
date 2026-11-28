<?php

declare(strict_types=1);

// Small, reproducible local measurement using the CMS's SQLite API fixture, not a production load test.
// Usage: php tools/benchmark.php /path/to/prime-cms
$cms = isset($argv[1]) ? realpath($argv[1]) : false;
if ($cms === false || !is_file($cms . '/tests/Support/ApiTestFixture.php') || !is_file($cms . '/content/plugins/prime-builder/plugin.json')) {
    fwrite(STDERR, "Pass the path to a Prime CMS checkout containing the current plugin copy.\n");
    exit(1);
}
require $cms . '/bootstrap/autoload.php';
require $cms . '/tests/Support/MysqlEmulatingPdo.php';
require $cms . '/tests/Support/ApiTestFixture.php';

use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Kernel\Env;
use Prime\Plugins\AssetPublisher;
use Prime\Plugins\PluginManager;
use Prime\Tests\Support\ApiTestFixture;
use PrimeBuilder\Styles\Compiler;
use PrimeBuilder\Styles\Kit;

final class BenchmarkFixture
{
    use ApiTestFixture;

    protected function assertNotNull(mixed $value): void
    {
        if ($value === null) {
            throw new RuntimeException('Unexpected null fixture value');
        }
    }

    protected function assertInstanceOf(string $type, mixed $value, string $message = ''): void
    {
        if (!$value instanceof $type) {
            throw new RuntimeException('Unexpected fixture type: ' . $message);
        }
    }

    public function run(string $temporary): void
    {
        $app = $this->createApp(null);
        $db = $this->db($app);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE pc_plugins (slug VARCHAR(64) PRIMARY KEY, version VARCHAR(64), status VARCHAR(20), manifest_hash VARCHAR(64), last_error TEXT, errored_at DATETIME, installed_at DATETIME, activated_at DATETIME, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_menus (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(150) NOT NULL, slug VARCHAR(120) NOT NULL, location VARCHAR(80) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $pdo->exec("CREATE TABLE pc_menu_items (id INTEGER PRIMARY KEY AUTOINCREMENT, menu_id INTEGER NOT NULL, parent_id INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, type VARCHAR(20) NOT NULL DEFAULT 'url', target_id INTEGER NULL, url VARCHAR(500) NULL, label VARCHAR(255) NOT NULL DEFAULT '', target VARCHAR(16) NOT NULL DEFAULT '_self', css_class VARCHAR(255) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)");
        $pdo->exec('CREATE TABLE pc_zones (id INTEGER PRIMARY KEY AUTOINCREMENT, theme VARCHAR(80) NOT NULL, slug VARCHAR(80) NOT NULL, content LONGTEXT NOT NULL, content_html LONGTEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $pdo->exec("INSERT INTO pc_entries (id,type,status,title,slug,excerpt,content,content_html,content_text,author_id,published_at,created_at,updated_at) VALUES (4, 'page', 'draft', 'Benchmark', 'atelier-benchmark', '', '{\"version\":1,\"blocks\":[]}', '', '', 1, NULL, '2026-11-20 10:00:00', '2026-11-20 10:00:00')");
        $app->container()->instance(AssetPublisher::class, new AssetPublisher($temporary . '/assets', '/assets/plugins'));
        $app->get(PluginManager::class)->activate('prime-builder');
        $elements = [];
        for ($i = 0; $i < 30; $i++) {
            $type = ['heading', 'text', 'image', 'button', 'posts', 'tabs'][$i % 6];
            $settings = match ($type) {
                'heading' => ['level' => 2, 'text' => 'Titre ' . $i],
                'text' => ['text' => '<p>Paragraphe de mesure.</p>'],
                'image' => ['url' => '/assets/plugins/prime-builder/images/landscape.svg', 'alt' => 'Paysage abstrait', 'width' => 800, 'height' => 600],
                'button' => ['text' => 'Continuer', 'url' => '/'],
                'posts' => ['limit' => 3, 'show_excerpt' => 'show'],
                default => ['label' => 'Informations', 'title_1' => 'Premier', 'text_1' => '<p>Un</p>', 'title_2' => 'Second', 'text_2' => '<p>Deux</p>'],
            };
            $elements[] = ['id' => 'bench_' . $i, 'type' => $type, 'settings' => $settings, 'styles' => ['desktop' => ['padding' => 8], 'mobile' => ['padding' => 4]], 'children' => []];
        }
        $layout = ['version' => 1, 'elements' => $elements];
        $document = ['version' => 1, 'blocks' => [['id' => 'pb_layout', 'type' => 'prime-builder/layout', 'data' => $layout]]];
        $app->get(EntryService::class)->update(4, ['content' => $document, 'status' => 'published'], $this->user($app, 1));
        $db->enableQueryLog();
        foreach (['/atelier-benchmark', '/api/prime-builder/v1/documents/4/live'] as $path) {
            $db->clearQueryLog();
            $start = hrtime(true);
            $response = $app->get(Kernel::class)->handle(Request::create('GET', $path));
            $ms = (hrtime(true) - $start) / 1e6;
            if ($response->status() !== 200) {
                throw new RuntimeException($path . ' returned HTTP ' . $response->status());
            }
            $postQueries = count(array_filter($db->queryLog(), static fn (array $query): bool => str_contains($query['sql'], 'FROM `pc_entries` e WHERE e.type IN')));
            printf("%s status=%d queries=%d post_queries=%d time_ms=%.2f body_bytes=%d\n", $path, $response->status(), count($db->queryLog()), $postQueries, $ms, strlen($response->body()));
        }
        $entry = $app->get(EntryRepository::class)->find(4);
        if ($entry === null) {
            throw new RuntimeException('Benchmark entry not found');
        }
        printf("layout_elements=%d saved_html_bytes=%d document_css_bytes=%d kit_css_bytes=%d\n", count($elements), strlen($entry->contentHtml), strlen((new Compiler())->compile($layout)), strlen(Kit::compile([])));
    }
}

$temporary = sys_get_temp_dir() . '/prime-builder-bench-' . bin2hex(random_bytes(8));
Env::set('PRIME_STORAGE_PATH', $temporary . '/storage', true);
Env::set('PRIME_DISABLE_DOTENV', 'true', true);
try {
    (new BenchmarkFixture())->run($temporary);
} finally {
    AssetPublisher::deleteDirectory($temporary);
}
