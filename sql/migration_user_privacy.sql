-- =====================================================
-- ACGO 迁移脚本：user 表新增隐私设置字段
-- 背景：个人主页「动态」「收藏」对他人的可见性（为访问他人主页功能做准备）
-- 对应代码：app/api/controller/User.php（user/privacy 获取/保存）、
--           app/api/controller/Post.php（myPosts/favorites 按 target_uid 校验隐私）
-- 说明：默认 1（公开），仅自己可看置 0
-- =====================================================

SET NAMES utf8mb4;

ALTER TABLE `user`
  ADD COLUMN `privacy_posts`     TINYINT(1) NOT NULL DEFAULT 1 COMMENT '动态可见性：1公开 0仅自己' AFTER `update_time`,
  ADD COLUMN `privacy_favorites` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '收藏可见性：1公开 0仅自己' AFTER `privacy_posts`;
