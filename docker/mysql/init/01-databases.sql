-- Runs once, on first boot of an empty data volume.
--
-- Two databases on purpose:
--   lms       — the development database
--   lms_test  — the test database
--
-- The test suite runs against real MySQL rather than in-memory SQLite, because
-- the correctness guarantees under test (unique-index idempotency, SELECT ...
-- FOR UPDATE row locks, REPEATABLE READ snapshots, deadlock retries) either do
-- not exist or behave differently in SQLite. Testing them on SQLite would prove
-- nothing about production.
CREATE DATABASE IF NOT EXISTS `lms_test`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `lms`.*      TO 'lms'@'%';
GRANT ALL PRIVILEGES ON `lms_test`.* TO 'lms'@'%';
FLUSH PRIVILEGES;
