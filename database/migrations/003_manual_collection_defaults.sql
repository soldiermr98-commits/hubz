-- Preserve all records. Require explicit re-approval of legacy published content,
-- and prevent old jobs from running until a provider is explicitly selected again.
START TRANSACTION;
UPDATE cities SET publish_mode = 'manual';
UPDATE provider_state SET enabled = 0 WHERE provider IN ('gosom','neshan','csv','wikidata','google_places','balad');
INSERT IGNORE INTO provider_state (provider, enabled, priority) VALUES ('osm', 1, 2);

-- Change only the untouched original default pack; customized configurations remain intact.
UPDATE category_packs SET name='بسته کافه‌ها', queries_json='{"osm":["amenity=cafe"],"neshan":["کافه"]}'
WHERE id=1 AND name='بسته کافه و رستوران‌های محبوب'
  AND queries_json='{"gosom":["کافه","رستوران سنتی","کبابی"],"osm":["amenity=cafe","amenity=restaurant"],"neshan":["کافه","رستوران"]}';

INSERT INTO review_queue (kind,target_id,reason_code,payload_json,status,created_at)
SELECT 'business',b.id,'legacy_revalidation',
       JSON_OBJECT('business_id',b.id,'business_name',b.name,'address',COALESCE(b.address,''),'provider','legacy'),
       'pending',NOW()
FROM businesses b WHERE b.status='published'
AND NOT EXISTS (SELECT 1 FROM review_queue r WHERE r.kind='business' AND r.target_id=b.id AND r.status='pending');
UPDATE businesses SET status='pending_review',publish_reason='legacy_revalidation' WHERE status='published';

INSERT INTO review_queue (kind,target_id,reason_code,payload_json,status,created_at)
SELECT 'article',a.id,'legacy_revalidation',JSON_OBJECT('article_id',a.id,'title',a.title),'pending',NOW()
FROM articles a WHERE a.status='published'
AND NOT EXISTS (SELECT 1 FROM review_queue r WHERE r.kind='article' AND r.target_id=a.id AND r.status='pending');
UPDATE articles SET status='review' WHERE status='published';

UPDATE collector_jobs SET status='paused', error='برای این نسخه یک کار جدید با انتخاب دستی منبع بسازید.',updated_at=NOW()
WHERE status IN ('queued','claimed','running');
COMMIT;
