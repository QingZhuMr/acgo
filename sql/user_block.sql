-- 拉黑（黑名单）表：blocker_id 拉黑 blocked_id
-- 拉黑后：双方在广场/主页互不可见、不能关注、不能聊天（前端关闭会话）
-- 幂等：唯一索引 (blocker_id, blocked_id)
CREATE TABLE IF NOT EXISTS user_block (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  blocker_id INT UNSIGNED NOT NULL COMMENT '拉黑者',
  blocked_id INT UNSIGNED NOT NULL COMMENT '被拉黑者',
  create_time INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uk_block (blocker_id, blocked_id),
  KEY idx_blocked (blocked_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拉黑关系';
