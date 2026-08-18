-- =====================================================
-- ACGO 迁移脚本：post_report 表新增 target_uid（举报用户）
-- 背景：他人个人中心右上角省略号可举报用户，post/report 接口支持 target_uid。
-- =====================================================

SET NAMES utf8mb4;

ALTER TABLE `post_report`
  ADD COLUMN `target_uid` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '被举报用户ID，0表示举报内容（帖子/评论）' AFTER `id`;

-- 索引：防重复举报查询 target_uid + user_id + status
ALTER TABLE `post_report`
  ADD KEY `idx_target_uid` (`target_uid`);
