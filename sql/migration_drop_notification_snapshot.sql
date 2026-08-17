-- =====================================================
-- ACGO 迁移脚本：删除 notification 表昵称/头像冗余快照
-- 背景：昵称/头像改为实时联表 user 表（经 Redis 缓存，见 app/common/UserInfoCache.php），
--       数据库不再存冗余快照。旧环境执行本脚本后即可。
-- 对应代码：Notification.php / Post.php / User.php（已不读写这两个字段）
-- 影响面：后台管理面板不依赖 notification 表，删除字段无影响。
-- =====================================================

SET NAMES utf8mb4;

ALTER TABLE `notification`
  DROP COLUMN `from_nickname`,
  DROP COLUMN `from_avatar`;
