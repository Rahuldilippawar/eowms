# VJMAT EOWMS — Database Schema Audit Report
**दिनांक:** 14 September 2026 | **एकूण Tables:** 176 | **Database Engine:** MySQL/MariaDB (InnoDB)

---

## ✅ Audit निकाल — सारांश

| Check | निकाल |
|---|---|
| एकूण Tables | **176** (सगळे `ENGINE=InnoDB`) |
| Foreign Key Integrity | ✅ **0 Broken References** — सगळे FK खऱ्या Tables कडेच Point करतात |
| Duplicate Table Definitions | ✅ **0** |
| Backup Export Coverage | ✅ 166/176 (उरलेले 10 — मुद्दाम वगळलेले Global/System Tables) |
| Backup↔Restore Consistency | ✅ जवळपास पूर्ण (6 Transient/Audit Tables वगळता) |
| Restore Column Completeness | ✅ **0 Issues** |
| Multi-Tenancy (`organization_id`) | ✅ सगळ्या मुख्य Tables मध्ये आहे; 92 Child-Tables Parent मार्फत Indirectly Scoped (Normal Design) |

**एकूण निष्कर्ष: Schema संरचनात्मकदृष्ट्या पूर्णपणे सुसंगत (Consistent) आणि स्वच्छ आहे.**

---

## 📋 Module-निहाय Tables यादी

### 1. 🔐 System / Global (Organization-Independent)
| Table | Columns | FKs |
|---|---|---|
| role_definitions | 8 | 0 |
| permissions | 4 | 0 |
| role_permissions | 4 | 0 |
| password_reset_codes | 7 | 1 |
| webauthn_credentials | 9 | 1 |
| push_subscriptions | 8 | 1 |
| scheduled_backups | 8 | 1 |
| help_categories | 4 | 0 |
| help_articles | 10 | 2 |
| biometric_punch_logs | 7 | 0 |

### 2. 🏢 Organization व मूलभूत संरचना
| Table | Columns | FKs |
|---|---|---|
| organizations | 17 | 1 |
| campuses | 19 | 1 |
| departments | 14 | 2 |
| buildings | 10 | 3 |
| users | 73 | 4 |
| user_extra_department_access | 9 | 3 |
| user_extra_campus_access | 9 | 3 |
| custom_fields | 12 | 1 |
| custom_field_values | 5 | 1 |
| activity_logs | 9 | 0 |
| notifications | 10 | 2 |
| documents | 13 | 3 |
| announcements | 10 | 2 |
| announcement_departments | 5 | 2 |
| announcement_reads | 7 | 2 |
| emergency_alerts | 13 | 3 |
| holidays | 11 | 3 |
| birthday_wishes | 12 | 3 |
| about_sections | 10 | 1 |
| about_contributors | 10 | 1 |
| software_suggestions | 15 | 3 |
| software_suggestion_votes | 7 | 2 |
| software_suggestion_comments | 7 | 2 |

### 3. 📅 दैनंदिन कामकाज / Tasks / Requests
| Table | Columns | FKs |
|---|---|---|
| work_records | 27 | 3 |
| work_record_team | 6 | 2 |
| work_record_materials | 7 | 2 |
| tasks | 20 | 4 |
| work_requests | 22 | 6 |
| work_request_actions | 10 | 2 |
| work_request_views | 7 | 2 |

### 4. 📨 पत्रव्यवहार (Letters/Correspondence)
| Table | Columns | FKs |
|---|---|---|
| letters | 40 | 9 |
| letter_categories | 6 | 1 |
| letter_inward_numbers | 10 | 2 |
| letter_actions | 10 | 3 |
| letter_attachments | 8 | 2 |
| letter_attachment_remarks | 10 | 2 |
| letter_watchers | 9 | 3 |
| letter_templates | 14 | 4 |
| letter_signatures | 11 | 2 |
| workflow_templates | 8 | 2 |
| workflow_steps | 10 | 3 |

