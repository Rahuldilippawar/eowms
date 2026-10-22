-- ============================================================
-- पत्र व्यवहार सोपा केला — Clerk-Gatekeeper पद्धत काढली.
-- Clerk च्या टेबलावर अडकलेली पत्रं थेट संबंधित अधिकाऱ्याकडे हलवतो.
-- (कितीही वेळा चालवलं तरी सुरक्षित)
-- ============================================================
UPDATE letters SET current_holder_id = intended_recipient_id, clerk_stage = 'none', intended_recipient_id = NULL, current_holder_seen_at = NULL
  WHERE clerk_stage = 'awaiting_inward' AND intended_recipient_id IS NOT NULL;
UPDATE letters SET current_holder_id = pending_forward_to, clerk_stage = 'none', pending_forward_to = NULL, current_holder_seen_at = NULL
  WHERE clerk_stage = 'awaiting_forward_approval' AND pending_forward_to IS NOT NULL;
UPDATE users SET gatekeeper_clerk_id = NULL WHERE gatekeeper_clerk_id IS NOT NULL;
