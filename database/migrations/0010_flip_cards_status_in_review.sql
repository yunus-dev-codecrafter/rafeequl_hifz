-- ============================================================
-- Rafeequl Hifz — migration 0010: flip card 'in_review' state
-- Apply order: after 0009.
-- Prompt 13 requires four card states; 0007 seeded three. Applied
-- migrations are never edited in place (schema.md §13), so this ALTER
-- appends 'in_review' to flip_cards.status. The default and existing
-- rows are unchanged: queued cards are active or in_review, and
-- mastered/archived cards stay outside the review queue.
-- ============================================================

ALTER TABLE flip_cards
  MODIFY status ENUM('active', 'in_review', 'mastered', 'archived') NOT NULL DEFAULT 'active';