### 5. 👥 कर्मचारी व्यवस्थापन (Staff / Attendance / Leave)
| Table | Columns | FKs |
|---|---|---|
| staff_attendance | 17 | 2 |
| attendance_regularization_requests | 13 | 3 |
| staff_shift_settings | 10 | 1 |
| biometric_devices | 11 | 2 |
| leave_types | 9 | 1 |
| leave_applications | 17 | 4 |
| leave_balances | 9 | 2 |
| employee_family_members | 7 | 1 |
| staff_documents | 7 | 1 |
| staff_achievements | 9 | 2 |
| staff_messages | 8 | 2 |

### 6. 🏠 वसतिगृह (Hostel)
| Table | Columns | FKs |
|---|---|---|
| hostels | 11 | 3 |
| hostel_staff | 6 | 2 |
| hostel_rooms | 8 | 1 |
| hostel_room_allocations | 12 | 3 |
| hostel_students | 13 | 1 |
| hostel_student_attendance | 13 | 3 |
| hostel_student_health | 10 | 1 |
| hostel_health_incidents | 9 | 2 |
| hostel_fees | 10 | 2 |
| hostel_fee_payments | 10 | 2 |
| hostel_leave_records | 11 | 2 |
| hostel_alumni | 11 | 2 |

### 7. 🏘️ रहिवासी (Residency)
| Table | Columns | FKs |
|---|---|---|
| residency_occupants | 21 | 5 |
| residency_allotment_requests | 17 | 5 |
| residency_guests | 18 | 4 |
| residency_inspections | 11 | 3 |
| residency_bills | 16 | 3 |
| residency_bill_shares | 8 | 2 |
| residency_room_items | 12 | 3 |
| residency_room_transfers | 12 | 4 |
| residency_import_batches | 11 | 2 |

### 8. 🚗 वाहन (Vehicles)
| Table | Columns | FKs |
|---|---|---|
| vehicles | 28 | 4 |
| vehicle_trips | 17 | 3 |
| vehicle_fuel_log | 11 | 2 |
| vehicle_maintenance | 13 | 2 |
| vehicle_correction_requests | 14 | 3 |

### 9. 🔧 देखभाल व Dead Stock
| Table | Columns | FKs |
|---|---|---|
| maintenance_locations | 17 | 4 |
| maintenance_logs | 15 | 4 |
| dead_stock_item_master | 11 | 2 |
| dead_stock_items | 21 | 4 |

### 10. 🛒 खरेदी व भांडार (Purchase / Store)
| Table | Columns | FKs |
|---|---|---|
| purchase_requisitions | 15 | 3 |
| purchase_quotations | 12 | 2 |
| purchase_comparative_statements | 10 | 3 |
| purchase_orders | 21 | 5 |
| purchase_order_items | 8 | 1 |
| payment_orders | 9 | 2 |
| purchase_pending_register | 24 | 4 |
| store_items | 13 | 3 |
| store_stock_in | 19 | 4 |
| store_stock_out | 13 | 4 |
| department_budgets | 12 | 3 |
| budget_transactions | 14 | 3 |

### 11. 🖥️ IT विभाग
| Table | Columns | FKs |
|---|---|---|
| it_labs | 12 | 3 |
| it_assets | 25 | 4 |
| it_asset_transfers | 11 | 4 |
| it_repair_tickets | 16 | 5 |
| it_general_requests | 21 | 5 |
| it_general_request_comments | 7 | 2 |

### 12. 📷 CCTV
| Table | Columns | FKs |
|---|---|---|
| cctv_cameras | 10 | 2 |
| cctv_camera_status | 8 | 2 |
| cctv_dvrs | 14 | 3 |
| cctv_checkups | 15 | 3 |

### 13. 🎓 Virtual Classroom (Academics)
| Table | Columns | FKs |
|---|---|---|
| students | 25 | 4 |
| virtual_classrooms | 14 | 4 |
| classroom_students | 9 | 3 |
| classroom_staff | 9 | 3 |
| classroom_materials | 11 | 2 |
| classroom_assignments | 12 | 2 |
| classroom_assignment_submissions | 15 | 3 |
| classroom_announcements | 8 | 2 |
| classroom_attendance | 12 | 3 |
| classroom_exams | 10 | 2 |
| classroom_exam_marks | 11 | 3 |
| classroom_timetable | 11 | 2 |
| classroom_questions | 9 | 2 |
| classroom_question_replies | 7 | 2 |
| student_notifications | 8 | 1 |

