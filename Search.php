<?php
	/**
	 * クエリのツリーのインターフェース
	 */
	interface QueryTree {
		/**
		 * 検索結果を取得するSQLの取得
		 * @param $seq SQLの構築の際に用いるシーケンス
		 */
		public function sql(int &$seq): string;

		/**
		 * フラット化したバインド変数の取得
		 * @return array<string>
		 */
		public function bindVal(): array;

		/**
		 * 同等の構文木を生成するクエリの取得
		 */
		public function query(): string;
	}

	/**
	 * タグについてのツリー
	 */
	class TagTree implements QueryTree {
		/** 検索結果のクエリの一部を得るためのSQL */
		private const SQL_PART_SELECT = 'SELECT article_id FROM `posted_articles_tags` WHERE tag_id IN (SELECT id FROM `tags` WHERE norm_name = ?)';
		/** バインド変数の値 */
		public string $val;

		public function __construct(string $val) {
			$this->val = $val;
		}

		/**
		 * 検索結果を取得するSQLの取得
		 * @param $seq SQLの構築の際に用いるシーケンス
		 */
		public function sql(int &$seq): string {
			return self::SQL_PART_SELECT;
		}

		/**
		 * フラット化したバインド変数の取得
		 * @return array<string>
		 */
		public function bindVal(): array {
			return [$this->val];
		}

		/**
		 * 同等の構文木を生成するクエリの取得
		 */
		public function query(): string {
			// ダブルクォートで囲った文字列を返す
			return '"'.str_replace('"', '""', $this->val).'"';
		}
	}

	/**
	 * 二項演算についてのツリー
	 */
	abstract class BinaryTree implements QueryTree {
		/** @var array<QueryTree> 子要素 */
		public array $children = [];
		/** クエリにおいて連結をする演算子 */
		private string $queryOp;
		/** 結合の優先順位 */
		private int $level;

		/**
		 * 可能ならばトークンをソートしてから構文木を構築するための並べ替えのルール
		 */
		private static function order(QueryTree $a, QueryTree $b) {
			if ($a instanceof TagTree && $b instanceof TagTree) {
				// 比較対象がTagTreeなら辞書式昇順にする
				return $a->val <=> $b->val;
			}
			if ($a instanceof BinaryTree && !($b instanceof BinaryTree)) {
				// BinaryTreeは先頭に持ってくる
				return -1;
			}
			if ($b instanceof BinaryTree && !($a instanceof BinaryTree)) {
				// BinaryTreeは先頭に持ってくる
				return 1;
			}
			if ($a instanceof ParenTree && $b instanceof ParenTree) {
				// ParenTree同士は再帰的に比較
				return self::order($a->child, $b->child);
			}
			if ($a instanceof ParenTree && !($b instanceof ParenTree)) {
				// ParenTreeは先頭に持ってくる
				return -1;
			}
			if ($b instanceof ParenTree && !($a instanceof ParenTree)) {
				// ParenTreeは先頭に持ってくる
				return 1;
			}
			// BinaryTree同士はlevelの降順にする
			if ($a->level !== $b->level) {
				return $b->level - $a->level;
			}
			// 同じlevelならsqlOpの昇順にする
			if ($a->sqlOp !== $b->sqlOp) {
				return $a->sqlOp <=> $b->sqlOp;
			}
			// 同じlevelかつ同じsqlOpなら項数について降順にする
			if ($a->children !== $b->children) {
				return count($b->children) - count($a->children);
			}
			// それ以外は並べ替えない
			return -1;
		}

		public function __construct(array $children, string $queryOp, int $level, bool $fixfirst = false) {
			$this->queryOp = $queryOp;
			$this->level = $level;

			$unsortChildren = [];
			foreach ($children as $child) {
				if ($child instanceof ParenTree) {
					// 括弧内の演算がthisと同じ演算もしくはTagTreeなら展開する
					if ($child->child instanceof BinaryTree && $queryOp === $child->child->queryOp) {
						array_push($unsortChildren, ...$child->child->children);
					}
					else if ($child->child instanceof TagTree) {
						$unsortChildren[] = $child->child;
					}
					else {
						$unsortChildren[] = $child;
					}
				}
				else {
					$unsortChildren[] = $child;
				}
			}

			if ($fixfirst) {
				// 先頭要素のみ並べ替えないときはあらかじめ除外してからソートする
				$first = array_shift($unsortChildren);
				uasort($unsortChildren, 'self::order');
				$this->children[] = $first;
				array_push($this->children, ...$unsortChildren);
			}
			else {
				uasort($unsortChildren, 'self::order');
				array_push($this->children, ...$unsortChildren);
			}
		}

		/**
		 * 検索結果を取得するSQLの取得
		 * @param $seq SQLの構築の際に用いるシーケンス
		 */
		public function sql(int &$seq): string {
			$result = $this->children[0]->sql($seq);
			if (count($this->children) > 0) {
				// 各クエリに対してsqlForBinOpを実行して連結
				for ($i = 1; $i < count($this->children); ++$i) {
					$result = $this->sqlForBinOp($result, $this->children[$i], $seq);
				}
			}
			return $result;
		}

		/**
		 * 2項演算のためのSQL
		 * @param $lhs 左項
		 * @param $rhs 右項
		 * @param $seq SQLの構築の際に用いるシーケンス
		 */
		protected abstract function sqlForBinOp(string $lhs, QueryTree $rhs, int &$seq): string;

		/**
		 * フラット化したバインド変数の取得
		 * @return array<string>
		 */
		public function bindVal(): array {
			return array_reduce($this->children, function (array $carry, QueryTree $item) {
				array_push($carry, ...($item->bindVal()));
				return $carry;
			}, []);
		}

		/**
		 * 同等の構文木を生成するクエリの取得
		 */
		public function query(): string {
			$child = $this->children[0];
			$result = $child->query();
			if (count($this->children) > 0) {
				// 子の方が演算の優先順位が低いときは括弧で囲う
				if ($child instanceof BinaryTree && $this->level > $child->level) {
					$result = "({$result})";
				}
				// 各クエリを$queryOpで連結
				for ($i = 1; $i < count($this->children); ++$i) {
					$child = $this->children[$i];
					$query = $child->query();
					// 子の方が演算の優先順位が低いときは括弧で囲う
					if ($child instanceof BinaryTree && $this->level > $child->level) {
						$query = "({$query})";
					}
					$result .= "{$this->queryOp}{$query}";
				}
			}
			return $result;
		}
	}

	/**
	 * AND検索についてのツリー
	 */
	class AndTree extends BinaryTree {
		/** 検索結果のクエリの一部を得るためのSQL */
		private const SQL_PART_SELECT = 'SELECT t%d.article_id FROM (%s) AS t%d INNER JOIN (%s) AS t%d ON t%d.article_id = t%d.article_id';

		public function __construct(array $children) {
			parent::__construct($children, ' ', 2);
		}

		/**
		 * 2項演算のためのSQL
		 * @param $lhs 左項
		 * @param $rhs 右項
		 * @param $seq SQLの構築の際に用いるシーケンス
		 */
		protected function sqlForBinOp(string $lhs, QueryTree $rhs, int &$seq): string {
			$t1 = $seq++;
			$t2 = $seq++;
			return sprintf(self::SQL_PART_SELECT, $t1, $lhs, $t1, $rhs->sql($seq), $t2, $t1, $t2);
			// MySQL 8.0以降の場合
			//return "({$lhs}) INTERSECT ({$rhs->sql($seq)})";
		}
	}

	/**
	 * OR検索についてのツリー
	 */
	class OrTree extends BinaryTree {
		public function __construct(array $children) {
			parent::__construct($children, 'OR', 1);
		}

		/**
		 * 2項演算のためのSQL
		 * @param $lhs 左項
		 * @param $rhs 右項
		 * @param $seq SQLの構築の際に用いるシーケンス
		 */
		protected function sqlForBinOp(string $lhs, QueryTree $rhs, int &$seq): string {
			return "({$lhs}) UNION ({$rhs->sql($seq)})";
		}
	}

	/**
	 * マイナス検索についてのツリー
	 */
	class MinusTree extends BinaryTree {
		/** 検索結果のクエリの一部を得るためのSQL */
		private const SQL_PART_SELECT = 'SELECT article_id FROM (%s) AS t%d WHERE article_id NOT IN (%s)';

		public function __construct(array $children) {
			parent::__construct($children, '-', 1, true);
		}

		/**
		 * 2項演算のためのSQL
		 * @param $lhs 左項
		 * @param $rhs 右項
		 * @param $seq SQLの構築の際に用いるシーケンス
		 */
		protected function sqlForBinOp(string $lhs, QueryTree $rhs, int &$seq): string {
			$t1 = $seq++;
			return sprintf(self::SQL_PART_SELECT, $lhs, $t1, $rhs->sql($seq));
			// MySQL 8.0以降の場合
			//return "({$lhs}) EXCEPT ({$rhs->sql($seq)})";
		}
	}

	/**
	 * 括弧についてのツリー
	 */
	class ParenTree implements QueryTree {
		/** 子要素 */
		public QueryTree $child;

		public function __construct(QueryTree $child) {
			// 括弧を簡約化する
			$this->child = $child instanceof ParenTree ? $child->child : $child;
		}

		/**
		 * 検索結果を取得するSQLの取得
		 * @param $seq SQLの構築の際に用いるシーケンス
		 */
		public function sql(int &$seq): string {
			return "{$this->child->sql($seq)}";
		}

		/**
		 * フラット化したバインド変数の取得
		 * @return array<string>
		 */
		public function bindVal(): array {
			return $this->child->bindVal();
		}

		/**
		 * 同等の構文木を生成するクエリの取得
		 */
		public function query(): string {
			$query = $this->child->query();
			// 子がTagTreeのときは括弧を外す
			return $this->child instanceof TagTree ? $query : "({$query})";
		}
	}

	/**
	 * タグ検索を行うためのクエリを構築するためのオブジェクト
	 */
	class BuildQueryOfTagSearch {
		/** @var int 投稿日時の昇順 */
		const ASC_POSTDATE = 0;
		/** @var int 更新日時の昇順 */
		const ASC_UPDATEDATE = 1;
		/** @var int 投稿日時の降順 */
		const DESC_POSTDATE = 2;
		/** @var int 更新日時の降順 */
		const DESC_UPDATEDATE = 3;
		/** @var array<int, string> SQLの並べ替え部の生成 */
		private const ORDER_MAP = [
			self::ASC_POSTDATE => 'ORDER BY posted_articles.id ASC',
			self::ASC_UPDATEDATE => 'ORDER BY posted_articles.update_date ASC',
			self::DESC_POSTDATE => 'ORDER BY posted_articles.id DESC',
			self::DESC_UPDATEDATE => 'ORDER BY posted_articles.update_date DESC'
		];
		/** 検索結果を得るためのSQL */
		private const SQL_SELECT = 'SELECT posted_articles.id FROM posted_articles INNER JOIN (%s) AS r ON posted_articles.id = r.article_id %s LIMIT %d OFFSET %d';
		/** 検索対象全体の件数を得るためのSQL */
		private const SQL_COUNT = 'SELECT COUNT(*) FROM (%s) AS r';
		/** 検索結果を得るためのSQL(クエリが空の場合) */
		private const SQL_SELECT_EMPTY = 'SELECT posted_articles.id FROM posted_articles %s LIMIT %d OFFSET %d';
		/** 検索対象全体の件数を得るためのSQL(クエリが空の場合) */
		private const SQL_COUNT_EMPTY = 'SELECT COUNT(*) FROM posted_articles';
	
		/** タグ検索に入力されている文字列 */
		private string $query;
		/** 検索に用いるタグ数の制限 */
		private int $limitTag;
		/** クエリ文字列の長さ */
		private int $len;
		/** 現在の文字の解析位置 */
		private int $pos = 0;
		/** 現在解析中のトークン */
		private ?array $currentToken = null;
		/** 出現したタグの数のカウント */
		private int $countTag = 0;
		/** クエリに関する構文木 */
		private ?QueryTree $tree;
		
		/**
		 * トークンを正規化する
		 */
		public static function normToken(string $token) {
			return mb_strtoupper(normalizer_normalize(trim($token), Normalizer::FORM_KC));
		}
		
		public function __construct(string $query, int $limitTag = -1) {
			$this->query = $query;
			$this->limitTag = $limitTag;
			$this->len = mb_strlen($query);
			$this->getNextToken();
			$this->tree = $this->expr();
		}
		
		/**
		 * 現在のトークンを構成する文字の取得
		 */
		private function getCurrentChar(): ?string {
			return $this->pos < $this->len ? mb_substr($this->query, $this->pos, 1) : null;
		}
	
		/**
		 * 次のトークンを構成する文字の取得
		 */
		private function getNextChar(): ?string {
			return $this->pos < $this->len ? mb_substr($this->query, ++$this->pos, 1) : null;
		}
		
		/**
		 * トークンを1つ先読みする
		 */
		private function lookahead(): ?string {
			return $this->pos + 1 < $this->len ? mb_substr($this->query, $this->pos + 1, 1) : null;
		}
		
		/**
		 * 次のトークンの取得
		 */
		private function getNextToken(): ?array {
			$str = '';
			$c = $this->getCurrentChar();
			do {
				switch ($t = trim($c)) {
					case '':
						// 単語区切りの場合は終了
						if ($str !== '') {
							$this->getNextChar();
							return $this->currentToken = ['type' => 'other', 'str' => self::normToken($str)];
						}
						break;
					case '(':
					case ')':
					case '-':
						if ($str !== '') {
							return $this->currentToken = ['type' => 'other', 'str' => self::normToken($str)];
						}
						$this->getNextChar();
						return $this->currentToken = ['type' => 'other', 'str' => $t];
					case '"':
						if ($str !== '') {
							return $this->currentToken = ['type' => 'other', 'str' => self::normToken($str)];
						}
						// 次のダブルクォートが出現するまでトークンを解析
						while ($c = $this->getNextChar()) {
							if ($c === '"') {
								if ($this->lookahead() === '"') {
									// ダブルクォートで囲まれた文字列内でダブルクォートが2つ並んでいるときはダブルクォートとして解釈
									$str .= '"';
									$this->getNextChar();
								}
								else {
									if ($str !== '') {
										$this->getNextChar();
										return $this->currentToken = ['type' => 'tag', 'str' => self::normToken($str)];
									}
								}
							}
							else {
								$str .= $c;
							}
						}
						// ダブルクォートが閉じられていないときは補完する
						return $this->currentToken = ($str !== '' ? ['type' => 'tag', 'str' => self::normToken($str)] : null);
					default:
						$str .= $c;
						break;
				}
			} while ($c = $this->getNextChar());
			
			// 終端のトークン
			return $this->currentToken = ($str !== '' ? ['type' => 'other', 'str' => self::normToken($str)] : null);
		}
		
		/**
		 * 現在のトークンの取得
		 */
		private function getCurrentToken(): ?array {
			return $this->currentToken;
		}
		
		private function expr() {
			// クエリ演算子とSQLの演算子の対応付け
			$opArray = ['OR', '-'];
			
			$result = [$opArray[0] => [$this->term()], $opArray[1] => []];
			while ($token = $this->getCurrentToken()) {
				if ($token['type'] !== 'tag') {
					if (in_array($token['str'], $opArray)) {
						// タグ検索の演算からSQLの演算に変換する
						$this->getNextToken();
						$temp = $this->term();
						if ($temp !== null) {
							$result[$token['str']][] = $temp;
						}
						else break;
					}
					else break;
				}
				else break;
			}
			// 構文木を構築
			$tree = count($result[$opArray[0]]) === 1 ? $result[$opArray[0]][0] : new OrTree($result[$opArray[0]]);
			if (count($result[$opArray[1]]) === 0) {
				// OR検索もマイナス検索もなかった場合/OR検索のみあった場合
				return $tree;
			}
			else {
				// マイナス検索のみあった場合/OR検索とマイナス検索の両方があった場合
				return new MinusTree([$tree, ...$result[$opArray[1]]]);
			}
		}
		
		private function term() {
			if ($this->getCurrentToken() === null) return null;
	
			$result = [$this->fact()];
			while ($token = $this->getCurrentToken()) {
				// ORと-と括弧は弾く
				if ($token['type'] !== 'tag' && in_array($token['str'], ['OR', '-', ')'], true)) {
					break;
				}
				// 共通集合をとる
				$temp = $this->fact();
				if ($temp !== null) {
					$result[] = $temp;
				}
				else break;
			}
			// 構文木を構築
			return count($result) === 1 ? $result[0] : new AndTree($result);
		} 
		
		private function fact() {
			$token = $this->getCurrentToken();
			if ($token === null) return null;
	
			if ($token['type'] !== 'tag' && $token['str'] === '(') {
				$this->getNextToken();
				$temp = $this->expr();
				// 括弧が閉じられていないときは補完する
				$token = $this->getCurrentToken();
				if ($token !== null && $token['type'] !== 'tag' && $token['str'] === ')') {
					$this->getNextToken();
				}
				return $temp === null ? null : new ParenTree($temp);
			}
			else {
				$this->getNextToken();
				// 利用可能なタグの数に制限をかける
				if ($this->limitTag >= 0 && $this->countTag >= $this->limitTag) {
					return null;
				}
				
				++$this->countTag;
				return new TagTree($token['str']);
			}
		}
		
		/**
		 * 検索結果を取得するSQLの取得
		 * @param int $limit 取得する件数
		 * @param int $offset 取得対象のオフセット
		 * @param int $order 並べ替えのルールについての識別子
		 */
		public function select(int $limit, int $offset, int $order) {
			if (!array_key_exists($order, self::ORDER_MAP)) {
				throw new \Exception("不明な並べ替えの指定'$order'がされました");
			}
			$seq = 0;
			return $this->tree ? sprintf(self::SQL_SELECT, $this->tree->sql($seq), self::ORDER_MAP[$order], $limit, $offset) : sprintf(self::SQL_SELECT_EMPTY, self::ORDER_MAP[$order], $limit, $offset);
		}
		
		/**
		 * 検索結果全体の件数を取得するSQLの取得
		 */
		public function count() {
			$seq = 0;
			return $this->tree ? sprintf(self::SQL_COUNT, $this->tree->sql($seq)) : self::SQL_COUNT_EMPTY;
		}

		/**
		 * 入力したクエリの正規化した文字列の取得
		 */
		public function query(): string {
			return $this->tree?->query() ?? '';
		}

		/**
		 * バインド変数の取得
		 */
		public function bindVal() {
			return $this->tree?->bindVal() ?? [];
		}
	}

	/**
	 * タグ検索におけるキャッシュを扱うためのオブジェクト
	 */
	class TagSearchCaches {
		/** キャッシュの有効期限を記録するファイル(更新系) */
		const EXPIRATION_NAME = 'expiration.json';
		/** キャッシュへ設定を記載するファイル(参照系) */
		const CONFIG_NAEM = 'config.json';

		/** キャッシュを管理するベースディレクトリ */
		private string $base;
		/** PDOを返すオブジェクト */
		private \Closure $callback;
		/** $callbackの戻り値のキャッシュ */
		private ?\PDO $pdo = null;

		public function __construct(string $base, \Closure $callback) {
			$this->base = $base;
			$this->callback = $callback;
		}

		public function getPDO(): \PDO {
			return $this->pdo = $this->pdo ?? ($this->callback)();
		}

		/**
		 * キャッシュの保存先のパスの取得
		 * @param $key キャッシュのキー
		 * @param $page キャッシュのページ
		 * @param $prefix キャッシュのページの種類を識別する接頭辞
		 */
		private function getCachePath(string $key, ?int $page = null, string $prefix = ''): string {
			$cacheBase = "{$this->base}/{$key}/";
			return $page !== null ? "{$cacheBase}{$prefix}{$page}.json" : $cacheBase;
		}

		/**
		 * キャッシュの有効期限の取得
		 * @param $key キャッシュのキー
		 * @throws \Exception 再度本関数を呼び出しても有効期限の取得ができない場合に送信される
		 */
		public function getExpirationTime(string $key) {
			$cacheBase = $this->getCachePath($key);
			$fp = @fopen($cacheBase.self::EXPIRATION_NAME, 'r');
			if ($fp === false) {
				// ファイルオープンに失敗しただけなら次の契機にキャッシュの削除を試みる
				if (file_exists($cacheBase.self::EXPIRATION_NAME)) {
					return null;
				}
				throw new \Exception('キャッシュが存在しません');
			}
			// 有効期限の取得
			$datetime = null;
			if (flock($fp, LOCK_SH | LOCK_NB)) {
				$data = stream_get_contents($fp);
				if ($data !== false) {
					$jsonIn = json_decode($data, true);
					if ($jsonIn !== null && array_key_exists('expiration', $jsonIn)) {
						$datetime = new \DateTime($jsonIn['expiration']);
					}
					else {
						fclose($fp);
						throw new \Exception('有効期限の取得に失敗しました');
					}
				}
			}
			fclose($fp);

			return $datetime;
		}

		/**
		 * キャッシュのコンフィグの取得
		 * @param $key キャッシュのキー
		 * @throws \Exception 再度本関数を呼び出してもコンフィグの取得ができない場合に送信される
		 */
		public function getConfig(string $key): array {
			$cacheBase = $this->getCachePath($key);
			if (is_file($cacheBase.self::CONFIG_NAEM)) {
				// 参照系のためfile_get_contents()で一括で取得する
				$json = json_decode(file_get_contents($cacheBase.self::CONFIG_NAEM), true);
				if ($json !== null) {
					return $json;
				}
			}
			throw new \Exception('コンフィグの取得に失敗しました');
		}

		/**
		 * キャッシュの有効期限の変更を試みる
		 * @param $key キャッシュのキー
		 * @param $isDeleteRequested DateTimeが設定されたときはその有効期限で更新する
		 */
		public function update(string $key, false|\DateTime $isDeleteRequested = false) {
			$cacheBase = $this->getCachePath($key);
			$fp = @fopen($cacheBase.self::EXPIRATION_NAME, 'r+');
			if ($fp === false) {
				return;
			}

			if (flock($fp, LOCK_EX | LOCK_NB)) {
				// 他により共有ロックがかかっているもしくは排他ロックがかかっているときはファイルを更新しない

				$jsonOut = [
					'expiration' => ($isDeleteRequested === false ? new \DateTime() : $isDeleteRequested)->format('Y-m-d H:i:s'),
					'interval' => 0
				];
				if ($isDeleteRequested === false) {
					$data = stream_get_contents($fp);
					if ($data !== false) {
						$jsonIn = json_decode($data, true);
						if ($jsonIn !== null && array_key_exists('expiration', $jsonIn) && array_key_exists('interval', $jsonIn)) {
							if ($jsonIn['interval'] === 0) {
								// 更新する必要がないときは即時終了
								fclose($fp);
								return;
							}
							$jsonOut = [
								// $jsonIn['interval']だけ有効期限を延長
								'expiration' => (new \DateTime())->modify($jsonIn['interval'].' min')->format('Y-m-d H:i:s'),
								'interval' => $jsonIn['interval']
							];
						}
					}
				}
				// JSONデータを出力(何かしら異常が発生しても放置する)
				fseek($fp, 0);
				ftruncate($fp, 0);
				fwrite($fp, json_encode($jsonOut, JSON_PRETTY_PRINT));
			}
			fclose($fp);
		}

		/**
		 * キャッシュの取得
		 * @param $key キャッシュのキー
		 * @param $page キャッシュのページ
		 * @param $prefix キャッシュのページの種類を識別する接頭辞
		 */
		public function get(string $key, int $page, string $prefix): ?array {
			if (!$this->has($key, $page, $prefix)) {
				// まだキャッシュの読み込みが不可のときはなしとして扱う
				return null;
			}

			/** @var ?array<string> 戻り値 */
			$ret = null;

			try {
				$config = $this->getConfig($key);
				if (array_key_exists('max-page', $config)) {
					if ($page <= 0 || $config['max-page'] < $page) {
						// 存在しないページへのキャッシュの取得の場合はから配列を返す
						$ret = [];
					}
					$path = $this->getCachePath($key, $page, $prefix);
					if (is_file($path)) {
						// キャッシュからファイルを取得
						$ret = json_decode(file_get_contents($path), true);
					}
				}
				else {
					throw new \Exception('キャッシュの設定ファイル形式が異常です');
				}
			}
			catch (\Exception $e) {
				// キャッシュの情報が取得異常の場合(通常は起きない)はキャッシュはないものとして扱う
				return null;
			}

			if ($ret !== null) {
				// キャッシュから何かしら取得した際は有効期限を延長する
				$this->update($key);
			}

			return $ret;
		}

		/**
		 * キャッシュが管理されているか
		 * @param $key キャッシュのキー
		 * @param $page キャッシュのページ
		 * @param $prefix キャッシュのページの種類を識別する接頭辞
		 */
		public function has(string $key, ?int $page = null, string $prefix = ''): bool {
			$cacheBase = $this->getCachePath($key);
			$f = is_dir($cacheBase) && is_file($cacheBase.self::EXPIRATION_NAME) && is_file($cacheBase.self::CONFIG_NAEM);
			return $page !== null ? $f && is_file($this->getCachePath($key, $page, $prefix)) : $f;
		}

		/**
		 * キャッシュの設定
		 * @param $key キャッシュのキー
		 * @param $page キャッシュのページ
		 * @param $prefix キャッシュのページの種類を識別する接頭辞
		 * @param $idList キャッシュとして記憶する本体
		 * @param $extendExpiration 有効期限を延長するか
		 */
		public function set(string $key, int $page, string $prefix, array $idList, bool $extendExpiration = true) {
			if (!$this->has($key)) {
				throw new \Exception('キャッシュが管理されていません');
			}
			// キャッシュを示すファイルの出力
			$path = $this->getCachePath($key, $page, $prefix);
			if (!file_put_contents($path, json_encode($idList, JSON_PRETTY_PRINT), LOCK_EX)) {
				throw new \Exception("キー{$key}かつページ{$page}のキャッシュデータの出力に失敗しました");
			}
			// キャッシュの設定が完了した際は有効期限を延長する
			if ($extendExpiration) {
				$this->update($key);
			}
		}

		/**
		 * キャッシュ情報の構築
		 * @param $key キャッシュのキー
		 * @param $tagList キャッシュに関連付けられたタグリスト
		 * @param $time キャッシュの有効期限/キャッシュの最終アクセス日時から有効期限までのインターバル
		 * @param $config キャッシュのコンフィグ
		 */
		public function create(string $key, array $tagList, \DateTime|int $time, array $config) {
			// 以下の順でキャッシュを構築
			// 1. DB
			// 2. キャッシュのフォルダ
			// 3. 有効期限
			// 4. コンフィグ

			// 有効期限の計算
			$expiration = ($time instanceof \DateTime ? $time : (new \DateTime())->modify("{$time} min"))->format('Y-m-d H:i:s');
			$interval = $time instanceof \DateTime ? 0 : $time;

			// DBのキャッシュデータの登録
			$pdo = $this->getPDO();
			$pdo->beginTransaction();
			try {
				$stmt = $pdo->prepare('SELECT id FROM tag_search_caches WHERE id = ?');
				$stmt->execute([$key]);
				if ($stmt->rowCount() === 0) {
					// DBにキャッシュデータを登録
					$pdo->prepare('INSERT INTO tag_search_caches(id, expiration_time) VALUES (?, ?)')->execute([$key, $expiration]);
					if (count($tagList) > 0) {
						$pdo->prepare('INSERT INTO tag_search_caches_tags(cache_id, tag_id) SELECT ? AS cache_id, tags.id AS tag_id FROM tags WHERE tags.norm_name IN ('.implode(',', array_fill(0, count($tagList), '?')).')')->execute([$key, ...$tagList]);
					}
				}
				else {
					// 何か異常があってDBデータの削除のみ失敗した場合は更新のみ行う
					$pdo->prepare('UPDATE tag_search_caches SET expiration_time = ? WHERE id = ?')->execute([$expiration, $key]);
				}
				$pdo->commit();
			}
			catch (\PDOException $e) {
				$pdo->rollBack();
				throw $e;
			}

			// キャッシュのフォルダの構築
			$cacheBase = $this->getCachePath($key);
			if (!is_dir($cacheBase) && !mkdir($cacheBase, 0777, true)) {
				throw new \Exception("キー{$key}のキャッシュを管理するフォルダの作成に失敗しました");
			}

			// 有効期限に関する設定ファイルの構築
			if (!file_put_contents($cacheBase.self::EXPIRATION_NAME, json_encode([
				'expiration' => $expiration,
				'interval' => $interval
			], JSON_PRETTY_PRINT), LOCK_EX)) {
				throw new \Exception("キー{$key}の有効期限に関する設定ファイルの出力に失敗しました");
			}

			// コンフィグの構築
			if (!file_put_contents($cacheBase.self::CONFIG_NAEM, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX)) {
				throw new \Exception("キー{$key}のコンフィグの出力に失敗しました");
			}
		}

		/**
		 * キャッシュの削除のためにキャッシュを管理しているフォルダをリネームする
		 */
		private function renameToDelete(string $key, \DateTime $now) {
			// リネーム先のフォルダ名の計算({$key}.{$datetime}の形式のフォルダ名にする)
			$cacheBase = substr($this->getCachePath($key), 0, -1);
			return rename($cacheBase, $cacheBase.'.'.$now->format('YmdHis'));
		}

		/**
		 * 単一のキャッシュデータの削除
		 * @param $cacheBase キャッシュが保存されているフォルダ
		 */
		private function deleteSingleCacheFile(string $cacheBase) {
			$isDeleteRequested = true;
			// ページごとのキャッシュを削除
			$cacheDatas = glob($cacheBase.'/*');
			if ($cacheDatas !== false) {
				foreach ($cacheDatas as $cache) {
					if (!unlink($cache)) {
						// 何かしらの理由でキャッシュの削除に失敗したときは次の契機で削除されるようにする
						$isDeleteRequested = false;
						break;
					}
				}
			}
			if ($isDeleteRequested) {
				// キャッシュを管理するフォルダの削除
				if (!rmdir($cacheBase)) {
					$isDeleteRequested = false;
				}
			}
			return $isDeleteRequested;
		}

		/**
		 * キャッシュの削除のためにリネームされたフォルダの削除
		 */
		public function deleteCacheFile() {
			// リネームしたキャッシュのフォルダを削除する
			$cacheDatas = glob("{$this->base}/*.*");
			if ($cacheDatas !== false) {
				foreach ($cacheDatas as $cache) {
					if (!$this->deleteSingleCacheFile($cache)) {
						// 何かしらで削除時に異常が発生(通常は起きない)が起きたときは無視
					}
				}
			}
		}

		/**
		 * トランザクション中で実施されるキャッシュの削除
		 * @param $key キャッシュのキー
		 * @param $now 削除の基準となる日時
		 * @param $updateTagSearchCachesStmt tag_search_cachesのレコードを更新するステートメント
		 * @param $deleteTagSearchCachesStmt tag_search_cachesからレコードを削除するステートメント
		 * @param $deleteTagSearchCachesTagsStmt tag_search_caches_tagsからレコードを削除するステートメント
		 */
		public function deleteCacheDuringTransaction(string $key, \DateTime $now, \PDOStatement $updateTagSearchCachesStmt, \PDOStatement $deleteTagSearchCachesStmt, \PDOStatement $deleteTagSearchCachesTagsStmt) {
			if (!$this->renameToDelete($key, $now)) {
				// キャッシュのフォルダ内のファイルが参照されているときは次の契機で削除する
				$datetime = $now->format('Y-m-d H:i:s');
				$this->update($key, $now);
				$updateTagSearchCachesStmt->execute([$datetime, $key]);
			}
			else {
				// キャッシュにはアクセス不可となったためDBからも削除
				$deleteTagSearchCachesTagsStmt->execute([$key]);
				$deleteTagSearchCachesStmt->execute([$key]);
			}
		}

		/**
		 * タグを指定することによるキャッシュの削除
		 * @param $tag キャッシュの削除対象となるタグ
		 * @param $now 削除の基準となる日時
		 */
		public function deleteByTag(string $tag, \DateTime $now = new \DateTime()) {
			$pdo = $this->getPDO();
			$updateTagSearchCachesStmt = $pdo->prepare('UPDATE tag_search_caches SET expiration_time = ? WHERE id = ?');
			$deleteTagSearchCachesStmt = $pdo->prepare('DELETE FROM tag_search_caches WHERE id = ?');
			$deleteTagSearchCachesTagsStmt = $pdo->prepare('DELETE FROM tag_search_caches_tags WHERE cache_id = ?');

			$pdo->beginTransaction();
			try {
				// 削除対象の候補を一時テーブルに積む
				$pdo->prepare('CREATE TEMPORARY TABLE delete_caches AS SELECT DISTINCT t1.cache_id AS id FROM tag_search_caches_tags AS t1 JOIN tags AS t2 ON t1.tag_id = t2.id WHERE t2.norm_name = ?')->execute([$tag]);

				// 選択した対象をすべて削除
				$stmt = $pdo->query('SELECT * FROM delete_caches');
				while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
					$key = $row['id'];
					$this->deleteCacheDuringTransaction($key, $now, $updateTagSearchCachesStmt, $deleteTagSearchCachesStmt, $deleteTagSearchCachesTagsStmt);
				}
				$pdo->exec('DROP TEMPORARY TABLE delete_caches');
				$pdo->commit();
			}
			catch (\PDOException $e) {
				$pdo->rollBack();
				throw $e;
			}
		}

		/**
		 * 有効期限によるキャッシュの削除
		 * @param $now 削除の基準となる日時
		 */
		public function deleteByDatetime(\DateTime $now = new \DateTime()) {
			$pdo = $this->getPDO();
			$updateTagSearchCachesStmt = $pdo->prepare('UPDATE tag_search_caches SET expiration_time = ? WHERE id = ?');
			$deleteTagSearchCachesStmt = $pdo->prepare('DELETE FROM tag_search_caches WHERE id = ?');
			$deleteTagSearchCachesTagsStmt = $pdo->prepare('DELETE FROM tag_search_caches_tags WHERE cache_id = ?');

			$pdo->beginTransaction();
			try {
				// 削除対象の候補を一時テーブルに積む
				$pdo->prepare('CREATE TEMPORARY TABLE delete_caches AS SELECT id FROM tag_search_caches WHERE expiration_time <= ?')->execute([$now->format('Y-m-d H:i:s')]);

				// 選択した対象をすべて削除
				$stmt = $pdo->query('SELECT * FROM delete_caches');
				while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
					$key = $row['id'];
					try {
						// キャッシュの有効期限の取得
						$datetime = $this->getExpirationTime($key);
						if ($datetime === null) {
							continue;
						}
						if ($datetime <= $now) {
							// 古い場合はキャッシュの削除
							$this->deleteCacheDuringTransaction($key, $now, $updateTagSearchCachesStmt, $deleteTagSearchCachesStmt, $deleteTagSearchCachesTagsStmt);
						}
						else {
							// キャッシュの管理レコードの有効期限の更新
							$updateTagSearchCachesStmt->execute([$datetime->format('Y-m-d H:i:s'), $key]);
						}
					}
					catch (\Exception $e) {
						// キャッシュの有効期限の取得が不可の場合
						$this->deleteCacheDuringTransaction($key, $now, $updateTagSearchCachesStmt, $deleteTagSearchCachesStmt, $deleteTagSearchCachesTagsStmt);
					}
				}
				$pdo->exec('DROP TEMPORARY TABLE delete_caches');
				$pdo->commit();
			}
			catch (PDOException $e) {
				$pdo->rollBack();
				throw $e;
			}
		}
	}

	/**
	 * 実際に検索を行うクラス
	 */
	class Query {
		/** クエリで取得する最大件数 */
		const MAX_SHOW_COUNT = 10;
		/** クエリを構築するためのオブジェクト */
		private \TagSearchCaches $cacheTable;
		/** PDOを返すオブジェクト */
		private \Closure $callback;
		/** $callbackの戻り値のキャッシュ */
		private ?\PDO $pdo = null;

		/**
		 * トークンを正規化する
		 */
		public static function normToken(string $token) {
			return mb_strtoupper(normalizer_normalize(trim($token), Normalizer::FORM_KC));
		}

		public function __construct(\TagSearchCaches $cacheTable, \Closure $callback) {
			$this->cacheTable = $cacheTable;
			$this->callback = $callback;
		}

		public function getPDO(): \PDO {
			return $this->pdo = $this->pdo ?? ($this->callback)();
		}

		/**
		 * 検索結果をもとに初期状態の有効期限の取得
		 */
		private function getExpiration(array $idList, int $count, array $bindVal): int|\DateTime {
			if (count($bindVal) === 0 || (count($bindVal) === 1 && $count > 0)) {
				// クエリが空もしくは単一タグかつ検索結果が存在するときは有効期限を事実上の無期限にする
				return new \DateTime('9999-01-01 00:00:00');
			}
			if ($count === 0) {
				// 検索結果が存在しないときは有効期限を15分とする
				return 15;
			}
			// 上記以外は有効期限を1週間とする
			return 7 * 24 * 60;
		}

		/**
		 * クエリの結果の取得
		 * @param $builder クエリを構築するためのオブジェクト
		 * @param $page ページ(オフセットは1)
		 * @param $order 並べ替え
		 * @throws \PDOException DBからの検索結果の取得に失敗した際に送信される
		 */
		public function get(\BuildQueryOfTagSearch $builder, int $page, int $order): array {
			$count = null;
			$idList = null;
			// 正規化したクエリからキャッシュのキーを計算
			$key = hash('sha256', $builder->query());
			$prefix = "{$order}.";

			if ($this->cacheTable->has($key)) {
				// キャッシュが存在するなら全体件数を得る
				try {
					$count = $this->cacheTable->getConfig($key)['count'];
					$idList = $this->cacheTable->get($key, $page, $prefix);
				}
				catch (\Exception $e) {
					// キャッシュの情報が取得異常の場合(通常は起きない)はキャッシュはないものとして扱う
					// 本来はログなどでイベントの管理はした方がいい
					$count = null;
					$idList = null;
				}
			}

			if ($idList === null) {
				// 検索結果を取得するクエリを実行
				$pdo = $this->getPDO();
				$stmt = $pdo->prepare($builder->select(self::MAX_SHOW_COUNT, ($page - 1) * self::MAX_SHOW_COUNT, $order));
				$stmt->execute($builder->bindVal());
				$idList = array_map(fn (array $arr) => $arr[0], $stmt->fetchAll(\PDO::FETCH_NUM));
				unset($stmt);
			}
			if ($count === null) {
				// 全体件数を取得するクエリを実行
				$pdo = $this->getPDO();
				$stmt = $pdo->prepare($builder->count());
				$stmt->execute($builder->bindVal());
				$count = $stmt->fetch(\PDO::FETCH_NUM)[0];
				unset($stmt);
			}

			if (!$this->cacheTable->has($key)) {
				try {
					// 検索結果をもとに有効期限を定義
					// キャッシュが存在しないときは新規作成
					$this->cacheTable->create(
						$key,
						$builder->bindVal(),
						$this->getExpiration($idList, $count, $builder->bindVal()),
						[
							'count' => $count,
							'max-page' => floor(($count - 1) / self::MAX_SHOW_COUNT) + 1
						]
					);
				}
				catch (\Exception $e) {
					// キャッシュ構築時の異常は外部から対処不要のため揉み消す
					// 本来はログなどでイベントの管理はした方がいい
				}
			}
			if (!$this->cacheTable->has($key, $page, $prefix)) {
				try {
					// クエリを実施したページについてキャッシュが存在しないときは設定
					$this->cacheTable->set(
						$key,
						$page,
						$prefix,
						$idList, false
					);
				}
				catch (\Exception $e) {
					// キャッシュ構築時の異常は外部から対処不要のため揉み消す
					// 本来はログなどでイベントの管理はした方がいい
				}
			}

			return ['id-list' => $idList, 'count'=> $count];
		}

		/**
		 * クエリ結果に出現するようにタグ情報を登録する
		 * @param $id 記事のキー
		 * @param $postDate 投稿日時
		 * @param $updateDate 更新日時
		 * @param $tagList タグのリスト
		 * @param $updateCache trueのときキャッシュを更新する
		 */
		public function set(int $id, string $postDate, string $updateDate, array $tagList, bool $updateCache = true) {
			$pdo = $this->getPDO();
			// タグ情報を挿入するステートメント
			$insertPostedArticlesStmt = $pdo->prepare('INSERT INTO posted_articles(id, post_date, update_date) VALUES (:id, :post_date, :update_date)');
			$updatePostedArticlesStmt = $pdo->prepare('UPDATE posted_articles SET post_date = :post_date, update_date = :update_date WHERE id = :id');
			$insertTagsStmt = $pdo->prepare('INSERT INTO tags(id, org_name, norm_name) VALUES (:id, :org_name, :norm_name)');
			$insertPostedArticlesTagsStmt = $pdo->prepare('INSERT INTO posted_articles_tags(article_id, tag_id) VALUES (:article_id, :tag_id)');
			// タグを選択するステートメント
			$selectTagsIdStmt = $pdo->prepare('SELECT id FROM tags WHERE norm_name = :norm_name');
			$selectTagsListStmt = $pdo->prepare('SELECT t2.norm_name FROM posted_articles_tags AS t1 JOIN tags AS t2 ON t1.tag_id = t2.id WHERE t1.article_id = :id');
			// 記事を選択するステートメント
			$selectArticleIdStmt = $pdo->prepare('SELECT id FROM posted_articles WHERE id = :id');

			// 変更があったタグのリスト
			$changeTagList = [];

			$pdo->beginTransaction();
			// 記事情報の登録
			try {
				$selectArticleIdStmt->bindValue(':id', $id, \PDO::PARAM_INT);
				$selectArticleIdStmt->execute();
				// 検索対象をInsertするかUpdateするかの選択
				$insertPostedArticleFlag = $selectArticleIdStmt->rowCount() === 0;
				$mergePostedArticleStmt = $insertPostedArticleFlag ? $insertPostedArticlesStmt : $updatePostedArticlesStmt;

				// すでに登録済みのタグのリスト
				$beforeTagList = [];
				if (!$insertPostedArticleFlag) {
					$selectTagsListStmt->bindValue(':id', $id, \PDO::PARAM_INT);
					$selectTagsListStmt->execute();
					$beforeTagList = array_map(fn ($tag) => self::normToken($tag[0]), $selectTagsListStmt->fetchAll(\PDO::FETCH_NUM));
				}
				// 新規に挿入するタグリストと削除するタグリスト
				$normTagList = array_map(fn ($tag) => self::normToken($tag), $tagList);
				$insertTagList = [...array_diff($normTagList, $beforeTagList)];
				$deleteTagList = [...array_diff($beforeTagList, $normTagList)];
				$changeTagList = [...$insertTagList, ...$deleteTagList];

				// 検索対象の登録
				$mergePostedArticleStmt->bindValue(':id', $id, \PDO::PARAM_INT);
				$mergePostedArticleStmt->bindValue(':post_date', $postDate, \PDO::PARAM_STR);
				$mergePostedArticleStmt->bindValue(':update_date', $updateDate, \PDO::PARAM_STR);
				$mergePostedArticleStmt->execute();

				// タグ情報の登録
				$cnt = 0;
				foreach ($insertTagList as $tag) {
					$selectTagsIdStmt->bindValue(':norm_name', $tag, \PDO::PARAM_STR);
					$selectTagsIdStmt->execute();
					// tag_idの取得
					if ($selectTagsIdStmt->rowCount() === 0) {
						$tagId = $id * 100 + (++$cnt);
						// tagsへの挿入
						$insertTagsStmt->bindValue(':id', $tagId, \PDO::PARAM_INT);
						$insertTagsStmt->bindValue(':org_name', trim($tag), \PDO::PARAM_STR);
						$insertTagsStmt->bindValue(':norm_name', $tag, \PDO::PARAM_STR);
						$insertTagsStmt->execute();
					}
					else {
						$tagId = (int)$selectTagsIdStmt->fetch()['id'];
					}
					// posted_articles_tagsへの挿入
					$insertPostedArticlesTagsStmt->bindValue(':article_id', $id, \PDO::PARAM_INT);
					$insertPostedArticlesTagsStmt->bindValue(':tag_id', $tagId, \PDO::PARAM_INT);
					$insertPostedArticlesTagsStmt->execute();
				}
				// タグ情報の削除
				if (count($deleteTagList) > 0) {
					$stmt = $pdo->prepare('DELETE FROM posted_articles_tags WHERE article_id = ? AND tag_id IN (SELECT id FROM tags WHERE norm_name IN ('.implode(',', array_fill(0, count($deleteTagList), '?')).'))');
					$stmt->bindValue(1, $id, \PDO::PARAM_INT);
					for ($i = 0; $i < count($deleteTagList); ++$i) {
						$stmt->bindValue(2 + $i, $deleteTagList[$i], \PDO::PARAM_STR);
					}
					$stmt->execute();
				}

				$pdo->commit();
			}
			catch (\PDOException $e) {
				$pdo->rollBack();
				throw $e;
			}

			if ($updateCache) {
				// 変更に係るタグに関連するキャッシュの削除
				// 本来ならば状態に応じて部分的な更新をするような最適化が実施されるべき
				foreach ($changeTagList as $tag) {
					try {
						$this->cacheTable->deleteByTag($tag);
					}
					catch (\Exception $e) {
						// キャッシュ更新時の異常は外部から対処不要のため揉み消す
						// 本来はログなどでイベントの管理はした方がいい
					}
				}
			}
		}

		/**
		 * 検索情報を削除する
		 * @param $id 記事のキー
		 */
		public function delete(int $id) {
			$pdo = $this->getPDO();
			$pdo->beginTransaction();

			// 削除対象のタグの取得
			$selectTagsListStmt = $pdo->prepare('SELECT t2.norm_name FROM posted_articles_tags AS t1 JOIN tags AS t2 ON t1.tag_id = t2.id WHERE t1.article_id = :id');
			$selectTagsListStmt->bindValue(':id', $id, \PDO::PARAM_INT);
			$selectTagsListStmt->execute();
			$deleteTagList = array_map(fn ($tag) => self::normToken($tag[0]), $selectTagsListStmt->fetchAll(\PDO::FETCH_NUM));

			// 記事情報の削除
			try {
				// 記事とタグの関連付けの破棄
				$stmt = $pdo->prepare('DELETE FROM posted_articles_tags WHERE article_id = :id');
				$stmt->bindValue(':id', $id, \PDO::PARAM_INT);
				$stmt->execute();
				// 記事情報の破棄
				$stmt = $pdo->prepare('DELETE FROM posted_articles WHERE id = :id');
				$stmt->bindValue(':id', $id, \PDO::PARAM_INT);
				$stmt->execute();
				
				$pdo->commit();
			}
			catch (\PDOException $e) {
				$pdo->rollBack();
				throw $e;
			}

			// 削除の場合はキャッシュの更新を強制する
			{
				// 変更に係るタグに関連するキャッシュの削除
				// 本来ならば状態に応じて部分的な更新をするような最適化が実施されるべき
				foreach ($deleteTagList as $tag) {
					try {
						$this->cacheTable->deleteByTag($tag);
					}
					catch (\Exception $e) {
						// キャッシュ更新時の異常は外部から対処不要のため揉み消す
						// 本来はログなどでイベントの管理はした方がいい
					}
				}
			}
		}
	}