-- =====================================================
-- ACGO 帖子回复通知表
-- 业务场景：评论/楼中楼回复他人后，生成一条通知，供被回复人在通知中心查看
-- 触发点：Post::comment() 插入 post_comment 后同步写入；同时经 Pusher 推送 reply_notice
-- =====================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `notification`;
CREATE TABLE `notification` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '通知ID',
  `user_id`         INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '接收通知的用户ID',
  `from_uid`        INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '触发通知的用户ID（谁回复了）',
  `post_id`         INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '所属帖子ID',
  `post_content`    VARCHAR(500) NOT NULL DEFAULT '' COMMENT '帖子内容摘要（最多200字，用于通知展示）',
  `comment_id`      INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '回复的评论ID',
  `comment_content` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '回复的评论内容（用于通知展示）',
  `type`            TINYINT      NOT NULL DEFAULT 1  COMMENT '通知类型：1评论了我的帖子 2回复了我的评论',
  `is_read`         TINYINT      NOT NULL DEFAULT 0  COMMENT '是否已读：0未读 1已读',
  `create_time`     INT          NOT NULL DEFAULT 0  COMMENT '通知时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`, `is_read`, `id`),
  KEY `idx_user_time` (`user_id`, `create_time`),
  KEY `idx_from` (`from_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='帖子回复通知表';
