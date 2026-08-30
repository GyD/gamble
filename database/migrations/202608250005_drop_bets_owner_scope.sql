-- The ownership scope is gone: bets are visible and editable by every authorized user.
-- Keep owner_user_id (creator, used by audit snapshots) but replace the now useless
-- composite index. A simple index is required first, otherwise the bets_owner_fk
-- foreign key would lose its supporting index (MySQL errno 150).
ALTER TABLE bets ADD INDEX bets_owner_index (owner_user_id);
ALTER TABLE bets DROP INDEX bets_owner_status_index;
