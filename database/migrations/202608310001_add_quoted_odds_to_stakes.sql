-- Quoted odds: the price actually shown to the bettor when the stake was taken.
--
-- Fixed odds stakes now sign their contract at payment, not at creation:
-- `odds_at_bet` stays NULL until the stake is paid. `quoted_odds` keeps the
-- informative trace of the odds announced at creation, so a stake taken over
-- the phone can be compared to the price it is finally paid at. It never enters
-- a settlement nor a contractual exposure.
--
-- Legacy stakes are left untouched: they carry no announced price, and their
-- historical `odds_at_bet` remains their contract.
ALTER TABLE stakes
    ADD COLUMN quoted_odds DECIMAL(10, 4) NULL AFTER amount,
    ADD CONSTRAINT stakes_quoted_odds_check CHECK (quoted_odds IS NULL OR quoted_odds >= 1);
