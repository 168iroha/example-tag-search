<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 検索クエリの字句解析・構文木の構築・SQLの生成についてのテスト(DB未使用)
 */
class BuildQueryOfTagSearchTest extends TestCase {
    /** テストで利用するタグ数のデフォルトの上限 */
    private const LIMIT_TAGS = 3;

    /**
     * BuildQueryOfTagSearchを構築するヘルパ
     * @param string $queryText タグ検索に入力されている文字列
     * @param int $limitTag 検索に用いるタグ数の制限
     * @return BuildQueryOfTagSearch
     */
    private function build(string $queryText, int $limitTag = self::LIMIT_TAGS): BuildQueryOfTagSearch {
        return new BuildQueryOfTagSearch($queryText, $limitTag);
    }

    /**
     * 単一トークンによるクエリの正規化内容
     * @return array<string, array{string, string}>
     */
    public static function normalizationProvider(): array {
        return [
            '小文字は大文字に変換される'           => ['php', 'PHP'],
            '前後の空白は除去される'               => ['   php   ', 'PHP'],
            '全角英字はNFKCで半角になる'           => ['ｐｈｐ', 'PHP'],
            '半角カナはNFKCで全角になる'           => ['ｱｲｳ', 'アイウ'],
            '丸数字はNFKCで数字になる'             => ['①', '1'],
            '全角記号はNFKCで半角になる'           => ['Ｃ＋＋', 'C++'],
            '正規化不要な文字はそのまま'           => ['C++', 'C++'],
            'ひらがなは変換されない'               => ['たぐ', 'たぐ'],
        ];
    }

    #[Test]
    #[DataProvider('normalizationProvider')]
    public function トークンの正規化(string $input, string $expected): void {
        $builder = $this->build($input);
        $this->assertSame($expected, BuildQueryOfTagSearch::normToken($input));
        $this->assertSame([$expected], $builder->bindVal());

        // 単一タグでは積は現れない
        $this->assertStringNotContainsString('INTERSECT', $builder->select(10, 0, TagSearch::DESC_POSTDATE));
    }

    #[Test]
    public function トークンの正規化の冪等性(): void {
        foreach (self::normalizationProvider() as [$input, $expected]) {
            $this->assertSame($expected, BuildQueryOfTagSearch::normToken(BuildQueryOfTagSearch::normToken($input)), '正規化は冪等でなければキャッシュキーが安定しない');
        }
    }

    /**
     * 空クエリの正規化内容
     * @return array<string, array{string}>
     */
    public static function emptyQueryProvider(): array {
        return [
            '空文字'         => [''],
            '空白のみ'       => ['   '],
            '空のダブルクォート' => ['""'],
        ];
    }

    #[Test]
    #[DataProvider('emptyQueryProvider')]
    public function 空クエリの正規化とバインド変数(string $queryText): void {
        $builder = $this->build($queryText);

        $this->assertSame('', $builder->query());
        $this->assertSame([], $builder->bindVal());
    }

    #[Test]
    public function 空クエリは全てのレコードが選択対象となるSQLが生成される(): void {
        $builder = $this->build('');

        // 絞り込み条件がないので中間テーブルとの結合は行われない
        $this->assertStringNotContainsString('posted_articles_tags', $builder->select(10, 0, TagSearch::DESC_POSTDATE));
        $this->assertStringNotContainsString('INNER JOIN', $builder->select(10, 0, TagSearch::DESC_POSTDATE));
        $this->assertSame('SELECT COUNT(*) FROM posted_articles', $builder->count());
    }

    #[Test]
    public function AND検索(): void {
        $builder = $this->build('php mysql');

        $this->assertSame('"MYSQL" "PHP"', $builder->query());
        $this->assertSame(['MYSQL', 'PHP'], $builder->bindVal());

        // 全角スペース区切り
        $this->assertSame(['A', 'B'], $this->build('a　b')->bindVal());
    }

    #[Test]
    public function OR検索(): void {
        $builder = $this->build('php OR mysql');

        $this->assertSame('"MYSQL"OR"PHP"', $builder->query());
        $this->assertSame(['MYSQL', 'PHP'], $builder->bindVal());
        // ORの大文字小文字は区別されない
        $this->assertSame($builder ->query(), $this->build('php or mysql')->query());
    }

    #[Test]
    public function マイナス検索(): void {
        $builder = $this->build('php - mysql');

        $this->assertSame('"PHP"-"MYSQL"', $builder->query());
        $this->assertSame(['PHP', 'MYSQL'], $builder->bindVal());
    }

    #[Test]
    public function マイナス検索の非可換性(): void {
        $this->assertSame('"MYSQL"-"PHP"', $this->build('mysql - php')->query());
        $this->assertSame('"PHP"-"MYSQL"', $this->build('php - mysql')->query());
        $this->assertNotSame($this->build('a - b - c')->query(), $this->build('a - (b - c)')->query());
    }

    #[Test]
    public function AND検索とOR検索の可換性(): void {
        $this->assertSame($this->build('a b')->query(), $this->build('b a')->query());
        $this->assertSame($this->build('a OR b')->query(), $this->build('b OR a')->query());
    }

    #[Test]
    public function 括弧による演算子の優先順位の変更(): void {
        $grouped = $this->build('(a OR b) c');
        $flat = $this->build('a OR b c');

        $this->assertNotSame($flat->query(), $grouped->query());
        $this->assertSame('("A"OR"B") "C"', $grouped->query());
    }

