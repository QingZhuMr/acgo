-- =====================================================
-- ACGO 测试数据：清除并重建用户、钱包数据
-- 说明：所有测试用户密码统一为 123456
-- =====================================================
SET NAMES utf8mb4;

-- 1. 清除已有用户相关数据（user_log 先删，因外键关联 user_id）
DELETE FROM `user_log`;
DELETE FROM `wallet`;
DELETE FROM `user`;
ALTER TABLE `user`  AUTO_INCREMENT = 1;
ALTER TABLE `wallet` AUTO_INCREMENT = 1;
ALTER TABLE `user_log` AUTO_INCREMENT = 1;

-- 2. 测试用户（密码 hash 为 123456）
INSERT INTO `user` (`username`, `nickname`, `password`, `phone`, `avatar`, `status`, `register_time`, `update_time`) VALUES
('user01', '张三', '$2y$10$WdHaQ2SqNlK6h9zr3OSBGOXoMztfGu88nnQt3pF4jZosnLLQ6VVku', '13800000001', '/static/admin/img/default-avatar.png', 1, UNIX_TIMESTAMP() - 86400 * 30, UNIX_TIMESTAMP() - 86400),
('user02', '李四', '$2y$10$WdHaQ2SqNlK6h9zr3OSBGOXoMztfGu88nnQt3pF4jZosnLLQ6VVku', '13800000002', '/static/admin/img/default-avatar.png', 1, UNIX_TIMESTAMP() - 86400 * 25, UNIX_TIMESTAMP() - 86400),
('user03', '王五', '$2y$10$WdHaQ2SqNlK6h9zr3OSBGOXoMztfGu88nnQt3pF4jZosnLLQ6VVku', '13800000003', '/static/admin/img/default-avatar.png', 1, UNIX_TIMESTAMP() - 86400 * 20, UNIX_TIMESTAMP() - 86400),
('user04', '赵六', '$2y$10$WdHaQ2SqNlK6h9zr3OSBGOXoMztfGu88nnQt3pF4jZosnLLQ6VVku', '13800000004', '/static/admin/img/default-avatar.png', 1, UNIX_TIMESTAMP() - 86400 * 15, UNIX_TIMESTAMP() - 86400),
('user05', '孙七', '$2y$10$WdHaQ2SqNlK6h9zr3OSBGOXoMztfGu88nnQt3pF4jZosnLLQ6VVku', '13800000005', '/static/admin/img/default-avatar.png', 2, UNIX_TIMESTAMP() - 86400 * 10, UNIX_TIMESTAMP() - 86400 * 3),
('user06', '周八', '$2y$10$WdHaQ2SqNlK6h9zr3OSBGOXoMztfGu88nnQt3pF4jZosnLLQ6VVku', '13800000006', '/static/admin/img/default-avatar.png', 0, UNIX_TIMESTAMP() - 86400 * 8,  UNIX_TIMESTAMP() - 86400 * 2),
('user07', '吴九', '$2y$10$WdHaQ2SqNlK6h9zr3OSBGOXoMztfGu88nnQt3pF4jZosnLLQ6VVku', '13800000007', '/static/admin/img/default-avatar.png', 1, UNIX_TIMESTAMP() - 86400 * 5,  UNIX_TIMESTAMP() - 86400);

-- 3. 对应钱包（uid 与 user.id 对应；双轨制货币：人民币 balance_cny + 熊爪 balance_paw）
INSERT INTO `wallet` (`uid`, `balance_cny`, `balance_paw`, `status`, `create_time`, `update_time`) VALUES
(1, 10000.00, 1500, 1, UNIX_TIMESTAMP() - 86400 * 30, UNIX_TIMESTAMP() - 86400),
(2, 5000.00,  800,  1, UNIX_TIMESTAMP() - 86400 * 25, UNIX_TIMESTAMP() - 86400),
(3, 2000.00,  300,  1, UNIX_TIMESTAMP() - 86400 * 20, UNIX_TIMESTAMP() - 86400),
(4, 800.00,   120,  1, UNIX_TIMESTAMP() - 86400 * 15, UNIX_TIMESTAMP() - 86400),
(5, 300.00,   50,   1, UNIX_TIMESTAMP() - 86400 * 10, UNIX_TIMESTAMP() - 86400 * 3),
(6, 0.00,     0,    0, UNIX_TIMESTAMP() - 86400 * 8,  UNIX_TIMESTAMP() - 86400 * 2),
(7, 100.00,   15,   1, UNIX_TIMESTAMP() - 86400 * 5,  UNIX_TIMESTAMP() - 86400);
