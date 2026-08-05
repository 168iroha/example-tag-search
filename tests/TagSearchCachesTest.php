<?php
declare(strict_types=1);

use TagSearch\Tests\Support\TagSearchTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * キャッシュの構築・参照・削除についてのテスト
 *
 * キャッシュはファイル(本体・有効期限・コンフィグ)とDB(tag_search_caches系)の
 * 二重管理になっているため、両者の整合が保たれることを中心に検証する
 */
class TagSearchCachesTest extends TagSearchTestCase {
    /** テストで用いるキャッシュのキー */
    private const KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER_KEY = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const THIRD_KEY = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private \TagSearchCaches $cache;

    protected function setUp(): void {
        parent::setUp();
        $this->loadFixture();
        $this->cache = $this->newCacheTable();
    }

    /**
     * キャッシュのファイル格納先
     */
    private function getCachePath(string $key, string $file = ''): string {
        return "{$this->cacheDir}/{$key}/{$file}";
    }

    /**
     * expiration.jsonの内容の取得
     * @return array{expiration: string, interval: int}
     */
    private function readExpirationFile(string $key): array {
        return json_decode(file_get_contents($this->getCachePath($key, 'expiration.json')), true);
    }

    /**
     * expiration.jsonの書き換え
     */
    private function writeExpirationFile(string $key, string $expiration, int $interval): void {
        file_put_contents($this->getCachePath($key, 'expiration.json'), json_encode(['expiration' => $expiration, 'interval' => $interval], JSON_PRETTY_PRINT));
    }

