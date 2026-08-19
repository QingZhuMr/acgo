-- =============================================================
-- 迁移：匹配退款 + 星球每账号免费次数 + 菜单图标 fas 前缀
-- 说明：
--   1) user_find 记录每次发起的扣费类型与数量，配对失败（超时/手动取消/重复发起）时退回
--   2) 新增 find_match_timeout 配置：匹配超时秒数（默认 60），超时自动停止并退回扣费
--   3) 菜单图标统一补 fas 前缀（后台侧边栏渲染依赖 class="side-icon fas fa-xxx"）
-- =============================================================

-- 1. user_find 增加扣费记录与退款标记字段
ALTER TABLE `user_find`
    ADD COLUMN `charge_type` VARCHAR(10) NOT NULL DEFAULT '' COMMENT '本次扣费类型：free=免费次数 paw=熊爪' AFTER `matched_uid`,
    ADD COLUMN `charge_amount` INT NOT NULL DEFAULT 0 COMMENT '本次扣费数量（free=1，paw=熊爪数）' AFTER `charge_type`,
    ADD COLUMN `refunded` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已退回（0=否 1=是）' AFTER `charge_amount`;

-- 2. 匹配超时配置（秒）
INSERT INTO `config` (`name`, `value`, `remark`, `create_time`, `update_time`) VALUES
('find_match_timeout', '60', '星球寻人匹配超时秒数：超时未配对自动停止并退回本次扣费', UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `remark` = VALUES(`remark`), `update_time` = UNIX_TIMESTAMP();

-- 3. 菜单图标统一补 fas 前缀（后台侧边栏渲染需 class="side-icon fas fa-xxx"）
UPDATE `menu` SET `icon` = CONCAT('fas ', `icon`) WHERE `icon` <> '' AND `icon` NOT LIKE 'fas %';
