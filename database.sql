CREATE DATABASE IF NOT EXISTS betagen
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE betagen;

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS seller_routes;
DROP TABLE IF EXISTS seller_areas;
DROP TABLE IF EXISTS otp_codes;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_seller TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE otp_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    purpose VARCHAR(50) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_otp_email_purpose (email,purpose),
    KEY idx_otp_expires (expires_at)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seller_areas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    province VARCHAR(100) NOT NULL,
    district VARCHAR(100) NOT NULL,
    subdistrict VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seller_area (
        user_id,
        province,
        district,
        subdistrict
    ),
    KEY idx_seller_location (
        province,
        district,
        subdistrict
    ),
    CONSTRAINT fk_seller_area_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seller_routes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    area_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    round_no TINYINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seller_route (
        area_id,
        day_of_week,
        round_no
    ),
    KEY idx_seller_route_day (
        day_of_week,
        round_no
    ),
    CONSTRAINT fk_seller_route_area
        FOREIGN KEY (area_id)
        REFERENCES seller_areas(id)
        ON DELETE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    image_url VARCHAR(500) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_no VARCHAR(50) NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    matched_seller_id INT UNSIGNED DEFAULT NULL,
    customer_name VARCHAR(190) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    address TEXT NOT NULL,
    province VARCHAR(100) NOT NULL,
    district VARCHAR(100) NOT NULL,
    subdistrict VARCHAR(100) NOT NULL,
    delivery_date DATE NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    round_no TINYINT UNSIGNED NOT NULL,
    payment VARCHAR(30) NOT NULL,
    items_json LONGTEXT NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_no (order_no),
    KEY idx_orders_customer (customer_id),
    KEY idx_orders_seller (matched_seller_id),
    KEY idx_orders_delivery (
        delivery_date,
        day_of_week,
        round_no
    ),
    CONSTRAINT fk_order_customer
        FOREIGN KEY (customer_id)
        REFERENCES users(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_order_seller
        FOREIGN KEY (matched_seller_id)
        REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO products
(id,name,price,image_url,active)
VALUES
(1,'รสดั้งเดิม',15.00,'',1),
(2,'รสสตรอว์เบอร์รี่',15.00,'',1),
(3,'รสพีช',15.00,'',1),
(4,'รสแอปเปิ้ล',15.00,'',1),
(5,'รสบลูเบอร์รี่',15.00,'',1),
(6,'รสผลไม้รวม',15.00,'',1);
