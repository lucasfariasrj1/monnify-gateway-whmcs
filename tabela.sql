CREATE TABLE IF NOT EXISTS mod_monnify_pix (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  client_id INT NOT NULL,
  charge_id VARCHAR(64) NULL,
  reference_id VARCHAR(128) NULL,
  txid VARCHAR(128) NULL,
  status VARCHAR(32) NULL,
  amount_cents INT NULL,
  qr_code_url TEXT NULL,
  copia_e_cola LONGTEXT NULL,
  checkout_url TEXT NULL,
  raw_response LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL,
  UNIQUE KEY uniq_invoice (invoice_id),
  KEY idx_charge (charge_id),
  KEY idx_reference (reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;