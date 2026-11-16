-- The certificate template editor persists `generated` as its generated-design mode.
-- Older databases used ENUM('upload', 'generate'), which rejects that value.

ALTER TABLE certificate_templates
  MODIFY COLUMN mode VARCHAR(20) NOT NULL;

UPDATE certificate_templates
SET mode = 'generated'
WHERE mode = 'generate';
