-- Source data for db-matrix.sh. Every row value contains ZZDATA so the script can
-- check that no row data lands on a line normalizeDumpCollations() reads or rewrites.
DROP DATABASE IF EXISTS src;
CREATE DATABASE src CHARACTER SET utf8mb4;
USE src;
CREATE TABLE wp_options (
  id int PRIMARY KEY AUTO_INCREMENT,
  option_name varchar(191) NOT NULL,
  option_value longtext NOT NULL COMMENT 'We collate the options',
  note varchar(50) COLLATE utf8mb4_bin
) DEFAULT CHARSET=utf8mb4;
INSERT INTO wp_options (option_name, option_value, note) VALUES
  ('wp_user_roles', 'a:1:{s:13:"administrator";a:1:{s:4:"name";s:13:"Administrator";}}ZZDATA', 'x'),
  ('blurb', CONCAT('ZZDATA We collate the results.', CHAR(10), '(line two) COLLATE utf8mb4_uca1400_ai_ci and collate post_title'), 'y'),
  ('paren', '(ZZDATA starts with a paren', NULL),
  ('collate', 'COLLATE utf8mb4_0900_ai_ci ZZDATA the theme', 'z');
