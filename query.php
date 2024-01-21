<?php
	require_once $_SERVER['DOCUMENT_ROOT'].'/Search.php';

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

	// SQLが実行されたかを記憶する変数
	$execQuery = false;

	$config = 'db.config.json';
	$cacheTable = new TagSearchCaches($_SERVER['DOCUMENT_ROOT'].'/cache', function () use($config, &$execQuery) { return getPDO($config, $execQuery); });
	$idList = [];
	$count = 0;

	try {
		// メソッドに応じて実行する処理を変更
		switch ($_SERVER['REQUEST_METHOD']) {
			case 'GET':
				// キャッシュを利用した検索結果の取得
				$builder = new BuildQueryOfTagSearch($_GET['str'] ?? '');
				$query = new Query($builder, $cacheTable, fn () => $cacheTable->getPDO());
				['id-list' => $idList, 'count' => $count] = $query->get(max((int)($_GET['page'] ?? 1), 1), BuildQueryOfTagSearch::DESC_POSTDATE);
				break;
			case 'POST':
				switch ($_POST['command']) {
					case 'delete-tag':
						// タグによるキャッシュの削除
						$cacheTable->deleteByTag(BuildQueryOfTagSearch::normToken($_POST['str']));
						break;
					case 'delete-datetime':
						// 日時によるによるキャッシュの削除
						$cacheTable->deleteByDatetime(new DateTime($_POST['datetime']));
						break;
					case 'delete-file':
						// キャッシュしたファイルの削除
						$cacheTable->deleteCacheFile();
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
				<input name="str" value="<?= htmlspecialchars($_GET['str'] ?? '') ?>">
				<input name="page" type="number" value="<?= htmlspecialchars($_GET['page'] ?? 1) ?>">
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