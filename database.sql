CREATE DATABASE IF NOT EXISTS betagen CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE betagen;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_seller TINYINT(1) NOT NULL DEFAULT 0,
    email_verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE otp_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    purpose VARCHAR(30) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_otp_email (email),
    KEY idx_otp_expire (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE seller_areas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    province VARCHAR(150) NOT NULL,
    district VARCHAR(150) NOT NULL,
    subdistrict VARCHAR(150) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seller_area (user_id,province,district,subdistrict),
    CONSTRAINT fk_seller_area_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE seller_routes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_area_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT NOT NULL,
    round_no TINYINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_route (seller_area_id,day_of_week,round_no),
    CONSTRAINT fk_route_area FOREIGN KEY (seller_area_id) REFERENCES seller_areas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_no VARCHAR(40) NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    customer_email VARCHAR(190) NOT NULL,
    customer_name VARCHAR(190) NOT NULL,
    customer_phone VARCHAR(50) NOT NULL,
    province VARCHAR(150) NOT NULL,
    district VARCHAR(150) NOT NULL,
    subdistrict VARCHAR(150) NOT NULL,
    delivery_date DATE NOT NULL,
    round_no TINYINT NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    items_json LONGTEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    matched_seller_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_no (order_no),
    KEY idx_order_route (province,district,subdistrict,delivery_date,round_no),
    CONSTRAINT fk_order_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_seller FOREIGN KEY (matched_seller_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    image_url TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO products (name,price,active) VALUES
('รสดั้งเดิม',15,1),
('รสสตรอว์เบอร์รี่',15,1),
('รสพีช',15,1),
('รสแอปเปิ้ล',15,1),
('รสบลูเบอร์รี่',15,1),
('รสผลไม้รวม',15,1);
