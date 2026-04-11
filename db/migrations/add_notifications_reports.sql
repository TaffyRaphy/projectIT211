-- Migration: Add Notification System & Reporting Tables
-- Date: April 2026
-- Description: Adds databases tables for email notifications, SMTP configuration, and reporting features

-- Notification System Tables
CREATE TABLE IF NOT EXISTS notification_templates (
    id SERIAL PRIMARY KEY,
    event_type VARCHAR(50) UNIQUE NOT NULL,
    subject TEXT NOT NULL,
    body_html TEXT NOT NULL,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notification_logs (
    id SERIAL PRIMARY KEY,
    recipient_email VARCHAR(255) NOT NULL,
    recipient_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    event_type VARCHAR(50) NOT NULL,
    subject TEXT,
    status VARCHAR(20) DEFAULT 'sent',  -- 'sent', 'failed', 'pending'
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    error_message TEXT
);

CREATE TABLE IF NOT EXISTS smtp_configuration (
    id SERIAL PRIMARY KEY,
    host VARCHAR(255) NOT NULL,
    port INTEGER DEFAULT 587,
    username VARCHAR(255),
    password TEXT,
    from_email VARCHAR(255) NOT NULL,
    use_env_fallback BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Reporting System Tables
CREATE TABLE IF NOT EXISTS report_snapshots (
    id SERIAL PRIMARY KEY,
    snapshot_date DATE NOT NULL,
    category VARCHAR(50),
    metric_key VARCHAR(100) NOT NULL,
    metric_value NUMERIC,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(snapshot_date, category, metric_key)
);

CREATE TABLE IF NOT EXISTS equipment_cost_logs (
    id SERIAL PRIMARY KEY,
    equipment_id INTEGER REFERENCES equipment(id) ON DELETE CASCADE,
    cost_type VARCHAR(50) NOT NULL,  -- 'maintenance', 'purchase', 'repair', 'move'
    amount NUMERIC(10,2) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for faster queries
CREATE INDEX IF NOT EXISTS idx_notification_logs_event ON notification_logs(event_type);
CREATE INDEX IF NOT EXISTS idx_notification_logs_recipient ON notification_logs(recipient_email);
CREATE INDEX IF NOT EXISTS idx_notification_logs_sent_at ON notification_logs(sent_at);
CREATE INDEX IF NOT EXISTS idx_report_snapshots_date ON report_snapshots(snapshot_date);
CREATE INDEX IF NOT EXISTS idx_report_snapshots_metric ON report_snapshots(metric_key);
CREATE INDEX IF NOT EXISTS idx_equipment_cost_equipment ON equipment_cost_logs(equipment_id);

-- Seed notification templates
INSERT INTO notification_templates (event_type, subject, body_html, is_active) VALUES
(
    'request_submitted',
    'New Equipment Request Submitted',
    '<html><body><h2>New Equipment Request</h2><p>Staff member {staff_name} has submitted a request for:</p><p><strong>{equipment_name}</strong> (Qty: {qty_requested})</p><p>Purpose: {purpose}</p><p><a href="{admin_link}">View Request</a></p></body></html>',
    true
),
(
    'request_approved',
    'Your Equipment Request Has Been Approved',
    '<html><body><h2>Request Approved</h2><p>Your request for {equipment_name} (Qty: {qty_allocated}) has been approved!</p><p><strong>Expected Return Date:</strong> {expected_return_date}</p><p>Please ensure you return the equipment by this date.</p></body></html>',
    true
),
(
    'request_rejected',
    'Your Equipment Request Has Been Declined',
    '<html><body><h2>Request Declined</h2><p>Unfortunately, your request for {equipment_name} could not be fulfilled at this time.</p><p>Available quantity: {qty_available}</p><p><a href="{request_link}">View Request</a></p></body></html>',
    true
),
(
    'maintenance_scheduled',
    'Maintenance Task Scheduled',
    '<html><body><h2>Maintenance Task Scheduled</h2><p>Equipment <strong>{equipment_name}</strong> has been scheduled for maintenance:</p><p><strong>Date:</strong> {schedule_date}</p><p><strong>Type:</strong> {maintenance_type}</p><p>Notes: {notes}</p></body></html>',
    true
),
(
    'maintenance_completed',
    'Equipment Maintenance Completed',
    '<html><body><h2>Maintenance Complete</h2><p>Maintenance for <strong>{equipment_name}</strong> has been completed.</p><p><strong>Completed Date:</strong> {completed_date}</p><p><strong>Cost:</strong> {cost}</p><p>The equipment is now available for use.</p></body></html>',
    true
),
(
    'equipment_due_return',
    'Equipment Return Due Soon',
    '<html><body><h2>Equipment Return Reminder</h2><p>You have <strong>{equipment_name}</strong> checked out.</p><p><strong>Expected Return Date:</strong> {expected_return_date}</p><p>Please return the equipment by this date. <a href="{allocation_link}">View Details</a></p></body></html>',
    true
),
(
    'equipment_overdue_return',
    'Equipment Return OVERDUE',
    '<html><body style="background-color: #ffcccc;"><h2 style="color: #cc0000;">OVERDUE RETURN</h2><p>Equipment <strong>{equipment_name}</strong> is now <strong>overdue</strong> for return.</p><p><strong>Expected Return Date:</strong> {expected_return_date}</p><p><strong>Days Overdue:</strong> {days_overdue}</p><p>Please return the equipment immediately. <a href="{allocation_link}">View Details</a></p></body></html>',
    true
)
ON CONFLICT (event_type) DO NOTHING;
