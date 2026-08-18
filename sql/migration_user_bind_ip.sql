-- =====================================================
-- ACGO user 表新增 AI 账号绑定 IP 字段
-- 用途：AI/机器人账号每个账号固定一个随机国内 IP，
--       发帖/回帖用该绑定 IP 落库（post.ip / post_comment.ip）
-- =====================================================

ALTER TABLE `user`
    ADD COLUMN `bind_ip`       VARCHAR(45) NOT NULL DEFAULT '' COMMENT 'AI账号绑定IP（每个账号固定）' AFTER `last_login_ip`,
    ADD COLUMN `bind_location` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '绑定IP归属地(省份)' AFTER `bind_ip`;
