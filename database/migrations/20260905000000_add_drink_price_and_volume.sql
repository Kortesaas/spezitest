ALTER TABLE drinks
    ADD COLUMN price_amount DECIMAL(12,4) NULL AFTER notes,
    ADD COLUMN price_volume_ml SMALLINT UNSIGNED NULL AFTER price_amount,
    ADD CONSTRAINT chk_drinks_price_amount CHECK (price_amount IS NULL OR price_amount > 0),
    ADD CONSTRAINT chk_drinks_price_volume_ml CHECK (price_volume_ml IS NULL OR price_volume_ml > 0),
    ADD CONSTRAINT chk_drinks_price_pair CHECK (
        (price_amount IS NULL AND price_volume_ml IS NULL)
        OR (price_amount IS NOT NULL AND price_volume_ml IS NOT NULL)
    );
