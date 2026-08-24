ALTER TABLE stakes
    ADD COLUMN winnings_paid_at DATETIME NULL AFTER is_cancelled;