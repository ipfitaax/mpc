-- 003 — a place to count failed receipt lookups
--
-- verify.php is the only public, unauthenticated thing this system exposes. It
-- answers a yes/no question about whether a payment exists, so it is worth
-- being deliberate about.
--
-- WHAT ACTUALLY PROTECTS THE CODES IS THEIR SIZE, NOT THIS TABLE.
-- Twelve characters of a thirty-symbol alphabet is about 5.3e17 combinations.
-- Nobody is walking that. This table does not exist to stop guessing.
--
-- It exists because the endpoint is on shared hosting, where every request
-- costs a PHP boot and a database connection, and a script hammering it is a
-- resource problem long before it is a disclosure one. Be honest about the
-- limit: a rate limiter that queries the database still costs a query, so this
-- slows casual scripted probing rather than defeating a determined flood.
--
-- Only FAILED lookups are recorded. A student checking their own receipt
-- repeatedly - which is exactly what a worried person does - must never be
-- locked out.

CREATE TABLE verify_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip         VARCHAR(45)     NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_verify_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The application needs to prune its own old rows, which means DELETE on this
-- table and only this table. That is safe in a way DELETE on payments is not:
-- this is a rate-limit log, not money. Run as the database owner:
--
--     GRANT DELETE ON mpc_db.verify_attempts TO 'mpc_app'@'localhost';
--     FLUSH PRIVILEGES;
