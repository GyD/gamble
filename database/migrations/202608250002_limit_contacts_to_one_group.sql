DELETE membership
FROM contact_group_members membership
INNER JOIN contact_group_members earlier_membership
    ON earlier_membership.contact_id = membership.contact_id
    AND earlier_membership.group_id < membership.group_id;

ALTER TABLE contact_group_members
    ADD UNIQUE INDEX contact_group_members_contact_unique (contact_id);