### 14. 👨‍🏫 Teaching Module
| Table | Columns | FKs |
|---|---|---|
| teacher_timetable | 15 | 3 |
| substitute_assignments | 17 | 5 |
| syllabus_trackers | 14 | 3 |
| teacher_qualifications | 11 | 1 |
| class_teacher_assignments | 11 | 3 |
| exam_duty_allocations | 14 | 4 |
| teacher_feedback | 8 | 1 |

### 15. 🎵 संगीत विभाग
| Table | Columns | FKs |
|---|---|---|
| music_students | 11 | 2 |
| music_instruments | 19 | 4 |
| music_instrument_log | 9 | 2 |
| music_enrollments | 9 | 2 |
| music_attendance | 9 | 2 |
| music_exams | 13 | 2 |
| music_events | 11 | 2 |
| music_event_participants | 7 | 2 |
| music_room_bookings | 13 | 3 |
| music_sheet_library | 11 | 2 |

### 16. 🧘 ध्यान व सत्संग / SOP
| Table | Columns | FKs |
|---|---|---|
| satsang_events | 13 | 4 |
| satsang_duties | 8 | 2 |
| satsang_attendance | 11 | 3 |
| meditation_quotes | 9 | 2 |
| sops | 9 | 2 |
| sop_steps | 9 | 1 |
| sop_department_assignments | 9 | 3 |
| sop_compliance_logs | 11 | 3 |

### 17. 🎉 कार्यक्रम व्यवस्थापन (Events) / पाहुणे (Guests)
| Table | Columns | FKs |
|---|---|---|
| events | 23 | 5 |
| event_tasks | 9 | 2 |
| event_budget_items | 7 | 1 |
| event_photos | 8 | 2 |
| event_attendance | 12 | 4 |
| guests | 20 | 5 |
| guest_pre_registrations | 16 | 3 |

### 18. 🔑 Asset Checkout / Allocation / Document Expiry / Room Booking / AMC
| Table | Columns | FKs |
|---|---|---|
| checkout_items | 12 | 3 |
| checkout_logs | 14 | 4 |
| asset_allocation_types | 9 | 2 |
| asset_allocation_fields | 9 | 1 |
| asset_allocations | 17 | 5 |
| document_expiry_items | 19 | 5 |
| bookable_rooms | 12 | 3 |
| room_bookings | 10 | 2 |
| amc_contracts | 19 | 4 |

### 19. 🧩 Custom Forms / तक्रार निवारण
| Table | Columns | FKs |
|---|---|---|
| custom_forms | 9 | 2 |
| custom_form_fields | 8 | 1 |
| custom_form_responses | 7 | 2 |
| grievances | 15 | 3 |

---

## 🔍 महत्त्वाच्या Notes

1. **`users` Table सगळ्यात मोठं आहे (73 Columns)** — हे Organization मधल्या प्रत्येक व्यक्तीची संपूर्ण Profile (Personal, Employment, Teaching-Mode, Reports-To, Emergency-Contact इ.) एकाच ठिकाणी ठेवतं.
2. **`letters` Table दुसऱ्या क्रमांकावर (40 Columns, 9 FKs)** — Workflow, Budget-Link, Dispatch-Tracking, Parent-Reply-Chain — सगळं एकत्र.
3. **Multi-Tenancy** — जवळपास प्रत्येक "Top-Level" Table मध्ये `organization_id` आहे; Detail/Child Tables आपापल्या Parent मार्फत आपोआप Scope होतात.
4. **10 Tables जाणूनबुजून Backup च्या बाहेर** — हे System-Wide (Permissions/Roles) किंवा Security-Sensitive (Password-Reset-Codes, WebAuthn) आहेत — प्रत्येक Organization साठी वेगळे नसतात.

---
*हा Report Claude ने स्वयंचलितपणे, संपूर्ण `database/schema.sql` Parse करून तयार केला आहे.*
