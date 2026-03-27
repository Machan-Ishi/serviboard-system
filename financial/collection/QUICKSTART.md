# QUICK START: Incoming Requests Feature

## ⚡ 5-Minute Setup

### 1. Create Database Table (2 minutes)
Open phpMyAdmin and run this SQL:

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

### 2. Add Sample Data (1 minute)
Option A - Using phpMyAdmin:
```sql
INSERT INTO service_requests (name, amount, status, created_at) VALUES
('System Installation', 5000.00, 'pending', NOW()),
('Network Setup', 3500.00, 'pending', NOW()),
('Software License', 2000.00, 'pending', NOW());
```

Option B - Using PHP script:
```bash
# In terminal/command line
php scripts/insert_sample_requests.php
```

### 3. Access the Feature (1 minute)
Navigate to:
```
http://localhost/FinancialSM/financial/collection/
```

### 4. Test It! (1 minute)
- Click **[Approve]** → See item appear in Financial Items
- Click **[Reject]** → See item marked as rejected
- Check summary cards update

---

## 📁 New Files Created

```
financial/collection/
  ├── index.php           (UI dashboard)
  ├── process.php         (Backend API)
  └── README.md           (Full documentation)

scripts/
  ├── create_service_requests_table.sql    (Schema)
  └── insert_sample_requests.php           (Sample data)
```

---

## 🎯 How It Works

**Pending Request Flow:**
```
1. CORE module sends request
2. Admin sees it in /financial/collection/
3. Admin clicks [Approve] or [Reject]
4. If Approved:
   - Inserted into financial_items
   - Appears in Financial Dashboard
   - Status = approved
5. If Rejected:
   - Status = rejected
   - Removed from pending list
```

---

## 💡 Key Features

✅ **Clean UI** - Modern card design with gradients
✅ **Summary Stats** - Pending count, items total, amount total
✅ **Audit Log** - All actions logged for compliance
✅ **Transaction-Safe** - Database consistency guaranteed
✅ **CSRF Protected** - All forms verified
✅ **Mobile Responsive** - Works on phone/tablet
✅ **Student-Friendly** - Well-commented code

---

## 🧪 Quick Test Guide

After opening `/financial/collection/`:

1. **See Sample Data?**
   - If yes ✅ → Continue to step 2
   - If no ❌ → Run `insert_sample_requests.php`

2. **Click [Approve] Button**
   - Check: "✓ Request approved" message appears
   - Check: Item appears in Recent Actions (green badge)
   - Check: Summary shows 1 less pending

3. **Click [Reject] Button**
   - Check: "✗ Request rejected" message appears
   - Check: Item appears in Recent Actions (red badge)
   - Check: Status shows "rejected"

4. **Verify Integration**
   - Go to Financial dashboard (if exists)
   - Check: Approved items show up there

---

## 📊 Database Schema

**service_requests table:**
```
id:         Auto-increment primary key
name:       Request title (e.g., "System Installation")
amount:     Decimal value (e.g., 5000.00)
status:     pending | approved | rejected
created_at: When request arrived
updated_at: When status changed
```

---

## 🔑 Key Functions Used

- `verify_csrf()` - Security check
- `finance_log_audit()` - Audit trail
- `finance_h()` - HTML escape
- `finance_money()` - Format currency
- PDO statements - Safe queries

---

## ⚙️ Configuration

No special configuration needed! Uses existing:
- `config/db.php` - Database connection
- `inc/functions.php` - Common functions
- `includes/require_admin.php` - Admin check

---

## 🚨 Troubleshooting

| Issue | Solution |
|-------|----------|
| No requests appear | Run `insert_sample_requests.php` |
| Table not found | Run SQL schema in phpMyAdmin |
| Permission denied | Log in as admin user |
| CSRF error | Clear cookies, reload |
| Database error | Check `config/db.php` connection |

---

## 📞 Support Files

Read these for more help:
- `financial/collection/README.md` - Full documentation
- `scripts/create_service_requests_table.sql` - SQL schema
- `scripts/insert_sample_requests.php` - Data seeding

---

**You're ready to go! 🚀**
