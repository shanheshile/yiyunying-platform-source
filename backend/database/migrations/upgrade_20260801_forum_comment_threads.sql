-- Stable forum comment threads. parent_id keeps the direct reply target while
-- root_comment_id keeps the top-level comment that owns the reply thread.
SET NAMES utf8mb4;
SET @schema_name = DATABASE();

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'forum_comments'
          AND COLUMN_NAME = 'root_comment_id'
    ),
    'SELECT 1',
    'ALTER TABLE forum_comments ADD COLUMN root_comment_id BIGINT UNSIGNED NULL AFTER parent_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'forum_comments'
          AND INDEX_NAME = 'idx_forum_comments_root'
    ),
    'SELECT 1',
    'ALTER TABLE forum_comments ADD INDEX idx_forum_comments_root (post_id, root_comment_id, status, id)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Give legacy replies an initial anchor, then collapse nested reply anchors to
-- the actual top-level comment. Keep this as plain SQL so migration runners
-- that execute one statement at a time need no custom parser directives.
UPDATE forum_comments
SET root_comment_id = parent_id
WHERE parent_id IS NOT NULL AND root_comment_id IS NULL;

-- Each pass removes one legacy nesting level. Sixteen passes keep this
-- migration portable while covering deeply nested legacy discussions.
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;
UPDATE forum_comments child INNER JOIN forum_comments anchor ON anchor.id = child.root_comment_id AND anchor.post_id = child.post_id SET child.root_comment_id = anchor.root_comment_id WHERE child.parent_id IS NOT NULL AND anchor.parent_id IS NOT NULL AND anchor.root_comment_id IS NOT NULL AND child.root_comment_id <> anchor.root_comment_id;

INSERT INTO schema_migrations (`version`, `description`, `applied_at`)
VALUES ('2026.08.01-forum-comment-threads', 'Stable root anchors for forum comment threads', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