    #[Test]
    public function 括弧が不要であることが自明な対象のクエリの正規化(): void {
        $this->assertSame('"A"', $this->build('(a)')->query());
        $this->assertSame('"A"', $this->build('((a))')->query());
        $this->assertSame('"A" "B"', $this->build('(a b)')->query());
    }

    #[Test]
    public function AND検索とOR検索の結合法則(): void {
        $this->assertSame($this->build('a b c')->query(), $this->build('a (b c)')->query());
        $this->assertSame($this->build('a (b c)')->query(), $this->build('(a b) c')->query());
        $this->assertSame($this->build('a OR b OR c')->query(), $this->build('a OR (b OR c)')->query());
        $this->assertSame($this->build('a OR (b OR c)')->query(), $this->build('(a OR b) OR c')->query());
    }

    #[Test]
    public function 不整合な括弧の補完(): void {
        $this->assertSame($this->build('(a b)')->query(), $this->build('(a b')->query());
        $this->assertSame($this->build('a b')->query(), $this->build(')a b')->query());
    }

    #[Test]
    public function 括弧の中身が空(): void {
        $this->assertSame('', $this->build('()')->query());
    }

    #[Test]
    public function ダブルクォートにより囲まれたトークンを指定した検索(): void {
        // キーワードも入力可能
        $this->assertSame(['OR'], $this->build('"or"')->bindVal());
        $this->assertSame(['-'], $this->build('"-"')->bindVal());
        $this->assertSame(['('], $this->build('"("')->bindVal());
        $this->assertSame([')'], $this->build('")"')->bindVal());
        // スペースも許容
        $this->assertSame(['TAG NAME'], $this->build('"tag name"')->bindVal());
        // 2つ並べればエスケープされる
        $this->assertSame(['A"B'], $this->build('"a""b"')->bindVal());
    }

    #[Test]
    public function 閉じていないダブルクォートの補完(): void {
        $this->assertSame(['TAG NAME'], $this->build('"tag name')->bindVal());
    }

    #[Test]
    public function タグ数の制限(): void {
        // 上限を超えたタグは無視される
        $this->assertSame([], $this->build('a b c d e', 0)->bindVal());
        $this->assertSame(['A'], $this->build('a b c d e', 1)->bindVal());
        $this->assertSame(['A', 'B'], $this->build('a b c d e', 2)->bindVal());
        $this->assertSame(['A', 'B', 'C'], $this->build('a b c d e', 3)->bindVal());
        $this->assertSame(['A', 'B', 'C', 'D'], $this->build('a b c d e', 4)->bindVal());
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $this->build('a b c d e', 5)->bindVal());
        // クエリの正規化は関係なくタグの出現順で制限
        $this->assertSame(['A', 'D', 'E'], $this->build('a d e b c', 3)->bindVal());
        $this->assertSame('"A" "D" "E"', $this->build('a d e b c', 3)->query());
        // 無制限
        $this->assertCount(5, $this->build('a b c d e', -1)->bindVal());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roundTripProvider(): array {
        $queries = [
            '', 'a', 'a b', 'a OR b', 'a -b', '(a OR b) c', 'a (b c)', 'a -(b c)',
            '"tag name"', '"a""b"', 'ｐｈｐ ＭｙＳＱＬ', 'c++ -"or"',
        ];
        return array_combine($queries, array_map(static fn (string $q): array => [$q], $queries));
    }

    #[Test]
    #[DataProvider('roundTripProvider')]
    public function クエリの正規化と冪等性(string $queryText): void {
        $builder = $this->build($queryText);
        $reparsed = $this->build($builder->query());

        $this->assertSame($builder->query(), $reparsed->query());
        $this->assertSame($builder->bindVal(), $reparsed->bindVal());
        $this->assertSame($builder->count(), $reparsed->count());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function orderProvider(): array {
        return [
            '投稿日時の昇順' => [TagSearch::ASC_POSTDATE, 'ORDER BY posted_articles.id ASC'],
            '更新日時の昇順' => [TagSearch::ASC_UPDATEDATE, 'ORDER BY posted_articles.update_date ASC'],
            '投稿日時の降順' => [TagSearch::DESC_POSTDATE, 'ORDER BY posted_articles.id DESC'],
            '更新日時の降順' => [TagSearch::DESC_UPDATEDATE, 'ORDER BY posted_articles.update_date DESC'],
        ];
    }

    #[Test]
    #[DataProvider('orderProvider')]
    public function ソート条件が生成されるSQLに反映される(int $order, string $expectedOrderBy): void {
        $this->assertStringContainsString($expectedOrderBy, $this->build('')->select(10, 0, $order));
    }

    #[Test]
    public function 不明なソート順序の指定時の例外送信(): void {
        $this->expectException(Exception::class);
        $this->build('')->select(10, 0, 999);
    }

    #[Test]
    public function ページ範囲がSQLに反映される(): void {
        $this->assertStringContainsString('LIMIT 10 OFFSET 20', $this->build('')->select(10, 20, TagSearch::DESC_POSTDATE));
    }

    #[Test]
    public function クエリの検索により要されるトークンから計算されるバインド変数の数とSQLのプレースホルダの対応(): void {
        foreach (['a', 'a b', 'a OR b', 'a -b', '(a OR b) c', 'a (b c)'] as $queryText) {
            $builder = $this->build($queryText);
            $this->assertSame(substr_count($builder->count(), '?'), count($builder->bindVal()), "プレースホルダとバインド変数の数が一致しない: {$queryText}");
        }
    }
}
