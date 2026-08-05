<?php
declare(strict_types=1);

namespace TagSearch\Tests\Support;

/**
 * テスト用のデータベース接続を提供する
 *
 * 既定ではSQLiteのインメモリDBを利用するが、以下の環境変数を設定することで本番と同じMySQLなど他のドライバへそのまま差し替えられる
 *
 * - TAGSEARCH_TEST_DSN      例: mysql:host=127.0.0.1;dbname=tag_search_test;charset=utf8mb4
 * - TAGSEARCH_TEST_USERNAME 例: root
 * - TAGSEARCH_TEST_PASSWORD 例: password
 *
 * スキーマはリポジトリ直下のsetup.sqlをそのまま流用するためテーブル定義の変更に追随するための二重管理は発生しない
 */
final class TestDatabase {
    /** 既定のDSN */
    public const DEFAULT_DSN = 'sqlite::memory:';

    /** setup.sqlのパス */
    private const SCHEMA_FILE = __DIR__ . '/../../setup.sql';

    /**
     * 接続に用いるDSNの取得
     */
    public static function dsn(): string {
        $dsn = getenv('TAGSEARCH_TEST_DSN');
        return is_string($dsn) && $dsn !== '' ? $dsn : self::DEFAULT_DSN;
    }

    /**
     * 接続に用いるドライバ名の取得
     */
    public static function driver(): string {
        $driver = strstr(self::dsn(), ':', true);
        return $driver === false ? 'sqlite' : $driver;
    }

    /**
     * SQLiteに接続しているか
     */
    public static function isSqlite(): bool {
        return self::driver() === 'sqlite';
    }

    /**
     * DBへの接続
     *
     * query.phpのgetPDO()と同じ属性を設定することで、
     * テストと本番でPDOの振る舞いが変わらないようにする
     */
    public static function connect(): \PDO
    {
        $username = getenv('TAGSEARCH_TEST_USERNAME');
        $password = getenv('TAGSEARCH_TEST_PASSWORD');

        $pdo = new \PDO(
            self::dsn(),
            is_string($username) && $username !== '' ? $username : null,
            is_string($password) && $password !== '' ? $password : null,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );

        if (self::isSqlite()) {
            // SQLiteは既定で外部キー制約を検査しないため、InnoDBと同等の条件に揃える
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return $pdo;
    }

    /**
     * setup.sqlを実行してスキーマを作り直す
     */
    public static function migrate(\PDO $pdo): void {
        $sql = file_get_contents(self::SCHEMA_FILE);
        if ($sql === false) {
            throw new \RuntimeException(self::SCHEMA_FILE . 'の読み込みに失敗しました');
        }
        $pdo->exec($sql);
    }
}
