-- Test database used by the Pest suite (see phpunit.xml).
CREATE DATABASE IF NOT EXISTS ada_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON ada_test.* TO 'ada'@'%';
