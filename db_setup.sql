-- Скрипт создания таблиц по ER-диаграмме для базы данных NOSTRO в PostgreSQL
-- Запустите этот скрипт в pgAdmin -> Query Tool (на базе NOSTRO)

SET client_min_messages TO WARNING;

-- 1. Пользователи системы
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    role VARCHAR(50) NOT NULL,
    fio VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
);

ALTER TABLE users ADD COLUMN IF NOT EXISTS password VARCHAR(255) NOT NULL DEFAULT '1234';
ALTER TABLE users ADD COLUMN IF NOT EXISTS telegram_chat_id VARCHAR(50) DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS otp_code VARCHAR(10);
ALTER TABLE users ADD COLUMN IF NOT EXISTS otp_expires_at TIMESTAMP;
CREATE UNIQUE INDEX IF NOT EXISTS users_fio_unique_idx ON users (fio);

-- 2. Валюты (ER: Валюта + котировки для пересчёта)
CREATE TABLE IF NOT EXISTS currencies (
    code VARCHAR(10) PRIMARY KEY,
    rate_date DATE NOT NULL DEFAULT CURRENT_DATE,
    name VARCHAR(50) NOT NULL,
    rate_to_rub NUMERIC(12, 4) NOT NULL DEFAULT 1.0000
);

ALTER TABLE currencies ADD COLUMN IF NOT EXISTS rate_to_rub NUMERIC(12, 4) NOT NULL DEFAULT 1.0000;

