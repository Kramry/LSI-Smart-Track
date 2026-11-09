-- Run once against an existing SmartTrack database before deploying the Xendit
-- payment verification update. Existing records remain unchanged.
ALTER TABLE `xendit_payments`
  ADD COLUMN `provider_transaction_id` varchar(150) DEFAULT NULL AFTER `provider_id`,
  ADD UNIQUE KEY `uq_xendit_provider_transaction` (`provider_transaction_id`);
