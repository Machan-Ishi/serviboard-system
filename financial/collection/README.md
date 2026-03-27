# COLLECTION Module: Incoming Requests Feature

## Overview

This feature implements the **Business Process Architecture (BPA)** workflow for "Incoming Requests from CORE → Financial (Collection)".

The CORE module sends financial-related service requests (e.g., System Installation, Network Setup) that need approval before being added to the financial tracking system.

---

## Architecture

### Directory Structure
```
/financial/
├── collection/
│   ├── index.php      → UI for managing incoming requests
│   └── process.php    → Backend processing logic
├── collection.php     (existing - other collection features)
└── ...
```

### Database Schema

**Table: `service_requests`**
```sql
id              INT AUTO_INCREMENT PRIMARY KEY
name            VARCHAR(255) NOT NULL
amount          DECIMAL(10,2) NOT NULL
status          ENUM('pending', 'approved', 'rejected')
created_at      TIMESTAMP (when request was received)
updated_at      TIMESTAMP (when status changed)
```

**Related Table: `financial_items`** (existing)
```sql
id              INT AUTO_INCREMENT PRIMARY KEY
name            VARCHAR(100)
amount          DECIMAL(10,2)
created_at      TIMESTAMP
```

---

## Workflow

### User Journey

```
┌─────────────────────────────────────────────────┐
│  CORE Module sends service request              │
│  (inserted into service_requests table)         │
└─────────────┬───────────────────────────────────┘
              │
              ▼
┌─────────────────────────────────────────────────┐
│  Financial Admin visits Collection module       │
│  /financial/collection/                         │
└─────────────┬───────────────────────────────────┘
              │
              ├─────────────────┬──────────────────┐
              │                 │                  │
              ▼                 ▼                  ▼
         [APPROVE]          [REJECT]          [NO ACTION]
              │                 │                  │
              ▼                 ▼                  ▼
    Insert into          Update status      Request stays
    financial_items      to 'rejected'       as 'pending'
    Update to 'approved'
              │                 │
              └────────┬────────┘
                       │
                       ▼
            Appears in Financial Dashboard
```

---

## Implementation Details

### 1. **Frontend (collection/index.php)**

**Features:**
- Displays all pending service requests in card format
- Shows request name, amount, and received date
- Two action buttons per request: **Approve** (green) and **Reject** (red)
- Summary cards showing:
  - Number of pending requests
  - Total financial items count
  - Total approved amount
- Recent actions history
- Modern, responsive UI with gradient styling

**Process Flow:**
```
User clicks [Approve] or [Reject]
     ↓
Form submitted via POST
     ↓
CSRF token verified
     ↓
Calls inline processing logic
     ↓
Database transaction executed
     ↓
Audit logged
     ↓
Redirect with success message
```

### 2. **Backend Processing (collection/process.php)**

**Purpose:** Alternative API endpoint for handling requests (optional)

**Usage:** Can be called via AJAX for async operations

**Response Format:**
```json
{
  "success": true,
  "message": "Request approved successfully.",
  "data": {
    "action": "approve",
    "request_id": 1,
    "name": "System Installation",
    "amount": "5000.00",
    "status": "approved"
  }
}
```

### 3. **Approval Logic**

**When user clicks APPROVE:**

```php
1. Fetch service_request details
2. Begin transaction
   a. Insert into financial_items (name, amount, NOW())
   b. Update service_requests SET status = 'approved'
   c. Log action to audit_logs
3. Commit transaction
4. Display success message
```

**Result:**
- Item appears in Financial Dashboard
- Financial totals update automatically
- Request is marked as 'approved'
- Action is logged for audit trail

### 4. **Rejection Logic**

**When user clicks REJECT:**

```php
1. Fetch service_request details
2. Update service_requests SET status = 'rejected'
3. Log action to audit_logs
4. Display success message
```

**Result:**
- Request remains in database (for history)
- Marked as 'rejected'
- Does NOT appear in Financial Items
- Action is logged for audit trail

---

