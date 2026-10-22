-- ------------------------------------------------------------
-- Migration v13: track which stock a client was "Connected" to.
--
-- Once a client requirement (properties.is_stock = 0) is Connected to a
-- stock match, Find Matching Stock should stop suggesting other candidates
-- for them — it should just show what they're already connected to. This
-- table is what makes that possible (previously nothing recorded *which*
-- stock a connect used, only a running count).
--
-- Run this once on existing databases; fresh installs already get this
-- via schema.sql.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS property_connections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    stock_id INT UNSIGNED NOT NULL,
    connected_by INT UNSIGNED DEFAULT NULL,
    connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (stock_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (connected_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_client (client_id),
    INDEX idx_stock (stock_id)
) ENGINE=InnoDB;