    /**
     * キャッシュに紐づけられたタグの取得
     * @return array<string>
     */
    private function getCachedTagIds(string $key): array {
        $stmt = $this->pdo->prepare('SELECT tag_id FROM tag_search_caches_tags WHERE cache_id = ? ORDER BY tag_id');
        $stmt->execute([$key]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    #[Test]
    public function タグ検索結果のキャッシュの新規構築(): void {
        $config = ['count' => 3, 'max-page' => 1];
        $this->cache->create(self::KEY, ['PHP', 'MYSQL'], 60, $config);

        // キャッシュキーの存在
        $this->assertTrue($this->cache->has(self::KEY));
        // キャッシュの有効期限についてのファイルの存在
        $this->assertFileExists($this->getCachePath(self::KEY, 'expiration.json'));
        // キャッシュのコンフィグについてのファイルの存在
        $this->assertFileExists($this->getCachePath(self::KEY, 'config.json'));
        $this->assertSame($config, $this->cache->getConfig(self::KEY));
        // テーブルにキャッシュキーが登録されるかの確認
        $this->assertSame([self::KEY], $this->cachedKeysInDatabase());
        // キャッシュに紐づけられたタグの確認
        $this->assertCount(2, $this->getCachedTagIds(self::KEY));
    }

    #[Test]
    public function 分数で指定した場合のキャッシュ有効期限の保存(): void {
        $this->cache->create(self::KEY, ['PHP'], 60, ['count' => 1, 'max-page' => 1]);

        $expiration = $this->readExpirationFile(self::KEY);
        // 分数で指定した場合は有効期限の延長に用いるためintervalが保存される
        $this->assertSame(60, $expiration['interval']);
        $this->assertGreaterThan(new DateTime(), new DateTime($expiration['expiration']));
    }

    #[Test]
    public function 日時で指定した場合のキャッシュ有効期限の保存(): void {
        $limit = new DateTime();
        $limit->modify('+1 day');
        $this->cache->create(self::KEY, [], $limit, ['count' => 0, 'max-page' => 0]);

        $expiration = $this->readExpirationFile(self::KEY);
        // 日時で指定した場合は延長対象外なのでintervalは0になる
        $this->assertSame(0, $expiration['interval']);
        $this->assertSame($limit->format('Y-m-d H:i:s'), $expiration['expiration']);
        $this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', $limit->format('Y-m-d H:i:s')), $this->cache->getExpirationTime(self::KEY));
    }

    #[Test]
    public function 存在しないキャッシュについて設定値の取得に失敗する(): void {
        $this->expectException(Exception::class);
        $this->cache->getConfig(self::KEY);
    }

    #[Test]
    public function 存在しないキャッシュについて有効期限の取得に失敗する(): void {
        $this->expectException(Exception::class);
        $this->cache->getExpirationTime(self::KEY);
    }

    #[Test]
    public function キャッシュ本体の構築(): void {
        $this->cache->create(self::KEY, ['PHP'], 60, ['count' => 3, 'max-page' => 1]);
        $idList = ['202401010001', '202401010002', '202401010003'];

        $this->cache->set(self::KEY, 1, '2.', $idList);

        $this->assertTrue($this->cache->has(self::KEY, 1, '2.'));
        $this->assertSame($idList, $this->cache->get(self::KEY, 1, '2.'));
    }

    #[Test]
    public function ページとプレフィックスでキャッシュ本体が分離される(): void {
        $this->cache->create(self::KEY, ['COMMON'], 60, ['count' => 12, 'max-page' => 2]);
        $this->cache->set(self::KEY, 1, '2.', ['page1']);
        $this->cache->set(self::KEY, 2, '2.', ['page2']);
        // prefixは並べ替え種別を表すので、同じページでも別のキャッシュになる
        $this->cache->set(self::KEY, 1, '0.', ['asc-page1']);

        $this->assertSame(['page1'], $this->cache->get(self::KEY, 1, '2.'));
        $this->assertSame(['page2'], $this->cache->get(self::KEY, 2, '2.'));
        $this->assertSame(['asc-page1'], $this->cache->get(self::KEY, 1, '0.'));
    }

    #[Test]
    public function キャッシュ本体に存在しないページの取得に失敗する(): void {
        $this->cache->create(self::KEY, ['PHP'], 60, ['count' => 3, 'max-page' => 1]);

        // キャッシュに存在しないため元テーブルからデータを取得してキャッシュを登録するのが運用
        $this->assertNull($this->cache->get(self::KEY, 1, '2.'));
    }

    #[Test]
    public function キャッシュが管理されていない場合は取得に失敗する(): void {
        // キャッシュに存在しないため元テーブルからデータを取得してキャッシュを登録するのが運用
        $this->assertNull($this->cache->get(self::KEY, 1, '2.'));
    }

    #[Test]
    public function キャッシュが管理されていない場合にキャッシュ本体を登録しようとすると例外を投げる(): void {
        $this->expectException(Exception::class);
        $this->cache->set(self::KEY, 1, '2.', ['202401010001']);
    }

    #[Test]
    public function キャッシュが管理されているかとキャッシュ本体が存在するかは別(): void {
        $this->assertFalse($this->cache->has(self::KEY));

        $this->cache->create(self::KEY, ['PHP'], 60, ['count' => 1, 'max-page' => 1]);
        $this->assertTrue($this->cache->has(self::KEY));
        $this->assertFalse($this->cache->has(self::KEY, 1, '2.'));

        $this->cache->set(self::KEY, 1, '2.', ['202401010001']);
        $this->assertTrue($this->cache->has(self::KEY, 1, '2.'));
    }

    #[Test]
    public function 分数による有効期限の更新(): void {
        $this->cache->create(self::KEY, ['PHP'], 60, ['count' => 1, 'max-page' => 1]);
        // 有効期限だけを過去に巻き戻す
        $limit = new DateTime();
        $limit->modify('-1 day');
        $this->writeExpirationFile(self::KEY, $limit->format('Y-m-d H:i:s'), 60);

        $this->cache->update(self::KEY);

        $this->assertGreaterThan(new DateTime(), $this->cache->getExpirationTime(self::KEY));
    }

    #[Test]
    public function 有効期限が0の場合は更新されない(): void {
        // 日時指定で作られたキャッシュ(空クエリなど)は延長対象にしない
        $limit = new DateTime();
        $limit->modify('+1 day');
        $this->cache->create(self::KEY, [], $limit, ['count' => 0, 'max-page' => 0]);

        $this->cache->update(self::KEY);

        $this->assertSame($limit->format('Y-m-d H:i:s'), $this->readExpirationFile(self::KEY)['expiration']);
    }

    #[Test]
    public function 参照されたキャッシュの有効期限は延長される(): void {
        $this->cache->create(self::KEY, ['PHP'], 60, ['count' => 1, 'max-page' => 1]);
        $this->cache->set(self::KEY, 1, '2.', ['202401010001'], false);
        $limit = new DateTime();
        $limit->modify('-1 day');
        $this->writeExpirationFile(self::KEY, $limit->format('Y-m-d H:i:s'), 60);

        $this->cache->get(self::KEY, 1, '2.');

        // 参照されたキャッシュは生存期間が延びる
        $this->assertGreaterThan(new DateTime(), $this->cache->getExpirationTime(self::KEY));
    }

    #[Test]
    public function キャッシュの有効期限をスキップして更新できる(): void {
        $this->cache->create(self::KEY, ['PHP'], 60, ['count' => 1, 'max-page' => 1]);
        $limit = new DateTime();
        $limit->modify('-1 day');
        $this->writeExpirationFile(self::KEY, $limit->format('Y-m-d H:i:s'), 60);

        $this->cache->set(self::KEY, 1, '2.', ['202401010001'], false);

        $this->assertSame($limit->format('Y-m-d H:i:s'), $this->readExpirationFile(self::KEY)['expiration']);
    }

    #[Test]
    public function キャッシュキーによる削除(): void {
        // キャッシュの登録
        $this->cache->create(self::KEY, ['PHP'], 60, ['count' => 1, 'max-page' => 1]);
        $this->cache->set(self::KEY, 1, '2.', ['202401010001']);
        $this->cache->create(self::OTHER_KEY, ['MYSQL'], 60, ['count' => 1, 'max-page' => 1]);

        // キャッシュの削除
        $this->cache->deleteByKey(self::KEY);

        $this->assertFalse($this->cache->has(self::KEY));
        $this->assertDirectoryDoesNotExist($this->getCachePath(self::KEY));
        $this->assertSame([], $this->getCachedTagIds(self::KEY));
        // 指定していないキャッシュは残る
        $this->assertSame([self::OTHER_KEY], $this->cachedKeysInDatabase());
    }

    #[Test]
    public function キャッシュの再削除は問題ない(): void {
        $this->cache->deleteByKey(self::KEY);

        $this->assertFalse($this->cache->has(self::KEY));
    }

    #[Test]
    public function タグによる削除は関連するキャッシュのみを削除する(): void {
        // キャッシュの登録
        $this->cache->create(self::KEY, ['PHP'], 60, ['count' => 1, 'max-page' => 1]);
        $this->cache->create(self::OTHER_KEY, ['PHP', 'MYSQL'], 60, ['count' => 1, 'max-page' => 1]);
        $this->cache->create(self::THIRD_KEY, ['テスト'], 60, ['count' => 1, 'max-page' => 1]);

        // タグPHPに紐づくキャッシュを削除
        $this->cache->deleteByTag('PHP');

        $this->assertFalse($this->cache->has(self::KEY));
        $this->assertFalse($this->cache->has(self::OTHER_KEY));
        $this->assertTrue($this->cache->has(self::THIRD_KEY));
        $this->assertSame([self::THIRD_KEY], $this->cachedKeysInDatabase());
    }

    #[Test]
    public function 未知のタグによる削除は問題ない(): void {
        $this->cache->create(self::KEY, ['PHP'], 60, ['count' => 1, 'max-page' => 1]);

        $this->cache->deleteByTag('UNKNOWN');

        $this->assertTrue($this->cache->has(self::KEY));
    }

    #[Test]
    public function 有効期限に基づくキャッシュの削除(): void {
        // キャッシュの登録
        $this->cache->create(self::KEY, ['PHP'], new DateTime('2000-01-01 00:00:00'), ['count' => 1, 'max-page' => 1]);
        $this->cache->create(self::OTHER_KEY, ['MYSQL'], new DateTime('2100-01-01 00:00:00'), ['count' => 1, 'max-page' => 1]);

        // 2020-01-01 00:00:00より前に期限切れになるキャッシュを削除する
        $this->cache->deleteByDatetime(new DateTime('2020-01-01 00:00:00'));

        $this->assertFalse($this->cache->has(self::KEY));
        $this->assertTrue($this->cache->has(self::OTHER_KEY));
        $this->assertSame([self::OTHER_KEY], $this->cachedKeysInDatabase());
    }

    #[Test]
    public function DB上は期限切れでもファイル側が延長されていればキャッシュは残す(): void {
        // キャッシュを登録
        $this->cache->create(self::KEY, ['PHP'], 60, ['count' => 1, 'max-page' => 1]);
        $this->pdo->prepare('UPDATE tag_search_caches SET expiration_time = ? WHERE id = ?')->execute(['2000-01-01 00:00:00', self::KEY]);
        // ファイル側の有効期限を延長
        // DB側はキャッシュの削除処理の契機で有効期限が判定される
        $this->writeExpirationFile(self::KEY, '2100-01-01 00:00:00', 60);

        // DB側の有効期限よりも後かつファイル側の有効期限よりも前の日時で削除を試みる
        $this->cache->deleteByDatetime(new DateTime('2020-01-01 00:00:00'));

        $this->assertTrue($this->cache->has(self::KEY));
        $stmt = $this->pdo->prepare('SELECT expiration_time FROM tag_search_caches WHERE id = ?');
        $stmt->execute([self::KEY]);
        // DB側の有効期限がファイルのものに更新される
        $this->assertSame('2100-01-01 00:00:00', $stmt->fetchColumn());
    }

    #[Test]
    public function すべてのキャッシュを削除する(): void {
        // キャッシュの登録
        $this->cache->create(self::KEY, ['PHP'], 60, ['count' => 1, 'max-page' => 1]);
        $this->cache->set(self::KEY, 1, '2.', ['202401010001']);
        $this->cache->create(self::OTHER_KEY, ['MYSQL'], 60, ['count' => 1, 'max-page' => 1]);

        // すべてのキャッシュを削除
        $this->cache->deleteAll();

        $this->assertFalse($this->cache->has(self::KEY));
        $this->assertFalse($this->cache->has(self::OTHER_KEY));
        $this->assertSame([], $this->cachedKeysInDatabase());
        $this->assertSame([], glob("{$this->cacheDir}/*"));
    }
    
    #[Test]
    public function キャッシュの削除の際にリネームしたファイルが残存した場合も別の契機で削除できる(): void {
        // 削除に失敗して「キー.日時」にリネームされたまま残ったフォルダを想定する
        $leftover = "{$this->cacheDir}/" . self::OTHER_KEY . '.20240101000000';
        mkdir($leftover, 0777, true);
        file_put_contents("{$leftover}/2.1.json", '[]');
        $this->cache->create(self::KEY, ['PHP'], 60, ['count' => 1, 'max-page' => 1]);

        $this->cache->deleteCacheFile();

        $this->assertDirectoryDoesNotExist($leftover);
        // 有効なキャッシュには影響しない
        $this->assertTrue($this->cache->has(self::KEY));
    }

    #[Test]
    public function 件数指定の削除は有効期限が長い上位件を残す(): void {
        $this->cache->create(self::KEY, ['PHP'], new DateTime('2100-01-01 00:00:00'), ['count' => 1, 'max-page' => 1]);
        $this->cache->create(self::OTHER_KEY, ['MYSQL'], new DateTime('2050-01-01 00:00:00'), ['count' => 1, 'max-page' => 1]);
        $this->cache->create(self::THIRD_KEY, ['テスト'], new DateTime('2030-01-01 00:00:00'), ['count' => 1, 'max-page' => 1]);

        // 有効期限が長い2件を残し残りの1件を削除する
        $this->cache->deleteByNumber(1, 2);

        $this->assertFalse($this->cache->has(self::THIRD_KEY), '最も早く期限切れになるキャッシュが削除される');
        $this->assertTrue($this->cache->has(self::KEY));
        $this->assertTrue($this->cache->has(self::OTHER_KEY));
    }

    #[Test]
    public function 件数指定の削除はすべてのキャッシュを削除する(): void {
        $this->cache->create(self::KEY, ['PHP'], 60, ['count' => 1, 'max-page' => 1]);

        $this->cache->deleteByNumber(1, 1);

        $this->assertTrue($this->cache->has(self::KEY));
    }
}
