<?php
	declare(strict_types=1);
	require_once __DIR__.'/Search.php';

	/**
	 * タグ検索に関する制御を行うクラス
	 */
	class TagSearch {
		/** @var int 投稿日時の昇順 */
		public const ASC_POSTDATE = 0;
		/** @var int 更新日時の昇順 */
		public const ASC_UPDATEDATE = 1;
		/** @var int 投稿日時の降順 */
		public const DESC_POSTDATE = 2;
		/** @var int 更新日時の降順 */
		public const DESC_UPDATEDATE = 3;

		/** 検索に用いることができるタグの数 */
		public const LIMIT_TAGS = 3;

		/**
		 * クエリの発行のためのオブジェクトの取得
		 * @param $callback PDOを返すオブジェクト
		 */
		public static function getQuery(\Closure $callback) {
			$cacheTable = new \TagSearchCaches(__DIR__.'/cache', $callback);
			$query = new \Query($cacheTable, $callback);
			return $query;
		}

		/**
		 * クエリの結果の取得
		 * @param $queryText クエリを示す文字列
		 * @param $page ページ(オフセットは1)
		 * @param $order 並べ替え
		 * @param $callback PDOを返すオブジェクト
		 * @throws \PDOException DBからの検索結果の取得に失敗した際に送信される
		 */
		public static function getResult(string $queryText, int $page, int $order, \Closure $callback) {
			if ($page < 1) {
				throw new \LogicException('ページ番号は1以上でなければいけません');
			}
			$query = self::getQuery($callback);
			$builder = new \BuildQueryOfTagSearch($queryText, self::LIMIT_TAGS);
			return $query->get($builder, $page, $order);
		}
	}
