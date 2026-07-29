SET NAMES utf8mb4;

-- Keep the oldest code for each document. Old duplicate codes are retired before
-- the one-document-one-code unique constraint is installed.
UPDATE `document_shares` AS duplicate_share
INNER JOIN (
  SELECT `document_id`, MIN(`id`) AS `keep_id`
  FROM `document_shares`
  GROUP BY `document_id`
  HAVING COUNT(*) > 1
) AS duplicate_group
  ON duplicate_group.`document_id` = duplicate_share.`document_id`
SET duplicate_share.`status` = 0
WHERE duplicate_share.`id` <> duplicate_group.`keep_id`;

DELETE duplicate_share
FROM `document_shares` AS duplicate_share
INNER JOIN (
  SELECT `document_id`, MIN(`id`) AS `keep_id`
  FROM `document_shares`
  GROUP BY `document_id`
  HAVING COUNT(*) > 1
) AS duplicate_group
  ON duplicate_group.`document_id` = duplicate_share.`document_id`
WHERE duplicate_share.`id` <> duplicate_group.`keep_id`;

SET @share_document_key_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'document_shares'
    AND index_name = 'uk_document_shares_document_fixed'
);
SET @share_document_key_sql = IF(
  @share_document_key_exists = 0,
  'ALTER TABLE `document_shares` ADD UNIQUE KEY `uk_document_shares_document_fixed` (`document_id`)',
  'SELECT 1'
);
PREPARE share_document_key_statement FROM @share_document_key_sql;
EXECUTE share_document_key_statement;
DEALLOCATE PREPARE share_document_key_statement;
