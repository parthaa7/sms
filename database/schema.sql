CREATE DATABASE IF NOT EXISTS sms_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sms_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS inventory_log, payments, sale_items, sales,
                     products, customers, suppliers, categories, users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  user_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username         VARCHAR(50)  NOT NULL,
  full_name        VARCHAR(100) NULL,
  password_hash    VARCHAR(255) NOT NULL,
  role_enum        ENUM('Administrator','Cashier') NOT NULL DEFAULT 'Cashier',
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  failed_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until     DATETIME NULL,
  last_login       DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB;

CREATE TABLE categories (
  category_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(100) NOT NULL,
  description  VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB;

CREATE TABLE suppliers (
  supplier_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  contact_info  VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_suppliers_name (name)
) ENGINE=InnoDB;

CREATE TABLE customers (
  customer_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(100) NOT NULL,
  phone        VARCHAR(20)  NULL,
  email        VARCHAR(150) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customers_phone (phone)
) ENGINE=InnoDB;

CREATE TABLE products (
  product_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(150)  NOT NULL,
  category_id     INT UNSIGNED  NOT NULL,
  supplier_id     INT UNSIGNED  NULL,
  purchase_price  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  selling_price   DECIMAL(10,2) NOT NULL,
  quantity        INT UNSIGNED  NOT NULL DEFAULT 0,
  reorder_level   INT UNSIGNED  NOT NULL DEFAULT 10,
  barcode         VARCHAR(64)   NULL,
  status_enum     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_barcode (barcode),
  KEY idx_products_name (name),
  KEY idx_products_stock (status_enum, quantity),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id)
      REFERENCES categories (category_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id)
      REFERENCES suppliers (supplier_id)  ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_products_prices CHECK (purchase_price >= 0 AND selling_price >= 0)
) ENGINE=InnoDB;

CREATE TABLE sales (
  sale_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no        VARCHAR(30)   NULL,
  user_id           INT UNSIGNED  NOT NULL,
  customer_id       INT UNSIGNED  NULL,
  transaction_date  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  subtotal          DECIMAL(12,2) NOT NULL,
  tax_amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount      DECIMAL(12,2) NOT NULL,
  payment_method    ENUM('Cash','Card','UPI') NOT NULL,
  UNIQUE KEY uq_sales_invoice (invoice_no),
  KEY idx_sales_date (transaction_date),
  KEY idx_sales_user_date (user_id, transaction_date),
  CONSTRAINT fk_sales_user FOREIGN KEY (user_id)
      REFERENCES users (user_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id)
      REFERENCES customers (customer_id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_sales_amounts CHECK (subtotal >= 0 AND tax_amount >= 0 AND total_amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE sale_items (
  sale_item_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id       INT UNSIGNED  NOT NULL,
  product_id    INT UNSIGNED  NOT NULL,
  quantity      INT UNSIGNED  NOT NULL,
  unit_price    DECIMAL(10,2) NOT NULL,
  unit_cost     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  line_total    DECIMAL(12,2) NOT NULL,
  KEY idx_sale_items_product (product_id),
  CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id)
      REFERENCES sales (sale_id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id)
      REFERENCES products (product_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_sale_items_qty CHECK (quantity > 0)
) ENGINE=InnoDB;

CREATE TABLE payments (
  payment_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id       INT UNSIGNED  NOT NULL,
  amount_paid   DECIMAL(12,2) NOT NULL,
  payment_type  ENUM('Cash','Card','UPI') NOT NULL,
  reference_no  VARCHAR(100)  NULL,
  payment_date  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_payments_date (payment_date),
  CONSTRAINT fk_payments_sale FOREIGN KEY (sale_id)
      REFERENCES sales (sale_id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT chk_payments_amount CHECK (amount_paid >= 0)
) ENGINE=InnoDB;

CREATE TABLE inventory_log (
  log_id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id        INT UNSIGNED NOT NULL,
  user_id           INT UNSIGNED NULL,
  sale_id           INT UNSIGNED NULL,
  change_type       ENUM('initial','sale','restock','adjustment','return') NOT NULL,
  quantity_change   INT          NOT NULL,
  current_quantity  INT UNSIGNED NOT NULL,
  remarks           VARCHAR(255) NULL,
  log_date          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_inv_log_product_date (product_id, log_date),
  KEY idx_inv_log_date (log_date),
  CONSTRAINT fk_inv_log_product FOREIGN KEY (product_id)
      REFERENCES products (product_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_inv_log_user FOREIGN KEY (user_id)
      REFERENCES users (user_id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_inv_log_sale FOREIGN KEY (sale_id)
      REFERENCES sales (sale_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO categories (name, description) VALUES
 ('Groceries','Rice, flour, pulses, oil'), ('Dairy','Milk, curd, butter, cheese'),
 ('Beverages','Tea, coffee, soft drinks'), ('Snacks','Biscuits, chips, namkeen'),
 ('Household','Cleaning and utility'),     ('Personal Care','Soap, shampoo, toothpaste');

INSERT INTO suppliers (name, contact_info) VALUES
 ('FreshFarm Distributors','+91 98100 00001, orders@freshfarm.example'),
 ('Metro Wholesale','+91 98100 00002, sales@metro.example'),
 ('DailyNeeds Traders','+91 98100 00003');

INSERT INTO products (name, category_id, supplier_id, purchase_price, selling_price, quantity, reorder_level, barcode) VALUES
 ('Basmati Rice 5kg',      1, 1, 520.00, 599.00, 40, 10, '8901000000011'),
 ('Sunflower Oil 1L',      1, 2, 125.00, 145.00, 60, 15, '8901000000028'),
 ('Toned Milk 500ml',      2, 1,  24.00,  28.00,  8, 20, '8901000000035'),
 ('Butter 100g',           2, 1,  48.00,  56.00, 25, 10, '8901000000042'),
 ('Green Tea 25 bags',     3, 2, 110.00, 140.00, 30,  8, '8901000000059'),
 ('Potato Chips 52g',      4, 3,  15.00,  20.00,  5, 25, '8901000000066'),
 ('Dishwash Liquid 500ml', 5, 2,  85.00, 105.00, 18,  6, '8901000000073'),
 ('Toothpaste 150g',       6, 3,  70.00,  92.00, 35, 10, '8901000000080');

INSERT INTO inventory_log (product_id, change_type, quantity_change, current_quantity, remarks)
SELECT product_id, 'initial', quantity, quantity, 'Opening stock (seed)'
FROM products WHERE quantity > 0;

INSERT INTO customers (name, phone, email) VALUES
 ('Ravi Kumar', '9876543210', 'ravi@example.com'),
 ('Anita Sharma', '9876501234', NULL);