-- 2.1. История изменения котировок валют
CREATE TABLE IF NOT EXISTS currency_history (
    id SERIAL PRIMARY KEY,
    code VARCHAR(10) NOT NULL,
    old_rate NUMERIC(12, 4),
    new_rate NUMERIC(12, 4) NOT NULL,
    rate_date DATE NOT NULL DEFAULT CURRENT_DATE,
    source VARCHAR(100) NOT NULL DEFAULT 'Вручную',
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3. Счета НОСТРО (ER: Счет НОСТРО)
CREATE TABLE IF NOT EXISTS nostro_accounts (
    id SERIAL PRIMARY KEY,
    account_number VARCHAR(50) NOT NULL UNIQUE,
    acc_date DATE NOT NULL DEFAULT CURRENT_DATE,
    currency VARCHAR(10) NOT NULL DEFAULT 'RUB' REFERENCES currencies(code),
    balance NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    prev_balance NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    owner_name VARCHAR(150) NOT NULL DEFAULT ''
);

ALTER TABLE nostro_accounts ADD COLUMN IF NOT EXISTS acc_date DATE NOT NULL DEFAULT CURRENT_DATE;
ALTER TABLE nostro_accounts ADD COLUMN IF NOT EXISTS currency VARCHAR(10) NOT NULL DEFAULT 'RUB';

-- 4. Сделки (ER: Сделка)
CREATE TABLE IF NOT EXISTS deals (
    id SERIAL PRIMARY KEY,
    deal_code VARCHAR(100) NOT NULL UNIQUE,
    deal_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    amount NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    nostro_account VARCHAR(50),
    direction VARCHAR(30) NOT NULL DEFAULT 'Входящий',
    currency VARCHAR(10) NOT NULL DEFAULT 'RUB' REFERENCES currencies(code),
    sender_account VARCHAR(50) DEFAULT '',
    receiver_account VARCHAR(50) DEFAULT '',
    status VARCHAR(50) NOT NULL DEFAULT 'Активна',
    deal_type VARCHAR(30) NOT NULL DEFAULT 'FX Deals',
    company VARCHAR(150) NOT NULL DEFAULT ''
);

-- 5. Позиции на дату по счёту (ER: Позиция на дату по счёту)
CREATE TABLE IF NOT EXISTS positions (
    id SERIAL PRIMARY KEY,
    position_code VARCHAR(100),
    pos_date DATE NOT NULL DEFAULT CURRENT_DATE,
    amount NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    balance NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'RUB',
    account_number VARCHAR(50) NOT NULL,
    sender_account VARCHAR(50) DEFAULT '',
    receiver_account VARCHAR(50) DEFAULT '',
    status VARCHAR(50) NOT NULL DEFAULT 'Активна',
    company VARCHAR(150) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE positions ADD COLUMN IF NOT EXISTS position_code VARCHAR(100);
ALTER TABLE positions ADD COLUMN IF NOT EXISTS sender_account VARCHAR(50) DEFAULT '';
ALTER TABLE positions ADD COLUMN IF NOT EXISTS receiver_account VARCHAR(50) DEFAULT '';
ALTER TABLE positions ADD COLUMN IF NOT EXISTS company VARCHAR(150) NOT NULL DEFAULT '';
CREATE UNIQUE INDEX IF NOT EXISTS positions_code_unique_idx ON positions (position_code) WHERE position_code IS NOT NULL;

-- 6. Платежи (ER: Платеж)
CREATE TABLE IF NOT EXISTS payments (
    id SERIAL PRIMARY KEY,
    payment_code VARCHAR(100) NOT NULL UNIQUE,
    pay_date DATE NOT NULL DEFAULT CURRENT_DATE,
    amount NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    nostro_account VARCHAR(50),
    direction VARCHAR(30) DEFAULT 'Входящий',
    currency VARCHAR(10) NOT NULL DEFAULT 'RUB',
    sender_account VARCHAR(50) DEFAULT '',
    receiver_account VARCHAR(50) DEFAULT '',
    status VARCHAR(50) NOT NULL DEFAULT 'Сформирован',
    deal_type VARCHAR(30) DEFAULT '',
    company VARCHAR(150) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE payments ADD COLUMN IF NOT EXISTS deal_type VARCHAR(30) DEFAULT '';
ALTER TABLE payments ADD COLUMN IF NOT EXISTS company VARCHAR(150) NOT NULL DEFAULT '';

-- ========== НАЧАЛЬНЫЕ ДАННЫЕ ==========

INSERT INTO users (role, fio, password) VALUES
    ('Администратор', 'Иванов', '1234'),
    ('Дилер', 'Петров', '1234'),
    ('Позиционер', 'Сидоров', '1234'),
    ('Руководитель', 'Красникова', '1234')
ON CONFLICT (fio) DO NOTHING;

INSERT INTO currencies (code, rate_date, name, rate_to_rub) VALUES
    ('RUB', CURRENT_DATE, 'Российский рубль', 1.0000),
    ('USD', CURRENT_DATE, 'Доллар США', 98.5000),
    ('EUR', CURRENT_DATE, 'Евро', 105.2000),
    ('CNY', CURRENT_DATE, 'Китайский юань', 13.6000)
ON CONFLICT (code) DO UPDATE SET rate_to_rub = EXCLUDED.rate_to_rub;

INSERT INTO nostro_accounts (account_number, acc_date, currency, balance, prev_balance, owner_name) VALUES
    ('40702810000000000001', CURRENT_DATE, 'RUB', 1250000.00, 1250000.00, 'ПАО Сбербанк'),
    ('40702840000000000002', CURRENT_DATE, 'USD', 450000.00, 450000.00, 'АО Альфа-Банк'),
    ('40702978000000000003', CURRENT_DATE, 'EUR', 320000.00, 320000.00, 'Банк ВТБ'),
    ('40702810000000000004', CURRENT_DATE, 'RUB', 950000.00, 950000.00, 'ООО Газпром Нефть')
ON CONFLICT (account_number) DO UPDATE SET owner_name = EXCLUDED.owner_name;

INSERT INTO deals (deal_code, deal_date, amount, nostro_account, direction, currency, sender_account, receiver_account, status, deal_type, company) VALUES
    ('FX-2026-001', '2026-11-18 10:15:00', 500000.00, '40702810000000000001', 'Входящий', 'RUB', '40702810100000001111', '40702810000000000001', 'Исполнена', 'FX Deals', 'ПАО Сбербанк'),
    ('MM-2026-002', '2026-11-19 14:30:00', 120000.00, '40702840000000000002', 'Исходящий', 'USD', '40702840000000000002', '40702840200000002222', 'Активна', 'MM Deals', 'АО Альфа-Банк'),
    ('FX-2026-003', '2026-11-20 09:45:00', 85000.00,  '40702978000000000003', 'Входящий', 'EUR', '40702978300000003333', '40702978000000000003', 'Активна', 'FX Deals', 'Банк ВТБ'),
    ('MM-2026-004', '2026-11-21 11:00:00', 950000.00, '40702810000000000004', 'Исходящий', 'RUB', '40702810000000000004', '40702810400000004444', 'На проверке', 'MM Deals', 'ООО Газпром Нефть')
ON CONFLICT (deal_code) DO UPDATE SET nostro_account = EXCLUDED.nostro_account, company = EXCLUDED.company;

INSERT INTO positions (position_code, pos_date, amount, balance, currency, account_number, sender_account, receiver_account, status, company) VALUES
    ('POS-001', '2026-11-18', 500000.00, 1250000.00, 'RUB', '40702810000000000001', '40702810100000001111', '40702810000000000001', 'Активна', 'ПАО Сбербанк'),
    ('POS-002', '2026-11-19', 120000.00, 450000.00,  'USD', '40702840000000000002', '40702840000000000002', '40702840200000002222', 'Активна', 'АО Альфа-Банк'),
    ('POS-003', '2026-11-20', 85000.00,  320000.00,  'EUR', '40702978000000000003', '40702978300000003333', '40702978000000000003', 'Закрыта', 'Банк ВТБ'),
    ('POS-004', '2026-11-21', 950000.00, 950000.00,  'RUB', '40702810000000000004', '40702810000000000004', '40702810400000004444', 'Активна', 'ООО Газпром Нефть')
ON CONFLICT (position_code) WHERE position_code IS NOT NULL DO UPDATE SET account_number = EXCLUDED.account_number, company = EXCLUDED.company;

INSERT INTO payments (payment_code, pay_date, amount, nostro_account, direction, currency, sender_account, receiver_account, status, deal_type, company) VALUES
    ('PAY-2026-101', '2026-11-18', 500000.00, '40702810000000000001', 'Входящий', 'RUB', '40702810100000001111', '40702810000000000001', 'Отправлен', 'FX Deals', 'ПАО Сбербанк'),
    ('PAY-2026-102', '2026-11-19', 120000.00, '40702840000000000002', 'Исходящий', 'USD', '40702840000000000002', '40702840200000002222', 'Сформирован', 'MM Deals', 'АО Альфа-Банк'),
    ('PAY-2026-103', '2026-11-20', 85000.00,  '40702978000000000003', 'Входящий', 'EUR', '40702978300000003333', '40702978000000000003', 'Изменён', 'FX Deals', 'Банк ВТБ'),
    ('PAY-2026-104', '2026-11-21', 210000.00, '40702810000000000004', 'Исходящий', 'RUB', '40702810000000000004', '40702810400000004444', 'Сформирован', 'MM Deals', 'ООО Газпром Нефть')
ON CONFLICT (payment_code) DO UPDATE SET nostro_account = EXCLUDED.nostro_account, company = EXCLUDED.company;
