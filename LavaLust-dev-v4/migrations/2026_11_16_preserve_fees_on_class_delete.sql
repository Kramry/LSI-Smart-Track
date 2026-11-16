-- Preserve payment and bond history when a class is deleted.
-- Run against the same database configured by the application.

ALTER TABLE trainee_fees
  DROP FOREIGN KEY smarttrack_trainee_fee_class_fk,
  MODIFY class_id INT UNSIGNED NULL,
  ADD CONSTRAINT smarttrack_trainee_fee_class_fk
    FOREIGN KEY (class_id) REFERENCES training_classes (class_id)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- Timed assessments are class-owned and can be removed with their class.
ALTER TABLE timed_assessments
  DROP FOREIGN KEY fk_timed_assessment_class,
  ADD CONSTRAINT fk_timed_assessment_class
    FOREIGN KEY (class_id) REFERENCES training_classes (class_id)
    ON DELETE CASCADE ON UPDATE CASCADE;
