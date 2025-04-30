-- テーブルを全削除する
DROP TABLE IF EXISTS tag_search_caches_tags;
DROP TABLE IF EXISTS posted_articles_tags;
DROP TABLE IF EXISTS posted_articles;
DROP TABLE IF EXISTS tags;
DROP TABLE IF EXISTS tag_search_caches;

-- 投稿済み記事テーブル
CREATE TABLE posted_articles (
	-- post_dateを利用
    id CHAR(12) PRIMARY KEY,
	-- 投稿日時(年(西暦4桁と仮定)月日時分から構成されるタイムスタンプと等価(これ以上の分解能では投稿頻度が高いという旨のエラーとする))
    post_date CHAR(12) NOT NULL UNIQUE,
	-- 更新日時
    update_date CHAR(12) NOT NULL
);
-- 検索キーのためインデックスを付与
CREATE INDEX idx_posted_articles_01 ON posted_articles(update_date);

-- タグテーブル
CREATE TABLE tags (
	-- posted_articlesのpost_date+連番2桁(14桁)を利用
    id CHAR(14) PRIMARY KEY,
	-- オリジナルのタグ名
    org_name VARCHAR(50) NOT NULL UNIQUE,
	-- 正規化されたタグ名(NFKC + 大文字変換)
	norm_name VARCHAR(50) NOT NULL UNIQUE
);

-- 記事とタグの中間テーブル
CREATE TABLE posted_articles_tags (
    article_id CHAR(12),
    tag_id CHAR(14),
    PRIMARY KEY (article_id, tag_id),
    FOREIGN KEY (article_id) REFERENCES posted_articles(id),
    FOREIGN KEY (tag_id) REFERENCES tags(id)
);

-- タグ検索結果についてのキャッシュテーブル
CREATE TABLE tag_search_caches (
	-- キャッシュのキー(SHA-256で計算)
    id CHAR(64) PRIMARY KEY,
	-- 有効期限
	expiration_time DATETIME
);

-- タグ検索結果とタグの中間テーブル
CREATE TABLE tag_search_caches_tags (
    cache_id CHAR(64),
    tag_id CHAR(14),
    PRIMARY KEY (cache_id, tag_id),
    FOREIGN KEY (cache_id) REFERENCES tag_search_caches(id),
    FOREIGN KEY (tag_id) REFERENCES tags(id)
);
