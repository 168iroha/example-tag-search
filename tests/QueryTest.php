<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use TagSearch\Tests\Support\ArticleFixture;
use TagSearch\Tests\Support\TagSearchTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 検索処理とキャッシュ制御についてのテスト
 */
class QueryTest extends TagSearchTestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->loadFixture();
    }

    /**
     * 記事の連番から期待する検索結果(投稿日時の降順)を組み立てる
     * @param array<int> $noList
     * @return array<string>
     */
    private function expected(array $noList): array {
        $ids = ArticleFixture::articleIds($noList);
        rsort($ids);
        return $ids;
    }

    /**
     * 既存のタグに記事を紐づける(Query::set()を経由しないのでキャッシュは破棄されない)
     * @param string $articleId 記事ID
     * @param string $normName 正規化済みのタグ名
     * @return void
     */
    private function addArticleWithExistingTag(string $articleId, string $normName): void {
        $this->insertArticle($articleId);
        $this->pdo->prepare('INSERT INTO posted_articles_tags(article_id, tag_id) SELECT ?, id FROM tags WHERE norm_name = ?')->execute([$articleId, $normName]);
    }

    /**
     * @return array<string, array{string, array<int>}>
     */
    public static function searchProvider(): array {
        return [
            '単一タグ'               => ['php', [1, 2, 4]],
            'AND検索'                => ['php mysql', [1, 4]],
            'OR検索'                 => ['php OR mysql', [1, 2, 3, 4]],
            'マイナス検索'           => ['php -mysql', [2]],
            '括弧によるグループ化'   => ['(php OR mysql) テスト', [2, 3, 4]],
            '括弧なしとの差異'       => ['php OR mysql テスト', [1, 2, 3, 4]],
            '正規化されて一致する'   => ['ｐｈｐ', [1, 2, 4]],
            '記号を含むタグ'         => ['c++', [5]],
            '該当なし'               => ['php c++', []],
            '存在しないタグ'         => ['unknown', []],
        ];
    }

    /**
     * @param array<int> $expectedNoList
     */
    #[Test]
    #[DataProvider('searchProvider')]
    public function デフォルトのタグ検索結果_入力あり(string $queryText, array $expectedNoList): void {
        $result = $this->search($queryText);

        $this->assertSame($this->expected($expectedNoList), $result['id-list']);
        $this->assertEquals(count($expectedNoList), $result['count']);
    }

    #[Test]
    public function デフォルトのタグ検索結果_入力なし(): void {
        $result = $this->search('');

        $this->assertEquals(ArticleFixture::ARTICLE_COUNT, $result['count']);
        $this->assertCount(Query::MAX_SHOW_COUNT, $result['id-list']);
    }

    /**
     * @return array<string, array{int, array<int>}>
     */
    public static function orderProvider(): array {
        // 投稿日時の昇順と更新日時の昇順が逆順になるようフィクスチャを定義している
        return [
            '投稿日時の昇順' => [TagSearch::ASC_POSTDATE, [1, 2, 4]],
            '投稿日時の降順' => [TagSearch::DESC_POSTDATE, [4, 2, 1]],
            '更新日時の昇順' => [TagSearch::ASC_UPDATEDATE, [4, 2, 1]],
            '更新日時の降順' => [TagSearch::DESC_UPDATEDATE, [1, 2, 4]],
        ];
    }

    /**
     * @param array<int> $expectedNoList
     */
    #[Test]
    #[DataProvider('orderProvider')]
    public function ソート指定したタグ検索結果(int $order, array $expectedNoList): void {
        $result = $this->search('php', 1, $order);

        $this->assertSame(ArticleFixture::articleIds($expectedNoList), $result['id-list']);
    }

    #[Test]
    public function ページネーションが適切に機能する(): void {
        $page1 = $this->search('', 1);
        $page2 = $this->search('', 2);
        $page3 = $this->search('', 3);

        $this->assertCount(Query::MAX_SHOW_COUNT, $page1['id-list']);
        $this->assertCount(ArticleFixture::ARTICLE_COUNT - Query::MAX_SHOW_COUNT, $page2['id-list']);
        $this->assertSame([], $page3['id-list'], '範囲外のページは空になる');
        // ページが変わっても全体件数は変わらない
        $this->assertEquals($page1['count'], $page2['count']);
        // ページ間で結果が重複しない
        $this->assertSame([], array_intersect($page1['id-list'], $page2['id-list']));
    }

    /**
     * 集合演算の入れ子を含むクエリ
     * @return array<string, array{string, array<int>}>
     */
    public static function nestedGroupingProvider(): array {
        return [
            'ORの結果同士をAND'         => ['(php OR mysql) (テスト OR c++)', [2, 3, 4]],
            'ANDの結果にORを重ねる'     => ['((php OR mysql) テスト) OR c++', [2, 3, 4, 5]],
            'ORの結果からORの結果を引く' => ['(php OR mysql) -(テスト OR c++)', [1]],
            '3段の入れ子'               => ['(((php OR mysql) テスト) OR c++) common', [2, 3, 4, 5]],
            '4段の入れ子'               => ['((((php OR mysql) テスト) OR c++) common) -php', [3, 5]],
            'マイナスの右辺が入れ子'     => ['(php OR mysql) -((テスト OR c++) common)', [1]],
        ];
    }

    /**
     * @param array<int> $expectedNoList
     */
    #[Test]
    #[DataProvider('nestedGroupingProvider')]
    public function 集合演算の入れ子を含むタグ検索結果(string $queryText, array $expectedNoList): void {
        $result = $this->searchWithoutTagLimit($queryText);

        $this->assertSame($this->expected($expectedNoList), $result['id-list']);
        $this->assertEquals(count($expectedNoList), $result['count']);
    }

    #[Test]
    public function タグ検索結果はキャッシュされる(): void {
        $this->search('php');

        $key = $this->cacheKeyOf('php');
        $this->assertTrue($this->newCacheTable()->has($key, 1, TagSearch::DESC_POSTDATE . '.'));
        $this->assertSame([$key], $this->cachedKeysInDatabase());
    }

    #[Test]
    public function 明示的にキャッシュを破棄しなければ最新のタグ検索結果は得られない(): void
    {
        $first = $this->search('php');

        // Query::set()を経由せずにDBだけを更新するのでキャッシュは破棄されない
        $this->addArticleWithExistingTag('202412310001', 'PHP');
        $cached = $this->search('php');

        $this->assertSame($first, $cached, 'キャッシュが使われていない');

        // キャッシュを破棄すれば最新の結果が得られる
        $this->newCacheTable()->deleteByKey($this->cacheKeyOf('php'));
        $fresh = $this->search('php');

        $this->assertNotSame($first, $fresh);
        $this->assertEquals(4, $fresh['count']);
    }

    #[Test]
    public function キャッシュは正規化されたクエリの元で同一視される(): void {
        // 表記が違っても正規形が同じクエリは同じキャッシュを使う
        $this->search('php mysql');
        $result = $this->search('ＭｙＳＱＬ  ｐｈｐ');

        $this->assertSame($this->expected([1, 4]), $result['id-list']);
        $this->assertCount(1, $this->cachedKeysInDatabase());
    }

    #[Test]
    public function キャッシュは並び替えごとに分離される(): void {
        $desc = $this->search('php', 1, TagSearch::DESC_POSTDATE);
        $asc = $this->search('php', 1, TagSearch::ASC_POSTDATE);

        $this->assertSame(array_reverse($desc['id-list']), $asc['id-list']);
        // 並べ替えが違ってもキャッシュのキーは同じで、ページのファイルだけが分かれる
        $key = $this->cacheKeyOf('php');
        $this->assertTrue($this->newCacheTable()->has($key, 1, TagSearch::DESC_POSTDATE.'.'));
        $this->assertTrue($this->newCacheTable()->has($key, 1, TagSearch::ASC_POSTDATE.'.'));
    }

    #[Test]
    public function 新規の記事とそれに対応する既存のタグの登録(): void {
        $before = $this->pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn();
        $this->insertArticle('202412310001');

        $this->getQuery()->set('202412310001', ['PHP']);

        $this->assertEquals($before, $this->pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn());
        $this->assertContains('202412310001', $this->search('php')['id-list']);
    }

    #[Test]
    public function 新規の記事とそれに対応する新規のタグの登録(): void {
        $before = $this->pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn();
        $this->insertArticle('202412310001');

        $this->getQuery()->set('202412310001', ['brandnew']);

        $this->assertEquals($before + 1, $this->pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn());
        $stmt = $this->pdo->prepare('SELECT norm_name FROM tags WHERE norm_name = ?');
        $stmt->execute(['BRANDNEW']);
        $this->assertSame('BRANDNEW', $stmt->fetchColumn());
    }

    #[Test]
    public function 既存の記事に対応するタグの再登録(): void {
        $target = ArticleFixture::articleId(4);

        // 記事4はPHP/MYSQL/テスト/COMMONを持つが、PHPとCOMMONだけを残す
        $this->getQuery()->set($target, ['PHP', 'COMMON']);

        $this->assertContains($target, $this->search('php')['id-list']);
        $this->assertNotContains($target, $this->search('mysql')['id-list']);
        $this->assertNotContains($target, $this->search('テスト')['id-list']);
    }

    #[Test]
    public function タグの登録によるキャッシュの削除(): void {
        $this->search('php');
        $this->search('テスト');
        $this->insertArticle('202412310001');

        $this->getQuery()->set('202412310001', ['PHP']);

        // 変更に関係するタグのキャッシュは破棄される
        $this->assertFalse($this->newCacheTable()->has($this->cacheKeyOf('php')));
        // 無関係なタグのキャッシュは残る
        $this->assertTrue($this->newCacheTable()->has($this->cacheKeyOf('テスト')));
    }

    #[Test]
    public function 空クエリのキャッシュの無効化(): void {
        $this->search('');
        $this->insertArticle('202412310001');

        $this->getQuery()->set('202412310001', ['PHP']);

        // 空クエリは全記事が対象なので常に破棄する必要がある
        $this->assertFalse($this->newCacheTable()->has($this->cacheKeyOf('')));
    }

    #[Test]
    public function キャッシュの無効化をスキップできる(): void {
        $this->search('php');
        $this->insertArticle('202412310001');

        $this->getQuery()->set('202412310001', ['PHP'], false);

        $this->assertTrue($this->newCacheTable()->has($this->cacheKeyOf('php')));
    }

    #[Test]
    public function 変更がない場合キャッシュを維持できる(): void {
        $this->search('php');
        $target = ArticleFixture::articleId(1);

        // 現状と同じタグを設定した場合は変更がないのでキャッシュは維持される
        $this->getQuery()->set($target, ['COMMON', 'PHP', 'MYSQL']);

        $this->assertTrue($this->newCacheTable()->has($this->cacheKeyOf('php')));
    }

    #[Test]
    public function 記事の削除による検索結果の削除(): void {
        $target = ArticleFixture::articleId(1);

        $this->getQuery()->delete($target);

        $this->assertNotContains($target, $this->search('php')['id-list']);
        $this->assertNotContains($target, $this->search('mysql')['id-list']);
        $this->assertEquals(2, $this->search('php')['count']);
    }

    #[Test]
    public function 記事の削除によるキャッシュの削除(): void {
        $this->search('php');
        $this->search('c++');

        $this->getQuery()->delete(ArticleFixture::articleId(1));

        $this->assertFalse($this->newCacheTable()->has($this->cacheKeyOf('php')));
        $this->assertTrue($this->newCacheTable()->has($this->cacheKeyOf('c++')));
    }

    #[Test]
    public function 記事の削除によるタグの保持(): void {
        $before = $this->pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn();

        $this->getQuery()->delete(ArticleFixture::articleId(5));

        // 記事との関連だけを外し、タグ自体は他の記事から参照されうるので残す
        $this->assertEquals($before, $this->pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn());
    }

    #[Test]
    public function すべてのキャッシュの削除(): void {
        $this->search('php');
        $this->search('mysql');

        $this->getQuery()->deleteAllCache();

        $this->assertSame([], $this->cachedKeysInDatabase());
        $this->assertSame([], glob("{$this->cacheDir}/*"));
    }
}
