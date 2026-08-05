<?php
declare(strict_types=1);

use TagSearch\Tests\Support\ArticleFixture;
use TagSearch\Tests\Support\TagSearchTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 外部から利用する入口であるTagSearchについてのテスト
 */
class TagSearchTest extends TagSearchTestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->loadFixture();
    }

    #[Test]
    public function タグ検索結果の記事IDと件数の取得(): void {
        $result = $this->search('php');

        $this->assertSame(['id-list', 'count'], array_keys($result));
        $this->assertEquals(3, $result['count']);
    }

    #[Test]
    public function ページ番号が1未満の場合は例外を投げる(): void {
        $this->expectException(LogicException::class);
        $this->search('php', 0);
    }

    #[Test]
    public function ページ番号が負の場合は例外を投げる(): void {
        $this->expectException(LogicException::class);
        $this->search('php', -1);
    }

    #[Test]
    public function タグ数が制限を超える場合は結果が制限される(): void {
        // 4つ目のタグ(テスト)は無視されるので COMMON ∩ PHP ∩ MYSQL の結果になる
        $limited = $this->search('common php mysql テスト');

        $this->assertSame(ArticleFixture::articleIds([4, 1]), $limited['id-list']);
        $this->assertEquals(2, $limited['count']);
    }

    #[Test]
    public function キャッシュディレクトリにバインドされたクエリを取得する(): void {
        $query = $this->getQuery();
        $query->getCacheTable()->create(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaa',
            [],
            60,
            ['count' => 0, 'max-page' => 0]
        );

        $this->assertDirectoryExists("{$this->cacheDir}/aaaaaaaaaaaaaaaaaaaaaaaaaaa");
    }

    #[Test]
    public function 並び替え定数がBuildQueryOfTagSearchと一致する(): void {
        // TagSearchとBuildQueryOfTagSearchで並べ替えの識別子がずれていないこと
        $this->assertSame(BuildQueryOfTagSearch::ASC_POSTDATE, TagSearch::ASC_POSTDATE);
        $this->assertSame(BuildQueryOfTagSearch::ASC_UPDATEDATE, TagSearch::ASC_UPDATEDATE);
        $this->assertSame(BuildQueryOfTagSearch::DESC_POSTDATE, TagSearch::DESC_POSTDATE);
        $this->assertSame(BuildQueryOfTagSearch::DESC_UPDATEDATE, TagSearch::DESC_UPDATEDATE);
    }
}
