<?php
declare(strict_types=1);

namespace TagSearch\Tests\Support;

/**
 * 検索テストで用いる記事とタグのフィクスチャ
 *
 * - 記事は12件(Query::MAX_SHOW_COUNTが10なのでページングが検証できる件数)
 * - post_dateの昇順とupdate_dateの昇順が逆順になるように定義することで4種類の並べ替えをすべて区別できるようにしている
 */
final class ArticleFixture {
    /** 記事の件数 */
    public const ARTICLE_COUNT = 12;

    /**
     * タグ(正規化済み名) => 紐づく記事の連番
     * @var array<string, array<int>>
     */
    public const TAG_ARTICLES = [
        'COMMON' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
        'PHP'    => [1, 2, 4],
        'MYSQL'  => [1, 3, 4],
        'テスト' => [2, 3, 4],
        'C++'    => [5],
    ];

    /**
     * タグの正規化前の名称
     * @var array<string, string>
     */
    private const TAG_ORG_NAMES = [
        'COMMON' => 'Common',
        'PHP'    => 'PHP',
        'MYSQL'  => 'MySQL',
        'テスト' => 'テスト',
        'C++'    => 'C++',
    ];

    /**
     * 連番から記事IDを求める
     */
    public static function articleId(int $no): string {
        return sprintf('2024010100%02d', $no);
    }

    /**
     * 連番から更新日時を求める(投稿日時とは逆順になる)
     */
    public static function updateDate(int $no): string {
        return sprintf('2024020100%02d', self::ARTICLE_COUNT + 1 - $no);
    }

    /**
     * 連番のリストから記事IDのリストを求める
     * @param array<int> $noList
     * @return array<string>
     */
    public static function articleIds(array $noList): array {
        return array_map(self::articleId(...), $noList);
    }

    /**
     * フィクスチャの投入
     */
    public static function load(\PDO $pdo): void {
        $insertArticle = $pdo->prepare('INSERT INTO posted_articles(id, post_date, update_date) VALUES (?, ?, ?)');
        for ($no = 1; $no <= self::ARTICLE_COUNT; ++$no) {
            $id = self::articleId($no);
            $insertArticle->execute([$id, $id, self::updateDate($no)]);
        }

        $insertTag = $pdo->prepare('INSERT INTO tags(id, org_name, norm_name) VALUES (?, ?, ?)');
        $insertLink = $pdo->prepare('INSERT INTO posted_articles_tags(article_id, tag_id) VALUES (?, ?)');
        $seq = 0;
        foreach (self::TAG_ARTICLES as $normName => $noList) {
            // タグIDは重複がないように適当な記事IDを利用して採番
            $tagId = self::articleId($noList[0]).sprintf('%02d', ++$seq);
            $insertTag->execute([$tagId, self::TAG_ORG_NAMES[$normName], $normName]);
            foreach ($noList as $no) {
                $insertLink->execute([self::articleId($no), $tagId]);
            }
        }
    }
}
