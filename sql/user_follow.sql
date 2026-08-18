-- =====================================================
-- ACGO 用户关注表（好友 = 互相关注）
-- =====================================================
CREATE TABLE IF NOT EXISTS `user_follow` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '关注ID',
  `follower_id` INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '关注者ID（主动关注的人）',
  `following_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '被关注者ID',
  `create_time` INT          NOT NULL DEFAULT 0  COMMENT '关注时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_follow` (`follower_id`, `following_id`),
  KEY `idx_following` (`following_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户关注表';
