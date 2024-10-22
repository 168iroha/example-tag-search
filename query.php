<?php
	declare(strict_types=1);
	require_once 'TagSearch.php';

	/**
	 * PDOを取得する
	 */
	function getPDO(string $path, bool &$execQuery) {
		$execQuery = true;

		$content = @file_get_contents($path);
		if ($content === false) {
			throw new \Exception("{$path}の読み込みに失敗しました");
		}
		$json = json_decode($content, true);
		$dns = $json['db'].":";
		// 接続情報に関する文字列の構築(簡単のために特に検査はしない)
		foreach (['dbname', 'host', 'charset'] as $key) {
			if (isset($json[$key])) {
				$dns .= $key.'='.$json[$key].';';
			}
		}
	
		// データベースに接続
		return new PDO(
			$dns,
			$json['username'],
			$json['password'],
			[
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			]
		);
	}

	/**
	 * 記事情報を登録する
	 * @param $pdo PDO
	 * @param $id 記事のキー
	 * @param $postDate 投稿日時
	 * @param $updateDate 更新日時
	 */
	function setArticle(\PDO $pdo, int $id, string $postDate, string $updateDate) {
		// 記事を挿入するステートメント
		$insertPostedArticlesStmt = $pdo->prepare('INSERT INTO posted_articles(id, post_date, update_date) VALUES (:id, :post_date, :update_date)');
		$updatePostedArticlesStmt = $pdo->prepare('UPDATE posted_articles SET post_date = :post_date, update_date = :update_date WHERE id = :id');
		// 記事を選択するステートメント
		$selectArticleIdStmt = $pdo->prepare('SELECT id FROM posted_articles WHERE id = :id');

		$pdo->beginTransaction();
		try {
			$selectArticleIdStmt->bindValue(':id', $id, \PDO::PARAM_INT);
			$selectArticleIdStmt->execute();
			// 記事をInsertするかUpdateするかの選択
			$insertPostedArticleFlag = $selectArticleIdStmt->rowCount() === 0;
			$mergePostedArticleStmt = $insertPostedArticleFlag ? $insertPostedArticlesStmt : $updatePostedArticlesStmt;

			// 記事の登録
			$mergePostedArticleStmt->bindValue(':id', $id, \PDO::PARAM_INT);
			$mergePostedArticleStmt->bindValue(':post_date', $postDate, \PDO::PARAM_STR);
			$mergePostedArticleStmt->bindValue(':update_date', $updateDate, \PDO::PARAM_STR);
			$mergePostedArticleStmt->execute();

			$pdo->commit();
		}
		catch (\PDOException $e) {
			$pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * 記事情報を削除する
	 * @param $pdo PDO
	 * @param $id 記事のキー
	 */
	function deleteArticle(\PDO $pdo, int $id) {
		$pdo->beginTransaction();
		try {
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
	}

	// SQLが実行されたかを記憶する変数
	$execQuery = false;

	$config = 'db.config.json';
	$callback = function () use($config, &$execQuery) { return getPDO($config, $execQuery); };
	$idList = [];
	$count = 0;

	try {
		// メソッドに応じて実行する処理を変更
		switch ($_SERVER['REQUEST_METHOD']) {
			case 'GET':
				// キャッシュを利用した検索結果の取得
				['id-list' => $idList, 'count' => $count] = \TagSearch::getResult($_GET['str'] ?? '', max((int)($_GET['page'] ?? 1), 1), \TagSearch::DESC_POSTDATE, $callback);
				break;
			case 'POST':
				switch ($_POST['command']) {
					case 'insert-article':
						// 記事の追加
						$postDate = (new \DateTime($_POST['id']))->format('YmdHi');
						$id = (int)$postDate;
						$tagList = explode(',', $_POST['tag-list']);
						$query = \TagSearch::getQuery($callback);
						setArticle($query->getPDO(), $id, $postDate, (new \DateTime())->format('YmdHi'));
						$query->set($id, $tagList);
						break;
					case 'delete-article':
						// 記事の削除
						$id = (int)((new \DateTime($_POST['id']))->format('YmdHi'));
						$query = \TagSearch::getQuery($callback);
						$query->delete($id);
						deleteArticle($query->getPDO(), $id);
						break;
					case 'delete-tag':
						// タグによるキャッシュの削除
						$query = \TagSearch::getQuery($callback);
						$query->getCacheTable()->deleteByTag(\Query::normToken($_POST['str']));
						break;
					case 'delete-datetime':
						// 日時によるによるキャッシュの削除
						$query = \TagSearch::getQuery($callback);
						$query->getCacheTable()->deleteByDatetime(new DateTime($_POST['datetime']));
						break;
					case 'delete-file':
						// キャッシュしたファイルの削除
						$query = \TagSearch::getQuery($callback);
						$query->getCacheTable()->deleteCacheFile();
						break;
					}
				break;
		}
	}
	catch (Exception $e) {
		header('Content-Type: text/plain; charset=UTF-8', true, 500);
		// デバッグ用にエラー内容も出力
		exit($e);
	}
?>
<html lang="ja">
	<head><meta charset="utf-8"></head>
	<body>
		<div>SQLが実行されたか:<?= $execQuery ? 'true' : 'false' ?></div>
		<section>
			<h2>検索</h2>
			<form method="GET">
				<input name="str" value="<?= htmlspecialchars((string)($_GET['str'] ?? '')) ?>">
				<input name="page" type="number" value="<?= htmlspecialchars((string)($_GET['page'] ?? 1)) ?>">
				<button type="submit">検索</button>
			</form>
			<div>Count:<?= $count ?></div>
			<ol>
				<?php foreach ($idList as $id) { ?>
					<li><?= $id ?></li>
				<?php } ?>
			</ol>
		</section>
		<section>
			<h2>記事の追加（日時はIDとなる）</h2>
			<form method="POST">
				<input name="command" type="hidden" value="insert-article">
				<input name="id" type="datetime-local" required>
				<input name="tag-list" placeholder="カンマ区切りのタグリストを入力：例：AAA,BBB,CCC" required>
				<button type="submit">追加</button>
			</form>
		</section>
		<section>
			<h2>記事の削除（日時はIDとなる）</h2>
			<form method="POST">
				<input name="command" type="hidden" value="delete-article">
				<input name="id" type="datetime-local" required>
				<button type="submit">削除</button>
			</form>
		</section>
		<section>
			<h2>タグによるキャッシュの削除</h2>
			<form method="POST">
				<input name="command" type="hidden" value="delete-tag">
				<input name="str" required>
				<button type="submit">削除</button>
			</form>
		</section>
		<section>
			<h2>日時によるによるキャッシュの削除</h2>
			<form method="POST">
				<input name="command" type="hidden" value="delete-datetime">
				<input name="datetime" type="datetime-local" required>
				<button type="submit">削除</button>
			</form>
		</section>
		<section>
			<h2>キャッシュしたファイルの削除</h2>
			<form method="POST">
				<input name="command" type="hidden" value="delete-file">
				<button type="submit">削除</button>
			</form>
		</section>
	</body>
</html>