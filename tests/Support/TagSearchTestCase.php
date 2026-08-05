<?php
declare(strict_types=1);

namespace TagSearch\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * DBとキャッシュ領域を必要とするテストの基底クラス
 */
abstract class TagSearchTestCase extends TestCase {
    /** テスト用のDB接続 */
    protected \PDO $pdo;

    /** キャッシュの出力先 */
    protected string $cacheDir;

    /** PDOを返すコールバック */
    protected \Closure $pdoFactory;

    protected function setUp(): void {
        // テスト用のDBに接続してテーブルを生成する
        $this->pdo = TestDatabase::connect();
        TestDatabase::migrate($this->pdo);

        $pdo = $this->pdo;
        $this->pdoFactory = static fn (): \PDO => $pdo;

        // キャッシュ用のフォルダを生成する
        $this->cacheDir = sys_get_temp_dir() . '/tag-search-test-' . bin2hex(random_bytes(8));
        if (!mkdir($this->cacheDir, 0777, true)) {
            throw new \RuntimeException("{$this->cacheDir}の作成に失敗しました");
        }
    }

    protected function tearDown(): void {
        // キャッシュの保存先の削除
        // テーブルはTestDatabase::migrateで作り直すため対処不要
        $this->removeDirectory($this->cacheDir);
    }

    /**
     * フィクスチャの投入
     */
    protected function loadFixture(): void {
        ArticleFixture::load($this->pdo);
    }

    /**
     * クエリの発行のためのオブジェクトの取得
     */
    protected function getQuery(): \Query {
        return \TagSearch::getQuery($this->cacheDir, $this->pdoFactory);
    }

    /**
     * キャッシュを扱うオブジェクトの取得
     */
    protected function newCacheTable(): \TagSearchCaches {
        return new \TagSearchCaches($this->cacheDir, $this->pdoFactory);
    }

    /**
     * 検索の実行
     * @return array{id-list: array<string>, count: int}
     */
    protected function search(string $queryText, int $page = 1, int $order = \TagSearch::DESC_POSTDATE): array {
        return \TagSearch::getResult($queryText, $page, $order, $this->cacheDir, $this->pdoFactory);
    }

    /**
     * タグ数の上限を設けずに検索を実行
     * @return array{id-list: array<string>, count: int}
     */
    protected function searchWithoutTagLimit(string $queryText, int $page = 1, int $order = \TagSearch::DESC_POSTDATE): array {
        return $this->getQuery()->get(new \BuildQueryOfTagSearch($queryText, -1), $page, $order);
    }

    /**
     * クエリ文字列に対応するキャッシュのキーを求める
     */
    protected function cacheKeyOf(string $queryText): string {
        return $this->getQuery()->getCacheKey((new \BuildQueryOfTagSearch($queryText, \TagSearch::LIMIT_TAGS))->query());
    }

    /**
     * 記事の直接登録(検索対象に含めるだけでタグは登録しない)
     */
    protected function insertArticle(string $id, ?string $updateDate = null): void {
        $this->pdo->prepare('INSERT INTO posted_articles(id, post_date, update_date) VALUES (?, ?, ?)')->execute([$id, $id, $updateDate ?? $id]);
    }

    /**
     * tag_search_cachesに登録されているキーの一覧
     * @return array<string>
     */
    protected function cachedKeysInDatabase(): array {
        return $this->pdo->query('SELECT id FROM tag_search_caches ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * ディレクトリの再帰的な削除
     */
    private function removeDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $dir,
                    \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ) as $entry
        ) {
            $target = $entry->getRealPath();
            if ($entry->isDir()) {
                if (!rmdir($target)) {
                    throw new \RuntimeException("{$target}の削除に失敗しました");
                }
            }
            else {
                if (!unlink($target)) {
                    throw new \RuntimeException("{$target}の削除に失敗しました");
                }
            }
        }
        if (!rmdir($dir)) {
            throw new \RuntimeException("{$target}の削除に失敗しました");
        }
    }
}
