-- =====================================================
-- ACGO 迁移脚本：user 表新增 browse_history（浏览历史）
-- 背景：跨设备浏览历史（最多 120 条），存 user 表 JSON 字段，
--       由 user/browseHistory 接口读写。
-- =====================================================

SET NAMES utf8mb4;

ALTER TABLE `user`
  ADD COLUMN `browse_history` LONGTEXT NULL COMMENT '浏览历史 JSON，最多120条，[{post_id,browse_at}]' AFTER `privacy_favorites`;
