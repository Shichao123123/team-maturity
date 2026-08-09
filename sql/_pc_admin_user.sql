USE team_maturity;
SET NAMES utf8mb4;
DELETE FROM tm_admin;
INSERT INTO tm_admin (name, username, mobile, password_hash, role_id, status) VALUES
('系统管理员','admin','13800000000','$2y$10$WHIuD7gW7NoNRylADmmR/ObL71kIRpUOHqoGM97NDD.0GInAg7uxy',1,1);