## Setup Instructions

### Step 1: Create the Service Requests Table

Run this SQL to create the required table:

```sql
CREATE TABLE IF NOT EXISTS service_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**File:** `scripts/create_service_requests_table.sql`

### Step 2: Insert Sample Data (Optional)

To test the feature with sample data, run:

```bash
php scripts/insert_sample_requests.php
```

This will insert 8 sample service requests with 'pending' status.

**File:** `scripts/insert_sample_requests.php`

### Step 3: Access the UI

Navigate to:
```
http://localhost/FinancialSM/financial/collection/
```

You should see the Collection dashboard with pending requests.

---

## File Summary

### SQL Files
- **`scripts/create_service_requests_table.sql`** - Database schema
  - Creates `service_requests` table
  - Adds indexes for performance
  - Includes sample INSERT statements

### PHP Files
- **`financial/collection/index.php`** (NEW)
  - Main UI for managing incoming requests
  - Handles form submission and display
  - Shows summary statistics
  - Action history

- **`financial/collection/process.php`** (NEW)
  - Standalone processing endpoint
  - Can be used for AJAX calls
  - Returns JSON responses
  - Separation of concerns

- **`scripts/insert_sample_requests.php`** (NEW)
  - Utility to seed test data
  - Safe insertion with error handling
  - Provides feedback on console

---

## Code Quality

✅ **PDO Prepared Statements** - All queries use parameterized statements to prevent SQL injection

✅ **CSRF Protection** - All form submissions verify CSRF tokens via `verify_csrf()`

✅ **Transactions** - Approval process uses database transactions for consistency

✅ **Audit Logging** - All actions logged via `finance_log_audit()` function

✅ **Error Handling** - try/catch blocks with meaningful error messages

✅ **Responsive Design** - Mobile-friendly UI with media queries

✅ **Clean Code** - Well-commented, organized, student-friendly

---

## Integration Points

### With Financial Dashboard
- Approved items automatically appear in financial_items table
- Totals update via SQL SUM() aggregate function
- Dashboard queries financial_items table (no code changes needed)

### With Audit System
- All actions logged to audit_logs table
- Includes: user_id, action, module, record_id, old/new values, timestamp

### With Admin Authentication
- Requires `require_admin.php` (admin login verification)
- Only authenticated admins can approve/reject

---

## Testing Checklist

- [ ] SQL table created successfully
- [ ] Sample data inserted (8 requests visible)
- [ ] Click "Approve" on one request
  - [ ] Request moves to Financial Items
  - [ ] Status changed to 'approved'
  - [ ] Success message displayed
- [ ] Click "Reject" on one request
  - [ ] Request removed from pending list
  - [ ] Status changed to 'rejected'
  - [ ] Success message displayed
- [ ] Recent Actions section shows history
- [ ] Summary cards update correctly
- [ ] Responsive design works on mobile
- [ ] All inputs are sanitized (XSS prevention)

---

## Future Enhancements

**Potential improvements:**
1. Add filters by date range
2. Add reason field for rejections
3. Bulk approve/reject operations
4. Email notifications to CORE module
5. Request editing before approval
6. Export pending requests to CSV
7. Admin dashboard reports
8. Workflow approval chains (multi-step)

---

## Troubleshooting

**"Service requests table does not exist"**
- Run: `scripts/create_service_requests_table.sql`

**"No pending requests"**
- Run: `scripts/insert_sample_requests.php`

**"Permission denied" error**
- Check user is logged in as Admin
- Verify `admin` user role in database

**CSRF token error**
- Clear browser cookies
- Reload page
- Ensure `$_SESSION` is active

---

## Support

For questions about:
- **Database** → Check `config/db.php`
- **Audit logging** → Check `inc/finance_functions.php`
- **Authentication** → Check `includes/require_admin.php`
- **CSRF** → Check `inc/functions.php`

---

**Created:** March 18, 2026
**Module:** FINANCIAL → COLLECTION
**Feature:** Incoming Requests from CORE
