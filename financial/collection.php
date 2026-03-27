<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/finance_functions.php';
require_once __DIR__ . '/../config/supabase_config.php';

$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$pending_core_requests = [];
$pending_hr_requests = [];
$pending_logistic_requests = [];
$approved_requests = [];
$requestLogTotalRows = 0;
$total_pending_count = 0;
$display_core_requests = [];
$allCoreRequests = [];
$allHrRequests = [];
$allLogisticsRequests = [];
$coreSearch = trim((string) ($_GET['core_q'] ?? ''));
$coreStatus = trim((string) ($_GET['core_status'] ?? 'All'));
$coreSummary = [
    'pending' => 0,
    'approved_today' => 0,
    'rejected_today' => 0,
];
$coreHistoryMap = [];
$logisticsSearch = trim((string) ($_GET['log_q'] ?? ''));
$logisticsStatus = trim((string) ($_GET['log_status'] ?? 'All'));
$logisticsPage = max(1, (int) ($_GET['log_page'] ?? 1));
$logisticsPerPage = 5;
$logisticsOffset = ($logisticsPage - 1) * $logisticsPerPage;
$logisticsTotalRows = 0;
$logisticsTotalPages = 1;
$logisticsDetailId = max(0, (int) ($_GET['log_view'] ?? 0));
$logisticsDetailRow = null;
$logisticsSummary = [
    'total_pending_requests' => 0,
    'approved_today' => 0,
    'total_amount' => 0.0,
    'pending_quotation_count' => 0,
];
$logisticsHistoryMap = [];
$hrSearch = trim((string) ($_GET['hr_q'] ?? ''));
$hrStatus = trim((string) ($_GET['hr_status'] ?? 'Pending'));
$hrPage = max(1, (int) ($_GET['hr_page'] ?? 1));
$hrPerPage = 5;
$hrOffset = ($hrPage - 1) * $hrPerPage;
$hrTotalRows = 0;
$hrTotalPages = 1;
$hrDetailId = max(0, (int) ($_GET['hr_view'] ?? 0));
$hrDetailRow = null;
$hrSummary = [
    'total_pending_requests' => 0,
    'approved_today' => 0,
    'rejected_requests' => 0,
    'total_pending_amount' => 0.0,
];
$hrHistoryMap = [];

// Validate and sanitize inputs
if (strlen($search) > 100) {
    $search = substr($search, 0, 100);
}
$page = max(1, min($page, 1000)); // Prevent excessive pagination

try {
    $bootstrapMessages = finance_bootstrap($pdo);
} catch (Throwable $e) {
    $bootstrapMessages = ["Bootstrap warning: " . $e->getMessage()];
}
$supabaseStatus = class_exists('SupabaseConfig') ? SupabaseConfig::getStatus() : null;
$message = $_GET['message'] ?? '';
$error = $_GET['error'] ?? '';
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = $editId > 0 ? getCollection($pdo, $editId) : null;
$accounts = listAccounts($pdo);

// Ensure required request tables exist (e.g. on fresh setups)
if (function_exists('ensure_request_tables')) {
    ensure_request_tables($pdo);
}

function collection_example_request(string $module): array
{
    $today = date('Y-m-d H:i:s');

    return match ($module) {
        'service' => [
            [
                'id' => 0,
                'example_key' => 'core_software_engineer',
                'request_code' => 'CORE-0001',
                'request_type' => 'Job Posting Payment',
                'requested_by' => 'Alyssa Mendoza',
                'department' => 'CORE Recruitment',
                'description' => 'Example: Software Engineer job posting payment',
                'job_title' => 'Software Engineer',
                'company_name' => 'Global Tech',
                'amount' => 45000.00,
                'status' => 'Pending',
                'remarks' => 'Urgent campaign opening for Q2 hiring.',
                'created_at' => $today,
                'is_example' => true,
            ],
            [
                'id' => 0,
                'example_key' => 'core_data_analyst',
                'request_code' => 'CORE-0002',
                'request_type' => 'Job Posting Payment',
                'requested_by' => 'Marco Dela Cruz',
                'department' => 'CORE Operations',
                'description' => 'Example: Data Analyst job posting payment',
                'job_title' => 'Data Analyst',
                'company_name' => 'Data Insights',
                'amount' => 5500.00,
                'status' => 'Pending',
                'remarks' => 'Additional visibility requested for March intake.',
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'is_example' => true,
            ],
        ],
        'hr' => [
            [
                'id' => 0,
                'example_key' => 'hr_onboarding',
                'request_code' => 'HR-0001',
                'request_type' => 'New Hire Equipment',
                'employee_name' => 'Angela Cruz',
                'title' => 'New Hire Equipment',
                'description' => 'Laptop, headset, and access card setup for an incoming employee.',
                'request_details' => 'Example: onboarding laptop and ID setup for a new employee',
                'department_name' => 'Human Resources',
                'status' => 'Pending',
                'amount' => 12500.00,
                'remarks' => 'Initial budget estimate submitted by HR.',
                'created_at' => $today,
                'is_example' => true,
            ],
            [
                'id' => 0,
                'example_key' => 'hr_training',
                'request_code' => 'HR-0002',
                'request_type' => 'Training Request',
                'employee_name' => 'Miguel Santos',
                'title' => 'Training Request',
                'description' => 'Compliance seminar registration and transportation allowance.',
                'request_details' => 'Example: training budget request for compliance seminar',
                'department_name' => 'Human Resources',
                'status' => 'Needs Revision',
                'amount' => 12500.00,
                'remarks' => 'Please attach the seminar program before approval.',
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'is_example' => true,
            ],
        ],
        'logistic' => [
            [
                'id' => 0,
                'example_key' => 'log_restock',
                'request_code' => 'LOG-0001',
                'request_type' => 'Restocking',
                'title' => 'Office Supplies',
                'description' => 'Office supplies restock for main office',
                'item_name' => 'Example: office supplies restock for main office',
                'requested_by_name' => 'Maria Santos',
                'department_name' => 'Warehouse',
                'status' => 'Pending',
                'amount' => null,
                'remarks' => 'Awaiting vendor quotation.',
                'created_at' => $today,
                'is_example' => true,
            ],
            [
                'id' => 0,
                'example_key' => 'log_fuel',
                'request_code' => 'LOG-0002',
                'request_type' => 'Maintenance',
                'title' => 'Delivery Van Fuel Reimbursement',
                'description' => 'Fuel reimbursement for delivery vehicle used in urgent dispatch.',
                'item_name' => 'Example: delivery van fuel reimbursement request',
                'amount' => 8750.00,
                'requested_by_name' => 'Paolo Reyes',
                'department_name' => 'Logistics',
                'status' => 'Needs Revision',
                'remarks' => 'Please attach the official fuel receipt before approval.',
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'is_example' => true,
            ],
        ],
        default => [],
    };
}

function collection_create_example_request(PDO $pdo, string $requestType, string $exampleKey): int
{
    $schema = finance_schema_prefix($pdo);

    return match ($requestType) {
        'service' => (function () use ($pdo, $schema, $exampleKey): int {
            if (!finance_table_exists($pdo, 'job_posting_payments')) {
                throw new RuntimeException('job_posting_payments table is not available locally.');
            }

            $examples = [
                'core_software_engineer' => [
                    'job_title' => 'Software Engineer',
                    'company_name' => 'Global Tech',
                    'amount' => 45000.00,
                ],
                'core_data_analyst' => [
                    'job_title' => 'Data Analyst',
                    'company_name' => 'Data Insights',
                    'amount' => 5500.00,
                ],
            ];

            if (!isset($examples[$exampleKey])) {
                throw new InvalidArgumentException('Unknown CORE example request.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO {$schema}job_posting_payments (job_title, company_name, amount, status, created_at, updated_at)
                VALUES (:job_title, :company_name, :amount, 'pending', NOW(), NOW())
            ");
            $stmt->execute($examples[$exampleKey]);

            return (int) $pdo->lastInsertId();
        })(),
        'hr' => (function () use ($pdo, $schema, $exampleKey): int {
            $examples = [
                'hr_onboarding' => [
                    'request_details' => 'Onboarding laptop and ID setup for a new employee',
                    'department' => 'HR',
                ],
                'hr_training' => [
                    'request_details' => 'Training budget request for compliance seminar',
                    'department' => 'HR',
                ],
            ];

            if (!isset($examples[$exampleKey])) {
                throw new InvalidArgumentException('Unknown HR example request.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO {$schema}hr_requests (request_details, department, status, created_at, updated_at)
                VALUES (:request_details, :department, 'pending', NOW(), NOW())
            ");
            $stmt->execute($examples[$exampleKey]);

            return (int) $pdo->lastInsertId();
        })(),
        'logistic' => (function () use ($pdo, $schema, $exampleKey): int {
            $examples = [
                'log_restock' => [
                    'item_name' => 'Office supplies restock for main office',
                    'quantity' => 25,
                    'destination' => 'Main Office',
                ],
                'log_fuel' => [
                    'item_name' => 'Delivery van fuel reimbursement request',
                    'quantity' => 1,
                    'destination' => 'Operations Garage',
                ],
            ];

            if (!isset($examples[$exampleKey])) {
                throw new InvalidArgumentException('Unknown logistics example request.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO {$schema}logistic_requests (item_name, quantity, destination, status, created_at, updated_at)
                VALUES (:item_name, :quantity, :destination, 'pending', NOW(), NOW())
            ");
            $stmt->execute($examples[$exampleKey]);

            return (int) $pdo->lastInsertId();
        })(),
        default => throw new InvalidArgumentException('Unsupported example request type.'),
    };
}

function collection_redirect(string $message = '', string $error = ''): never
{
    $params = [];
    if ($message !== '') {
        $params['message'] = $message;
    }
    if ($error !== '') {
        $params['error'] = $error;
    }

    $target = 'collection.php';
    if ($params !== []) {
        $target .= '?' . http_build_query($params);
    }

    header('Location: ' . $target);
    exit;
}

function collection_core_badge_class(string $status): string
{
    return match (trim($status)) {
        'Approved' => 'badge-paid',
        'Rejected' => 'badge-cancelled',
        'Revision Requested' => 'badge-overdue',
        'Ready for Disbursement' => 'badge-partial',
        'Released' => 'badge-paid',
        'Linked to AR/AP' => 'badge-partial',
        'Linked to Budget' => 'badge-partial',
        default => 'badge-pending',
    };
}

// Fetch pending requests from all modules
try {
    $allCoreRequests = finance_get_core_review_request_pool($pdo);
    $pending_core_requests = finance_filter_core_review_rows($allCoreRequests, ['status' => 'Pending']);
    $display_core_requests = finance_filter_core_review_rows($allCoreRequests, [
        'search' => $coreSearch,
        'status' => $coreStatus,
    ]);
    $coreSummary = finance_get_core_request_summary_from_rows($allCoreRequests);
    $schema = finance_schema_prefix($pdo);
    $pending_hr_requests = finance_get_hr_review_requests($pdo, [
        'search' => $hrSearch,
        'status' => $hrStatus,
    ], $hrPerPage, $hrOffset);
    $hrTotalRows = finance_count_hr_review_requests($pdo, [
        'search' => $hrSearch,
        'status' => $hrStatus,
    ]);
    $hrTotalPages = max(1, (int) ceil($hrTotalRows / $hrPerPage));
    if ($hrDetailId > 0) {
        $hrDetailRow = finance_get_hr_request_detail($pdo, $hrDetailId);
    }
    $allHrRequests = finance_get_hr_review_requests($pdo, ['status' => 'All'], 1000, 0);
    $hrSummary = finance_get_hr_request_summary_from_rows($allHrRequests);
    $pendingHrCount = finance_count_hr_review_requests($pdo, ['status' => 'Pending']);
    $pending_logistic_requests = finance_get_logistics_review_requests($pdo, [
        'search' => $logisticsSearch,
        'status' => $logisticsStatus,
    ], $logisticsPerPage, $logisticsOffset);
    $logisticsTotalRows = finance_count_logistics_review_requests($pdo, [
        'search' => $logisticsSearch,
        'status' => $logisticsStatus,
    ]);
    $logisticsTotalPages = max(1, (int) ceil($logisticsTotalRows / $logisticsPerPage));
    if ($logisticsDetailId > 0) {
        $logisticsDetailRow = finance_get_logistics_request_detail($pdo, $logisticsDetailId);
    }
    $pendingLogisticsCount = finance_count_logistics_review_requests($pdo, ['status' => 'Pending']);
    $allLogisticsRequests = finance_get_logistics_review_requests($pdo, ['status' => 'All'], 1000, 0);
    $logisticsSummary = finance_get_logistics_request_summary_from_rows($allLogisticsRequests);

    // Combine all pending counts
    $total_pending_count = (int) ($coreSummary['pending'] ?? count($pending_core_requests)) + $pendingHrCount + $pendingLogisticsCount;

    // Use the persistent approval log so every approved action appears in the history view.
} catch (Exception $e) {
    $pending_core_requests = [];
    $pending_hr_requests = [];
    $pending_logistic_requests = [];
    $error = "Could not fetch requests: " . $e->getMessage();
}

try {
    finance_ensure_request_action_logs_ready($pdo);
    $approved_requests = finance_get_request_action_logs($pdo, 3);
    $requestLogTotalRows = finance_count_request_action_logs($pdo);
} catch (Throwable $e) {
    $approved_requests = [];
    $requestLogTotalRows = 0;
    $error = trim($error . ' Request log error: ' . $e->getMessage());
}

try {
    finance_backfill_approved_collections($pdo);
} catch (Throwable $e) {
    $error = trim($error . ' Collection backfill error: ' . $e->getMessage());
}

$hasCoreFilters = $coreSearch !== '' || strtolower($coreStatus) !== 'all';
$display_core_requests = !empty($display_core_requests) ? $display_core_requests : (!$hasCoreFilters ? collection_example_request('service') : []);
$hasHrFilters = $hrSearch !== '' || strtolower($hrStatus) !== 'pending';
$display_hr_requests = !empty($pending_hr_requests) ? $pending_hr_requests : (!$hasHrFilters ? collection_example_request('hr') : []);
$display_logistic_requests = $pending_logistic_requests;

$formatHistoryLines = static function (array $historyRows): array {
    $historyLines = [];
    foreach ($historyRows as $historyRow) {
        $historyLines[] = sprintf(
            '%s | %s | %s%s',
            date('M d, Y g:i A', strtotime((string) ($historyRow['created_at'] ?? 'now'))),
            (string) ($historyRow['status'] ?? '-'),
            (string) ($historyRow['acted_by'] ?? 'Finance Reviewer'),
            trim((string) ($historyRow['remarks'] ?? '')) !== '' ? ' | ' . trim((string) $historyRow['remarks']) : ''
        );
    }

    return $historyLines;
};

try {
    $coreHistoryRows = finance_get_request_history_map($pdo, 'CORE', array_map(static fn (array $row): string => trim((string) ($row['id'] ?? '')), $display_core_requests), 5);
    foreach ($display_core_requests as $coreRequestRow) {
        $coreRequestId = trim((string) ($coreRequestRow['id'] ?? ''));
        if ($coreRequestId === '' || $coreRequestId === '0') {
            $coreHistoryMap[$coreRequestId !== '' ? $coreRequestId : uniqid('core_', true)] = [];
            continue;
        }
        $coreHistoryMap[$coreRequestId] = $formatHistoryLines($coreHistoryRows[$coreRequestId] ?? []);
    }
} catch (Throwable) {
    foreach ($display_core_requests as $coreRequestRow) {
        $coreRequestId = trim((string) ($coreRequestRow['id'] ?? ''));
        if ($coreRequestId !== '' && $coreRequestId !== '0') {
            $coreHistoryMap[$coreRequestId] = [];
        }
    }
}

try {
    $hrHistoryRows = finance_get_request_history_map($pdo, 'HR', array_map(static fn (array $row): string => trim((string) ($row['id'] ?? '')), $display_hr_requests), 3);
    foreach ($display_hr_requests as $hrRequestRow) {
        $hrRequestId = trim((string) ($hrRequestRow['id'] ?? ''));
        if ($hrRequestId === '' || $hrRequestId === '0') {
            continue;
        }
        $hrHistoryMap[$hrRequestId] = $formatHistoryLines($hrHistoryRows[$hrRequestId] ?? []);
    }
} catch (Throwable) {
    foreach ($display_hr_requests as $hrRequestRow) {
        $hrRequestId = trim((string) ($hrRequestRow['id'] ?? ''));
        if ($hrRequestId !== '' && $hrRequestId !== '0') {
            $hrHistoryMap[$hrRequestId] = [];
        }
    }
}

try {
    $logisticsHistoryRows = finance_get_request_history_map($pdo, 'LOGISTICS', array_map(static fn (array $row): string => trim((string) ($row['id'] ?? '')), $display_logistic_requests), 3);
    foreach ($display_logistic_requests as $logisticsRequestRow) {
        $logisticsRequestId = trim((string) ($logisticsRequestRow['id'] ?? ''));
        if ($logisticsRequestId === '' || $logisticsRequestId === '0') {
            continue;
        }
        $logisticsHistoryMap[$logisticsRequestId] = $formatHistoryLines($logisticsHistoryRows[$logisticsRequestId] ?? []);
    }
} catch (Throwable) {
    foreach ($display_logistic_requests as $logisticsRequestRow) {
        $logisticsRequestId = trim((string) ($logisticsRequestRow['id'] ?? ''));
        if ($logisticsRequestId !== '' && $logisticsRequestId !== '0') {
            $logisticsHistoryMap[$logisticsRequestId] = [];
        }
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf()) {
            throw new Exception('Invalid request (CSRF failure).');
        }
        $action = $_POST['action'] ?? '';

        if ($action === 'save_collection') {
            $error = '';
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                updateCollection($pdo, $id, $_POST);
                $message = 'Collection updated successfully.';
            } else {
                createCollection($pdo, $_POST);
                $message = 'Collection recorded successfully.';
            }
            collection_redirect($message, $error);
        } elseif ($action === 'delete_collection') {
            $error = '';
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                deleteCollection($pdo, $id);
                $message = 'Collection record deleted.';
            }
            collection_redirect($message, $error);
        } elseif ($action === 'seed_sample_requests') {
            $error = '';
            try {
                $isPg = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql');
                $conflict = $isPg ? "ON CONFLICT DO NOTHING" : "";
                $ignore = $isPg ? "" : "IGNORE";
                
                // CORE (Job Posting Payments)
                $pdo->exec("INSERT {$ignore} INTO job_posting_payments (job_title, company_name, amount, status, updated_at) VALUES 
                    ('Software Engineer', 'Global Tech', 45000.00, 'pending', NOW()), 
                    ('Data Analyst', 'Data Insights', 5500.00, 'pending', NOW()),
                    ('Web Developer', 'Creative Studio', 12000.00, 'approved', NOW())
                    {$conflict}");
                // HR
                $pdo->exec("INSERT {$ignore} INTO hr_requests (request_details, department, status, updated_at) VALUES 
                    ('New Hire Equipment', 'IT', 'pending', NOW()),
                    ('Employee Wellness Program', 'HR', 'approved', NOW())
                    {$conflict}");
                // Logistics
                $pdo->exec("INSERT {$ignore} INTO logistic_requests (item_name, quantity, destination, status, updated_at) VALUES 
                    ('Office Supplies', 10, 'Main Office', 'pending', NOW()),
                    ('Vehicle Site Visit', 1, 'Project Site A', 'approved', NOW())
                    {$conflict}");
                
                $message = 'Sample requests seeded successfully.';
            } catch (Exception $e) {
                $error = "Error seeding: " . $e->getMessage();
            }
            collection_redirect($message, $error);
        } elseif ($action === 'approve_request' || $action === 'reject_request' || $action === 'request_revision') {
            $error = '';
            $requestType = (string) ($_POST['request_type'] ?? '');
            $requestIdRaw = trim((string) ($_POST['request_id'] ?? ''));
            $requestId = $requestType === 'hr' ? $requestIdRaw : (string) ((int) $requestIdRaw);
            $remarks = (string) ($_POST['remarks'] ?? '');
            $exampleKey = trim((string) ($_POST['example_key'] ?? ''));
            $requestAmount = isset($_POST['request_amount']) && $_POST['request_amount'] !== ''
                ? (float) $_POST['request_amount']
                : null;

            $isMissingRequestId = $requestType === 'hr' ? ($requestId === '' || $requestId === '0') : ((int) $requestId <= 0);
            if ($isMissingRequestId && $exampleKey !== '' && in_array($requestType, ['service', 'hr', 'logistic'], true)) {
                $requestId = (string) collection_create_example_request($pdo, $requestType, $exampleKey);
            }

            $hasRequestId = $requestType === 'hr' ? ($requestId !== '' && $requestId !== '0') : ((int) $requestId > 0);
            if ($hasRequestId && in_array($requestType, ['service', 'hr', 'logistic'])) {
                try {
                    if ($requestType === 'logistic') {
                        $logisticsActionRow = finance_get_logistics_request_source_row($pdo, (int) $requestId);
                        if ($logisticsActionRow !== null && !finance_logistics_is_actionable($logisticsActionRow) && in_array($action, ['approve_request', 'reject_request', 'request_revision'], true)) {
                            throw new RuntimeException('This logistics request is no longer available for action.');
                        }
                    }
                    if ($action === 'approve_request') {
                        if ($requestType === 'service') {
                            approveServiceRequest($pdo, (int) $requestId);
                        } elseif ($requestType === 'hr') {
                            approveHRRequest($pdo, $requestId, $requestAmount);
                        } elseif ($requestType === 'logistic') {
                            approveLogisticsRequest($pdo, (int) $requestId, $requestAmount);
                        }
                        $message = "Request #{$requestId} from " . strtoupper($requestType) . " has been approved.";
                    } elseif ($action === 'request_revision') {
                        if ($requestType === 'service') {
                            requestCoreRevision($pdo, (int) $requestId, $remarks);
                            $message = "Request #{$requestId} from CORE has been returned for revision.";
                        } elseif ($requestType === 'hr') {
                            requestHRRevision($pdo, $requestId, $remarks);
                            $message = "Request #{$requestId} from HR has been returned for revision.";
                        } elseif ($requestType === 'logistic') {
                            requestLogisticsRevision($pdo, (int) $requestId, $remarks, $requestAmount);
                            $message = "Request #{$requestId} from LOGISTIC has been returned for revision.";
                        } else {
                            $error = 'Revision flow is available for CORE, HR, and Logistics requests only.';
                        }
                    } else {
                        if ($requestType === 'service') {
                            rejectServiceRequest($pdo, (int) $requestId, $remarks);
                        } elseif ($requestType === 'hr') {
                            rejectHRRequest($pdo, $requestId, $remarks, $requestAmount);
                        } elseif ($requestType === 'logistic') {
                            rejectLogisticsRequest($pdo, (int) $requestId, $remarks, $requestAmount);
                        }
                        $message = "Request #{$requestId} from " . strtoupper($requestType) . " has been rejected.";
                    }
                } catch (Exception $e) {
                    $error = "Error processing request: " . $e->getMessage();
                }
            }
            collection_redirect($message, $error);
        } elseif ($action === 'link_core_disbursement') {
            $error = '';
            try {
                $requestId = (int) ($_POST['request_id'] ?? 0);
                $remarks = trim((string) ($_POST['remarks'] ?? ''));
                if ($requestId <= 0) {
                    throw new RuntimeException('CORE request ID is required.');
                }
                finance_link_core_request_to_disbursement($pdo, $requestId, $remarks);
                $message = "CORE request #{$requestId} is now ready for disbursement.";
            } catch (Throwable $e) {
                $error = 'Error linking CORE request to disbursement: ' . $e->getMessage();
            }
            collection_redirect($message, $error);
        } elseif ($action === 'link_core_arap') {
            $error = '';
            try {
                $requestId = (int) ($_POST['request_id'] ?? 0);
                $entryType = trim((string) ($_POST['entry_type'] ?? 'AR'));
                $dueDate = trim((string) ($_POST['due_date'] ?? ''));
                $remarks = trim((string) ($_POST['remarks'] ?? ''));
                if ($requestId <= 0) {
                    throw new RuntimeException('CORE request ID is required.');
                }
                $linkedId = finance_link_core_request_to_arap($pdo, $requestId, $entryType, $dueDate !== '' ? $dueDate : null, $remarks);
                $message = strtoupper($entryType) . " entry #{$linkedId} linked to CORE request #{$requestId}.";
            } catch (Throwable $e) {
                $error = 'Error linking CORE request to AR/AP: ' . $e->getMessage();
            }
            collection_redirect($message, $error);
        } elseif ($action === 'link_core_budget') {
            $error = '';
            try {
                $requestId = (int) ($_POST['request_id'] ?? 0);
                $budgetId = (int) ($_POST['budget_id'] ?? 0);
                $remarks = trim((string) ($_POST['remarks'] ?? ''));
                if ($requestId <= 0 || $budgetId <= 0) {
                    throw new RuntimeException('CORE request and budget are required.');
                }
                $budgetResult = finance_link_core_request_to_budget($pdo, $requestId, $budgetId, $remarks);
                $message = !empty($budgetResult['over_budget'])
                    ? "CORE request #{$requestId} linked to budget #{$budgetId} with an over-budget warning."
                    : "CORE request #{$requestId} linked to budget #{$budgetId}.";
            } catch (Throwable $e) {
                $error = 'Error linking CORE request to budget: ' . $e->getMessage();
            }
            collection_redirect($message, $error);
        } elseif ($action === 'release_logistics_funds') {
            $error = '';
            try {
                $requestId = (int) ($_POST['request_id'] ?? 0);
                $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'Bank Transfer'));
                if ($requestId <= 0) {
                    throw new RuntimeException('Logistics request ID is required.');
                }
                $disbursementId = finance_release_request_for_disbursement($pdo, 'LOGISTICS', (string) $requestId, $paymentMethod !== '' ? $paymentMethod : 'Bank Transfer');
                $message = "Logistics request #{$requestId} released through disbursement #{$disbursementId}.";
            } catch (Throwable $e) {
                $error = 'Error releasing logistics funds: ' . $e->getMessage();
            }
            collection_redirect($message, $error);
        } elseif ($action === 'create_logistics_payable') {
            $error = '';
            try {
                $requestId = (int) ($_POST['request_id'] ?? 0);
                $vendorName = trim((string) ($_POST['vendor_name'] ?? ''));
                $dueDate = trim((string) ($_POST['due_date'] ?? ''));
                $remarks = trim((string) ($_POST['remarks'] ?? ''));
                if ($requestId <= 0) {
                    throw new RuntimeException('Logistics request ID is required.');
                }
                $arApId = finance_link_logistics_request_to_ap($pdo, $requestId, $vendorName, $dueDate !== '' ? $dueDate : null, $remarks);
                $message = "Payable #{$arApId} created for logistics request #{$requestId}.";
            } catch (Throwable $e) {
                $error = 'Error creating logistics payable: ' . $e->getMessage();
            }
            collection_redirect($message, $error);
        } elseif ($action === 'sync_from_supabase') {
            $error = '';
            try {
                $count = sync_job_posting_payments_from_supabase($pdo);
                $message = "Successfully synced {$count} requests from Supabase.";
            } catch (Exception $e) {
                $error = "Sync failed: " . $e->getMessage();
            }
            collection_redirect($message, $error);
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$collectionsThisMonth = getCollectionsThisMonth($pdo);
$totalRows = countCollections($pdo, $search);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage; // Recalculate offset if page was adjusted
$rows = listCollectionsPaginated($pdo, $search, $perPage, $offset);
$budgetRows = getBudgetList($pdo);
$incomeSummary = getIncomeSummary($pdo);
$budgetDashboardSummary = finance_get_budget_dashboard_summary_from_rows($budgetRows);
$integrationSummary = [
    'approved_total' => 0,
    'disbursed_total' => 0,
    'recorded_total' => 0,
    'pending_by_module' => [
        'CORE' => (int) ($coreSummary['pending'] ?? 0),
        'HR' => (int) ($hrSummary['total_pending_requests'] ?? 0),
        'LOGISTICS' => (int) ($logisticsSummary['total_pending_requests'] ?? 0),
    ],
];
$coreFlowMap = [];
$hrFlowMap = [];
$logisticsFlowMap = [];

$accumulateIntegrationSummary = static function (string $module, array $requests) use ($pdo, &$integrationSummary): array {
    $map = [];
    foreach ($requests as $request) {
        $requestId = trim((string) ($request['id'] ?? ''));
        if ($requestId === '' || $requestId === '0') {
            continue;
        }
        $snapshot = finance_request_flow_snapshot($pdo, $module, $requestId, $request);
        $map[$requestId] = $snapshot;
        if ($snapshot['approved']) {
            $integrationSummary['approved_total']++;
        }
        if ($snapshot['disbursed']) {
            $integrationSummary['disbursed_total']++;
        }
        if ($snapshot['recorded']) {
            $integrationSummary['recorded_total']++;
        }
    }

    return $map;
};

$coreFlowMap = $accumulateIntegrationSummary('CORE', $allCoreRequests);
$hrFlowMap = $accumulateIntegrationSummary('HR', $allHrRequests);
$logisticsFlowMap = $accumulateIntegrationSummary('LOGISTICS', $allLogisticsRequests);

function collection_badge_class(string $status): string
{
    $status = strtoupper($status);
    if ($status === 'POSTED' || $status === 'PAID') {
        return 'badge-paid';
    }
    if ($status === 'PENDING' || $status === 'PARTIAL') {
        return 'badge-partial';
    }
    if ($status === 'CANCELLED' || $status === 'VOID') {
        return 'badge-overdue';
    }

    return 'badge-unpaid';
}

function collection_flow_stage_badge_class(string $stage): string
{
    return match (strtolower(trim($stage))) {
        'approved' => 'badge-partial',
        'linked' => 'badge-submitted',
        'disbursed' => 'badge-paid',
        'recorded' => 'badge-paid',
        default => 'badge-pending',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Collection - Financial - ServiBoard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="strict-origin-when-cross-origin">
  <link rel="stylesheet" href="../assets/financial.css">
  <style>
    .collection-shell {
      padding: 20px;
    }
    .collection-header {
      margin-bottom: 18px;
    }
    .collection-title {
      margin: 0 0 6px;
      line-height: 1.2;
    }
    .collection-subtitle {
      margin: 0;
      max-width: 760px;
    }
    .collection-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 14px;
      align-items: stretch;
    }
    .integration-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
      gap: 14px;
      margin-top: 14px;
    }
    .integration-card {
      min-height: 92px;
      padding: 16px;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,0.08);
      background: linear-gradient(180deg, rgba(255,255,255,0.045), rgba(255,255,255,0.02));
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 8px;
    }
    .integration-card .stat-label {
      font-size: 11px;
      letter-spacing: 0.06em;
    }
    .integration-card .stat-note {
      color: var(--muted);
      font-size: 11px;
      line-height: 1.4;
    }
    .request-status-stack {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 6px;
      min-width: 170px;
    }
    .request-flow-line {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
      color: var(--muted);
      font-size: 10px;
      line-height: 1.3;
    }
    .request-flow-step {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 20px;
      padding: 2px 8px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.03);
      color: var(--muted);
      white-space: nowrap;
    }
    .request-flow-step.active {
      color: #dce8ff;
      border-color: rgba(61, 136, 234, 0.35);
      background: rgba(61, 136, 234, 0.12);
    }
    .request-flow-arrow {
      color: rgba(255,255,255,0.3);
      font-size: 11px;
    }
    .impact-badges {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
    }
    .impact-badge {
      display: inline-flex;
      align-items: center;
      min-height: 20px;
      padding: 2px 8px;
      border-radius: 999px;
      border: 1px solid rgba(240,175,28,0.22);
      background: rgba(240,175,28,0.08);
      color: #f5c96b;
      font-size: 10px;
      white-space: nowrap;
    }
    .linked-record-note {
      display: block;
      color: var(--muted);
      font-size: 11px;
      line-height: 1.4;
      margin-top: 4px;
    }
    .stat-block {
      min-height: 74px;
      justify-content: center;
    }
    .section-card {
      overflow: hidden;
    }
    .section-head {
      display: flex;
      align-items: flex-start;
      gap: 14px;
      flex-wrap: wrap;
    }
    .section-title {
      min-width: 0;
      flex: 1 1 320px;
    }
    .section-title h2 {
      line-height: 1.2;
      margin-bottom: 6px;
    }
    .section-title p {
      margin: 0;
      max-width: 720px;
    }
    .notion-table {
      width: 100%;
      table-layout: auto;
      border-collapse: collapse;
    }
    .notion-table th,
    .notion-table td {
      vertical-align: middle;
    }
    .notion-table td {
      line-height: 1.45;
    }
    .notion-table td:last-child,
    .notion-table th:last-child {
      text-align: right;
    }
    .table-actions {
      width: 1%;
      white-space: nowrap;
    }
    .inline-form {
      display: inline-flex;
      align-items: center;
      margin: 0;
    }
    .btn-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 34px;
      padding: 7px 12px;
      border-radius: 10px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      background: rgba(255, 255, 255, 0.03);
      color: var(--text);
      font-size: 12px;
      font-weight: 600;
      text-decoration: none;
      transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
    }
    .btn-link:hover {
      background: rgba(255, 255, 255, 0.06);
      text-decoration: none;
    }
    .btn-link.success {
      color: #37d39a;
      border-color: rgba(55, 211, 154, 0.3);
      background: rgba(55, 211, 154, 0.12);
    }
    .btn-link.danger {
      color: #ff7a7a;
      border-color: rgba(255, 92, 92, 0.3);
      background: rgba(255, 92, 92, 0.12);
    }
    .collection-shell > .panel-grid {
      align-items: start;
    }
    .collection-shell > .panel-grid .table-card {
      min-width: 0;
    }
    .collection-shell > .panel-grid .table-card .table-title {
      margin-bottom: 14px;
    }
    .collection-shell > .panel-grid .table-card .panel-tools {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 14px;
    }
    .collection-shell > .panel-grid .table-card .panel-tools .input {
      flex: 1 1 260px;
      min-width: 0;
    }
    .collection-shell > .panel-grid .table-card .panel-tools .btn {
      flex: 0 0 auto;
      min-width: 84px;
    }
    .collection-shell > .panel-grid .table-card .table-wrap {
      border: 1px solid rgba(45, 67, 99, 0.72);
      border-radius: 16px;
      overflow: hidden;
      background: rgba(7, 11, 18, 0.36);
    }
    .collection-shell > .panel-grid .table-card .table-wrap.table-scroll-pane {
      max-height: 360px;
      overflow: auto;
    }
    .collection-shell > .panel-grid .table-card .notion-table th,
    .collection-shell > .panel-grid .table-card .notion-table td {
      padding-top: 14px;
      padding-bottom: 14px;
    }
    .collection-shell > .panel-grid .table-card .notion-table thead th {
      white-space: nowrap;
    }
    .collection-shell > .panel-grid .table-card .notion-table tbody tr:hover td {
      background: rgba(59, 130, 246, 0.04);
    }
    .collection-shell > .panel-grid .table-card .notion-table th:nth-child(1),
    .collection-shell > .panel-grid .table-card .notion-table td:nth-child(1),
    .collection-shell > .panel-grid .table-card .notion-table th:nth-child(5),
    .collection-shell > .panel-grid .table-card .notion-table td:nth-child(5) {
      white-space: nowrap;
    }
    .collection-shell > .panel-grid .table-card .notion-table th:nth-child(3),
    .collection-shell > .panel-grid .table-card .notion-table td:nth-child(3) {
      text-align: right;
      white-space: nowrap;
    }
    .collection-shell > .panel-grid .table-card .notion-table th:nth-child(6),
    .collection-shell > .panel-grid .table-card .notion-table td:nth-child(6) {
      width: 120px;
      text-align: center;
      white-space: nowrap;
    }
    .collection-shell > .panel-grid .table-card .status-badge {
      min-width: 88px;
      justify-content: center;
      text-align: center;
    }
    .collection-shell > .panel-grid .table-card .table-actions {
      min-width: 156px;
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 10px;
    }
    .collection-shell > .panel-grid .table-card .inline-form {
      margin: 0;
    }
    .collection-shell > .panel-grid .table-card .btn-link {
      min-width: 70px;
    }
    .collection-shell > .section-card:first-of-type .notion-table th:nth-child(1),
    .collection-shell > .section-card:first-of-type .notion-table td:nth-child(1) {
      width: 150px;
      white-space: nowrap;
    }
    .collection-shell > .section-card:first-of-type .core-request-scroll {
      max-height: 340px;
      overflow-y: auto;
      overflow-x: hidden;
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 14px;
      background: rgba(7, 11, 18, 0.55);
      scrollbar-width: thin;
      scrollbar-color: rgba(214, 176, 92, 0.55) rgba(255, 255, 255, 0.04);
    }
    .collection-shell > .section-card:first-of-type .core-request-scroll::-webkit-scrollbar {
      width: 10px;
    }
    .collection-shell > .section-card:first-of-type .core-request-scroll::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.04);
      border-radius: 999px;
    }
    .collection-shell > .section-card:first-of-type .core-request-scroll::-webkit-scrollbar-thumb {
      background: linear-gradient(180deg, rgba(214, 176, 92, 0.72), rgba(214, 176, 92, 0.42));
      border-radius: 999px;
      border: 2px solid rgba(7, 11, 18, 0.65);
    }
    .collection-shell > .section-card:first-of-type .core-request-scroll::-webkit-scrollbar-thumb:hover {
      background: linear-gradient(180deg, rgba(214, 176, 92, 0.82), rgba(214, 176, 92, 0.52));
    }
    .collection-shell > .section-card:first-of-type .core-request-scroll .notion-table {
      margin: 0;
    }
    .collection-shell > .section-card:first-of-type .core-request-scroll .notion-table thead th {
      position: sticky;
      top: 0;
      z-index: 2;
      background: #111827;
      box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.08);
    }
    .collection-shell > .section-card:first-of-type .core-request-scroll .notion-table tbody tr:last-child td {
      border-bottom: 0;
    }
    .core-table-wrap.table-scroll-pane,
    .hr-request-scroll.table-scroll-pane,
    .logistics-request-scroll.table-scroll-pane {
      max-height: 360px;
      overflow: auto;
    }
    .core-table-wrap.table-scroll-pane .notion-table thead th,
    .hr-request-scroll.table-scroll-pane .notion-table thead th,
    .logistics-request-scroll.table-scroll-pane .notion-table thead th {
      position: sticky;
      top: 0;
      z-index: 2;
      background: #111827;
      box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.08);
    }
    .collection-shell > .section-card:first-of-type .notion-table th:nth-child(3),
    .collection-shell > .section-card:first-of-type .notion-table td:nth-child(3) {
      width: 150px;
      white-space: nowrap;
      text-align: right;
    }
    .collection-shell > .section-card:first-of-type .notion-table th:nth-child(4),
    .collection-shell > .section-card:first-of-type .notion-table td:nth-child(4) {
      width: 220px;
    }
    .collection-shell > .section-card:first-of-type .table-actions {
      min-width: 220px;
      white-space: nowrap;
    }
    .collection-shell > .section-card:first-of-type .table-actions .inline-form + .inline-form {
      margin-left: 10px;
    }
    .collection-shell > .section-card:first-of-type .btn-link {
      min-width: 84px;
    }
    .hr-review-toolbar,
    .logistics-review-toolbar {
      align-items: flex-end;
    }
    .hr-review-filters,
    .logistics-review-filters {
      flex: 1 1 560px;
      min-width: 0;
    }
    .hr-filter-group,
    .logistics-filter-group {
      flex: 1 1 200px;
      min-width: 0;
    }
    .hr-filter-group input,
    .hr-filter-group select,
    .logistics-filter-group input,
    .logistics-filter-group select {
      width: 100%;
    }
    .hr-review-actions,
    .logistics-review-actions {
      margin-left: auto;
      flex: 0 0 auto;
      justify-content: flex-end;
    }
    .hr-review-table .action-stack,
    .logistics-review-table .action-stack {
      display: grid;
      grid-template-columns: repeat(3, max-content);
      justify-content: flex-end;
      align-items: center;
      gap: 8px;
      white-space: nowrap;
    }
    .hr-review-table .amount-cell,
    .logistics-review-table .amount-cell {
      white-space: nowrap;
    }
    .hr-review-table th:nth-child(7),
    .hr-review-table td:nth-child(7),
    .logistics-review-table th:nth-child(7),
    .logistics-review-table td:nth-child(7) {
      width: 120px;
      white-space: nowrap;
    }
    .hr-review-table th:nth-child(8),
    .hr-review-table td:nth-child(8),
    .logistics-review-table th:nth-child(8),
    .logistics-review-table td:nth-child(8) {
      width: 170px;
      white-space: nowrap;
      text-align: right;
    }
    .hr-review-table th:nth-child(9),
    .hr-review-table td:nth-child(9),
    .logistics-review-table th:nth-child(9),
    .logistics-review-table td:nth-child(9) {
      width: 250px;
    }
    .hr-review-table .table-actions,
    .logistics-review-table .table-actions {
      min-width: 250px;
    }
    @media (max-width: 1200px) {
      .notion-table {
        font-size: 13px;
      }
      .notion-table th,
      .notion-table td {
        padding-left: 12px;
        padding-right: 12px;
      }
    }
    @media (max-width: 900px) {
      .collection-stats {
        grid-template-columns: 1fr;
      }
      .collection-shell > .panel-grid .table-card .panel-tools {
        flex-wrap: wrap;
      }
      .collection-shell > .panel-grid .table-card .panel-tools .btn {
        width: 100%;
      }
      .hr-review-actions,
      .logistics-review-actions {
        margin-left: 0;
      }
      .notion-table {
        display: block;
        overflow-x: auto;
      }
    }
    .hr-summary-grid,
    .logistics-summary-grid {
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      align-items: stretch;
    }
    .hr-summary-card,
    .logistics-summary-card {
      min-height: 92px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .hr-request-scroll,
    .logistics-request-scroll {
      margin-top: 0;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
    }
    .hr-request-scroll .notion-table th,
    .hr-request-scroll .notion-table td,
    .logistics-request-scroll .notion-table th,
    .logistics-request-scroll .notion-table td {
      padding-top: 14px;
      padding-bottom: 14px;
    }
    .hr-review-table .table-actions,
    .logistics-review-table .table-actions {
      min-width: 250px;
      vertical-align: top;
    }
    .hr-review-table .action-stack,
    .logistics-review-table .action-stack {
      align-items: start;
    }
    .hr-review-table .action-btn,
    .logistics-review-table .action-btn,
    .hr-toolbar-btn,
    .logistics-toolbar-btn {
      white-space: nowrap;
    }
    .logistics-review-table .amount-badge {
      white-space: nowrap;
    }
    .hr-detail-card,
    .logistics-detail-card {
      max-height: calc(100vh - 48px);
      overflow-y: auto;
      scrollbar-width: thin;
    }
    .hr-detail-grid > div,
    .logistics-detail-grid > div,
    .hr-detail-remarks,
    .logistics-detail-remarks {
      min-width: 0;
      word-break: break-word;
    }
    @media (max-width: 700px) {
      .hr-review-table .table-actions,
      .logistics-review-table .table-actions {
        min-width: 0;
      }
      .collection-shell > .panel-grid .table-card .table-actions {
        min-width: 0;
        gap: 8px;
      }
      .hr-review-table .action-stack,
      .logistics-review-table .action-stack {
        justify-content: stretch;
      }
      .hr-review-table .action-btn,
      .logistics-review-table .action-btn {
        width: 100%;
      }
      .collection-shell > .panel-grid .table-card .btn-link {
        min-width: 64px;
      }
    }
  </style>

  <?php
  // Security headers
  header("X-Content-Type-Options: nosniff");
  header("X-Frame-Options: DENY");
  header("X-XSS-Protection: 1; mode=block");
  header("Referrer-Policy: strict-origin-when-cross-origin");
  header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
  if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
      header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
  }
  ?>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/../inc/sidebar.php'; ?>
  <main class="content" role="main">
    <?php include __DIR__ . '/../inc/financial_topbar.php'; ?>

    <div class="page-header"><h1>Collection</h1><p>Record payment receipts, automate AR updates, and post directly to the General Ledger.</p></div>
    <a class="back-link" href="/FinancialSM/financial/index.php">&larr; Back to Financial</a>

    <?php foreach ($bootstrapMessages as $bootstrapMessage): ?>
      <section class="section-card"><div class="error-text"><?= finance_h($bootstrapMessage) ?></div></section>
    <?php endforeach; ?>
    <?php if (is_array($supabaseStatus)): ?>
      <section class="section-card">
        <div class="<?= $supabaseStatus['configured'] ? 'status-badge badge-paid' : 'error-text' ?>">
          <?= finance_h((string) $supabaseStatus['mode_label']) ?>
          <?php if (!$supabaseStatus['configured']): ?>
            <span> Configure `SUPABASE_URL` and a key in `.env` to enable cloud sync.</span>
          <?php elseif (!$supabaseStatus['connected']): ?>
            <span> Credentials are present, but the API health check is currently failing.</span>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
      <section class="section-card"><div class="status-badge badge-paid"><?= finance_h($message) ?></div></section>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <section class="section-card"><div class="error-text"><?= finance_h($error) ?></div></section>
    <?php endif; ?>

    <section class="section-card collection-shell">
      <div class="collection-header">
        <div>
          <h2 class="collection-title">Collection Workspace</h2>
          <p class="collection-subtitle">Business process flow: save collection, update AR when applicable, then post debit Cash / credit Accounts Receivable.</p>
        </div>
      </div>

      <div class="collection-stats">
        <div class="stat-block"><span class="stat-label">Collections This Month</span><strong class="stat-value"><?= finance_money($collectionsThisMonth) ?></strong></div>
        <div class="stat-block"><span class="stat-label">Search Results</span><strong class="stat-value"><?= number_format($totalRows) ?></strong></div>
        <div class="stat-block"><span class="stat-label">Pending Requests</span><strong class="stat-value"><?= number_format($total_pending_count) ?></strong></div>
        <div class="stat-block" style="background: transparent; border: none; padding: 0; display: flex; align-items: center;">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="seed_sample_requests">
                <button type="submit" class="btn subtle" style="font-size: 11px; padding: 4px 8px;">Seed Sample Data</button>
            </form>
        </div>
      </div>
      <div class="integration-stats">
        <article class="integration-card">
          <span class="stat-label">Total Approved Requests</span>
          <strong class="stat-value"><?= number_format((int) $integrationSummary['approved_total']) ?></strong>
          <span class="stat-note">Approved requests now tracked across CORE, HR, and Logistics.</span>
        </article>
        <article class="integration-card">
          <span class="stat-label">Total Disbursed Amount</span>
          <strong class="stat-value">PHP <?= finance_money((float) ($incomeSummary['total_disbursements'] ?? 0)) ?></strong>
          <span class="stat-note">Released funds visible in Disbursement and reflected in linked requests.</span>
        </article>
        <article class="integration-card">
          <span class="stat-label">Total Collections</span>
          <strong class="stat-value">PHP <?= finance_money((float) ($incomeSummary['total_collections'] ?? 0)) ?></strong>
          <span class="stat-note">Recorded collections update AR balances and cash movement.</span>
        </article>
        <article class="integration-card">
          <span class="stat-label">Budget Used vs Remaining</span>
          <strong class="stat-value">PHP <?= finance_money((float) ($budgetDashboardSummary['used_amount'] ?? 0)) ?> / PHP <?= finance_money((float) ($budgetDashboardSummary['remaining_amount'] ?? 0)) ?></strong>
          <span class="stat-note">Budget links and disbursements immediately affect available allocation.</span>
        </article>
        <article class="integration-card">
          <span class="stat-label">Pending by Module</span>
          <strong class="stat-value">C <?= number_format((int) ($integrationSummary['pending_by_module']['CORE'] ?? 0)) ?> · H <?= number_format((int) ($integrationSummary['pending_by_module']['HR'] ?? 0)) ?> · L <?= number_format((int) ($integrationSummary['pending_by_module']['LOGISTICS'] ?? 0)) ?></strong>
          <span class="stat-note">Presentation view of incoming workload by source module.</span>
        </article>
        <article class="integration-card">
          <span class="stat-label">Recorded Requests</span>
          <strong class="stat-value"><?= number_format((int) $integrationSummary['recorded_total']) ?></strong>
          <span class="stat-note">Requests with downstream ledger evidence or completed financial posting.</span>
        </article>
      </div>

    <section class="section-card core-review-shell" style="margin-top: 20px;">
        <style>
            .core-review-shell { overflow: hidden; }
            .core-summary-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: 18px 0 16px; }
            .core-summary-card { padding: 14px 16px; border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02)); transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease; }
            .core-summary-card:hover { transform: translateY(-2px); border-color: rgba(240, 175, 28, 0.35); box-shadow: 0 14px 24px rgba(0, 0, 0, 0.16); }
            .core-summary-label { display: block; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.7px; }
            .core-summary-value { display: block; margin-top: 6px; font-size: 24px; font-weight: 700; color: var(--text); }
            .core-review-toolbar { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 14px; }
            .core-filter-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; flex: 1; }
            .core-filter-form .input.subtle, .core-filter-form select { min-width: 180px; max-width: 280px; }
            .core-table-wrap { overflow-x: auto; border: 1px solid rgba(255,255,255,0.06); border-radius: 16px; }
            .core-table-wrap .notion-table tbody tr { transition: background 0.18s ease, box-shadow 0.18s ease; }
            .core-table-wrap .notion-table tbody tr:hover { background: rgba(255,255,255,0.04); }
            .core-request-emphasis { box-shadow: inset 3px 0 0 rgba(240, 175, 28, 0.78); }
            .core-request-id { font-weight: 600; color: var(--text); }
            .core-request-meta { display: block; margin-top: 4px; color: var(--muted); font-size: 11px; }
            .core-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
            .core-action-btn { border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.04); color: var(--text); border-radius: 10px; padding: 7px 10px; font-size: 12px; cursor: pointer; transition: background 0.18s ease, border-color 0.18s ease, transform 0.18s ease; }
            .core-action-btn:hover { transform: translateY(-1px); border-color: rgba(240, 175, 28, 0.35); background: rgba(255,255,255,0.08); }
            .core-action-btn.approve { color: #8fe3b0; }
            .core-action-btn.reject { color: #ff9b9b; }
            .core-action-btn.revision { color: #f4b568; }
            .core-action-btn.view { color: #8ab8ff; }
            .core-action-state { color: var(--muted); font-size: 12px; text-align: right; }
            .core-modal[hidden] { display: none; }
            .core-modal { position: fixed; inset: 0; z-index: 120; background: rgba(8, 10, 17, 0.7); backdrop-filter: blur(6px); display: flex; align-items: center; justify-content: center; padding: 20px; }
            .core-modal-card { width: min(760px, 100%); border-radius: 18px; border: 1px solid rgba(255,255,255,0.08); background: #111826; box-shadow: 0 22px 48px rgba(0, 0, 0, 0.35); padding: 20px; }
            .core-modal-head { display: flex; justify-content: space-between; gap: 12px; align-items: start; margin-bottom: 16px; }
            .core-modal-head h3 { margin: 0; }
            .core-modal-close { border: 1px solid rgba(255,255,255,0.08); background: rgba(255,255,255,0.04); color: var(--text); border-radius: 10px; padding: 8px 12px; cursor: pointer; }
            .core-detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
            .core-detail-grid div, .core-detail-block { padding: 12px 14px; border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; background: rgba(255,255,255,0.03); }
            .core-detail-grid strong, .core-detail-block strong { display: block; margin-bottom: 6px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; color: var(--muted); }
            .core-detail-history { margin: 10px 0 0; padding-left: 18px; color: var(--text); }
            .core-confirm-textarea { width: 100%; min-height: 92px; resize: vertical; margin-top: 14px; padding: 10px 12px; background: var(--input-bg); border: 1px solid var(--border); border-radius: 12px; color: var(--text); }
            .core-confirm-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; }
            @media (max-width: 900px) { .core-summary-grid, .core-detail-grid { grid-template-columns: 1fr; } .core-filter-form { width: 100%; } }
        </style>
        <div class="section-head">
            <div class="section-icon">CORE</div>
            <div class="section-title">
                <h2>Incoming Requests from CORE</h2>
                <p>Review incoming CORE financial requests with approval history and controlled actions.</p>
            </div>
            <?php if (supabase_mode() === 'mirror'): ?>
                <form method="post" class="inline-form" style="margin-left: auto;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="sync_from_supabase">
                    <button type="submit" class="btn-link success" style="font-size: 12px;">Sync from Supabase</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="core-summary-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
            <article class="core-summary-card"><span class="core-summary-label">Pending CORE Requests</span><strong class="core-summary-value"><?= number_format((int) ($coreSummary['pending'] ?? 0)) ?></strong></article>
            <article class="core-summary-card"><span class="core-summary-label">Approved Today</span><strong class="core-summary-value"><?= number_format((int) ($coreSummary['approved_today'] ?? 0)) ?></strong></article>
            <article class="core-summary-card"><span class="core-summary-label">Rejected Today</span><strong class="core-summary-value"><?= number_format((int) ($coreSummary['rejected_today'] ?? 0)) ?></strong></article>
            <article class="core-summary-card"><span class="core-summary-label">Ready for Disbursement</span><strong class="core-summary-value"><?= number_format((int) ($coreSummary['ready_for_disbursement'] ?? 0)) ?></strong></article>
            <article class="core-summary-card"><span class="core-summary-label">Linked to AR/AP</span><strong class="core-summary-value"><?= number_format((int) ($coreSummary['linked_arap'] ?? 0)) ?></strong></article>
            <article class="core-summary-card"><span class="core-summary-label">Linked to Budget</span><strong class="core-summary-value"><?= number_format((int) ($coreSummary['linked_budget'] ?? 0)) ?></strong></article>
        </div>
        <div class="core-review-toolbar">
            <form class="core-filter-form" method="get">
                <input class="input subtle" type="text" name="core_q" value="<?= finance_h($coreSearch) ?>" placeholder="Search request, requester, department">
                <select name="core_status" class="input subtle">
                    <?php foreach (['All', 'Pending', 'Approved', 'Rejected', 'Revision Requested', 'Ready for Disbursement', 'Released', 'Linked to AR/AP', 'Linked to Budget'] as $statusOption): ?>
                        <option value="<?= finance_h($statusOption) ?>" <?= $coreStatus === $statusOption ? 'selected' : '' ?>><?= finance_h($statusOption) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn subtle">Filter</button>
                <a href="collection.php" class="btn subtle">Reset</a>
            </form>
            <div class="status-badge" style="background: var(--stat-bg);"><?= number_format(count($display_core_requests)) ?> CORE request<?= count($display_core_requests) === 1 ? '' : 's' ?> shown</div>
        </div>
        <div class="core-table-wrap table-scroll-pane">
            <table class="notion-table">
                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>Request Type</th>
                        <th>Requested By</th>
                        <th>Department</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($display_core_requests)): ?>
                        <?php foreach ($display_core_requests as $request): ?>
                            <?php
                                $isExample = (bool) ($request['is_example'] ?? false);
                                $requestId = (int) ($request['id'] ?? 0);
                                $requestCode = (string) ($request['request_code'] ?? finance_core_request_code($requestId));
                                $requestTypeLabel = (string) ($request['request_type'] ?? 'Job Posting Payment');
                                $requestDescription = (string) ($request['description'] ?? ($request['job_title'] ?? 'Job posting payment'));
                                $requestCompany = (string) ($request['company_name'] ?? '');
                                $requestStatus = finance_core_status_label((string) ($request['status'] ?? 'Pending'));
                                $requestRemarks = trim((string) ($request['remarks'] ?? ''));
                                $requestRequestedBy = (string) ($request['requested_by'] ?? ($requestCompany !== '' ? $requestCompany : 'CORE Team'));
                                $requestDepartment = (string) ($request['department'] ?? 'CORE');
                                $requestDate = (string) ($request['created_at'] ?? '');
                                $historyLines = $requestId > 0 ? ($coreHistoryMap[(string) $requestId] ?? []) : [];
                                $historyPayload = !empty($historyLines) ? implode("\n", $historyLines) : 'No previous history available.';
                                $linkedArApId = (int) ($request['related_ar_ap_id'] ?? 0);
                                $linkedBudgetId = (int) ($request['related_budget_id'] ?? 0);
                                $linkedDisbursementId = (int) ($request['related_disbursement_id'] ?? 0);
                                $linkedArApRow = $linkedArApId > 0 ? getArAp($pdo, $linkedArApId) : null;
                                $linkedBudgetRow = $linkedBudgetId > 0 ? getBudgetById($pdo, $linkedBudgetId) : null;
                                $linkedDisbursementRow = $linkedDisbursementId > 0 ? getDisbursementById($pdo, $linkedDisbursementId) : null;
                                $flowSnapshot = $requestId > 0 ? ($coreFlowMap[(string) $requestId] ?? finance_request_flow_snapshot($pdo, 'CORE', (string) $requestId, $request)) : ['stage' => 'Pending', 'steps' => ['Approved' => false, 'Linked' => false, 'Disbursed' => false, 'Recorded' => false], 'badges' => [], 'linked_records' => []];
                                $linkedRecords = [];
                                if ($linkedDisbursementRow) {
                                    $linkedRecords[] = 'Disbursement #' . (string) ($linkedDisbursementRow['reference_no'] ?? $linkedDisbursementId);
                                }
                                if ($linkedArApRow) {
                                    $linkedRecords[] = (string) ($linkedArApRow['entry_type'] ?? 'AR/AP') . ' #' . (string) ($linkedArApRow['reference_no'] ?? $linkedArApId);
                                }
                                if ($linkedBudgetRow) {
                                    $linkedRecords[] = 'Budget #' . (string) ($linkedBudgetRow['budget_name'] ?? $linkedBudgetId);
                                }
                                $linkedRecords = array_values(array_unique(array_merge($linkedRecords, (array) ($flowSnapshot['linked_records'] ?? []))));
                                $linkedRecordsText = !empty($linkedRecords) ? implode(' | ', $linkedRecords) : 'No linked records.';
                                $isPendingState = $isExample || $requestStatus === 'Pending';
                                $isApprovedState = !$isExample && $requestStatus === 'Approved';
                                $rowClass = ((float) ($request['amount'] ?? 0) >= 20000) ? 'core-request-emphasis' : '';
                            ?>
                            <tr class="<?= $rowClass ?>">
                                <td><span class="core-request-id"><?= finance_h($requestCode) ?></span><span class="core-request-meta"><?= $requestDate !== '' ? finance_h(date('M d, Y', strtotime($requestDate))) : '-' ?></span></td>
                                <td><div><?= finance_h($requestTypeLabel) ?></div><span class="core-request-meta"><?= finance_h($requestDescription) ?></span></td>
                                <td><div><?= finance_h($requestRequestedBy) ?></div><?php if ($requestCompany !== '' && $requestCompany !== $requestRequestedBy): ?><span class="core-request-meta"><?= finance_h($requestCompany) ?></span><?php endif; ?></td>
                                <td><?= finance_h($requestDepartment) ?></td>
                                <td style="font-weight: 700; color: var(--gold);">PHP <?= finance_money($request['amount'] ?? 0) ?></td>
                                <td><?= $requestDate !== '' ? finance_h(date('M d, Y', strtotime($requestDate))) : '-' ?></td>
                                <td>
                                    <div class="request-status-stack">
                                        <span class="status-badge <?= collection_core_badge_class($requestStatus) ?>"><?= finance_h($requestStatus) ?></span>
                                        <span class="status-badge <?= collection_flow_stage_badge_class((string) ($flowSnapshot['stage'] ?? 'Pending')) ?>"><?= finance_h((string) ($flowSnapshot['stage'] ?? 'Pending')) ?></span>
                                        <div class="request-flow-line">
                                            <?php foreach (['Approved', 'Linked', 'Disbursed', 'Recorded'] as $index => $flowStep): ?>
                                                <span class="request-flow-step <?= !empty($flowSnapshot['steps'][$flowStep]) ? 'active' : '' ?>"><?= finance_h($flowStep) ?></span><?php if ($index < 3): ?><span class="request-flow-arrow">&rarr;</span><?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (!empty($flowSnapshot['badges'])): ?>
                                            <div class="impact-badges">
                                                <?php foreach ((array) $flowSnapshot['badges'] as $impactBadge): ?>
                                                    <span class="impact-badge"><?= finance_h((string) $impactBadge) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($linkedRecordsText !== 'No linked records.'): ?><span class="linked-record-note"><?= finance_h($linkedRecordsText) ?></span><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="core-actions">
                                        <button type="button" class="core-action-btn view" onclick="openCoreDetails(this)" data-request-id="<?= finance_h($requestCode) ?>" data-request-type="<?= finance_h($requestTypeLabel) ?>" data-requester="<?= finance_h($requestRequestedBy) ?>" data-department="<?= finance_h($requestDepartment) ?>" data-amount="<?= finance_h('PHP ' . finance_money($request['amount'] ?? 0)) ?>" data-date="<?= finance_h($requestDate !== '' ? date('M d, Y', strtotime($requestDate)) : '-') ?>" data-status="<?= finance_h($requestStatus) ?>" data-description="<?= finance_h($requestDescription) ?>" data-remarks="<?= finance_h($requestRemarks !== '' ? $requestRemarks : ($isExample ? 'Sample CORE request for demonstration.' : 'No remarks recorded.')) ?>" data-history="<?= finance_h($historyPayload) ?>" data-linked-records="<?= finance_h($linkedRecordsText) ?>">View Details</button>
                                        <?php if ($isPendingState): ?>
                                            <form class="inline-form core-action-form" method="post">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="approve_request">
                                                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                                <input type="hidden" name="request_type" value="service">
                                                <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                                <input type="hidden" name="remarks" value="">
                                                <?php if ($isExample): ?><input type="hidden" name="example_key" value="<?= finance_h((string) ($request['example_key'] ?? '')) ?>"><?php endif; ?>
                                                <button type="button" class="core-action-btn approve" onclick="openCoreActionModal(this)" data-confirm-title="Approve CORE Request" data-confirm-message="Are you sure you want to approve this request?" data-require-remarks="0" data-request-code="<?= finance_h($requestCode) ?>">Approve</button>
                                            </form>
                                            <form class="inline-form core-action-form" method="post">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="reject_request">
                                                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                                <input type="hidden" name="request_type" value="service">
                                                <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                                <input type="hidden" name="remarks" value="" class="js-core-remarks">
                                                <?php if ($isExample): ?><input type="hidden" name="example_key" value="<?= finance_h((string) ($request['example_key'] ?? '')) ?>"><?php endif; ?>
                                                <button type="button" class="core-action-btn reject" onclick="openCoreActionModal(this)" data-confirm-title="Reject CORE Request" data-confirm-message="Are you sure you want to reject this request?" data-require-remarks="1" data-request-code="<?= finance_h($requestCode) ?>">Reject</button>
                                            </form>
                                            <form class="inline-form core-action-form" method="post">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="request_revision">
                                                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                                <input type="hidden" name="request_type" value="service">
                                                <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                                <input type="hidden" name="remarks" value="" class="js-core-remarks">
                                                <?php if ($isExample): ?><input type="hidden" name="example_key" value="<?= finance_h((string) ($request['example_key'] ?? '')) ?>"><?php endif; ?>
                                                <button type="button" class="core-action-btn revision" onclick="openCoreActionModal(this)" data-confirm-title="Request CORE Revision" data-confirm-message="Are you sure you want to request a revision for this request?" data-require-remarks="1" data-request-code="<?= finance_h($requestCode) ?>">Request Revision</button>
                                            </form>
                                        <?php elseif ($isApprovedState): ?>
                                            <form class="inline-form core-action-form" method="post">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="link_core_disbursement">
                                                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                                <input type="hidden" name="remarks" value="">
                                                <button type="button" class="core-action-btn approve" onclick="openCoreActionModal(this)" data-confirm-title="Link to Disbursement" data-confirm-message="Send this approved CORE request to the disbursement queue?" data-require-remarks="0" data-request-code="<?= finance_h($requestCode) ?>">Link to Disbursement</button>
                                            </form>
                                            <form class="inline-form" method="post">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="link_core_arap">
                                                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                                <input type="hidden" name="entry_type" value="AR" class="js-core-arap-type">
                                                <input type="hidden" name="due_date" value="" class="js-core-arap-due-date">
                                                <input type="hidden" name="remarks" value="" class="js-core-arap-remarks">
                                                <button type="button" class="core-action-btn revision" onclick="openCoreArApModal(this)" data-request-id="<?= $requestId ?>" data-request-code="<?= finance_h($requestCode) ?>">Link to AR/AP</button>
                                            </form>
                                            <form class="inline-form" method="post">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="link_core_budget">
                                                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                                <input type="hidden" name="budget_id" value="0" class="js-core-budget-id">
                                                <input type="hidden" name="remarks" value="" class="js-core-budget-remarks">
                                                <button type="button" class="core-action-btn view" onclick="openCoreBudgetModal(this)" data-request-id="<?= $requestId ?>" data-request-code="<?= finance_h($requestCode) ?>" data-request-amount="<?= finance_h((string) ($request['amount'] ?? 0)) ?>">Link to Budget</button>
                                            </form>
                                        <?php else: ?>
                                            <div class="core-action-state">Action completed</div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" style="text-align: center; color: var(--muted); padding: 20px;">No CORE requests matched the current filter.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="core-details-modal" class="core-modal" hidden>
            <div class="core-modal-card">
                <div class="core-modal-head">
                    <div><h3 id="core-details-title">CORE Request Details</h3><p id="core-details-subtitle" style="margin: 4px 0 0; color: var(--muted);">Full request information for finance review.</p></div>
                    <button type="button" class="core-modal-close" onclick="closeCoreDetails()">Close</button>
                </div>
                <div class="core-detail-grid">
                    <div><strong>Request ID</strong><span id="core-detail-id">-</span></div>
                    <div><strong>Request Type</strong><span id="core-detail-type">-</span></div>
                    <div><strong>Requested By</strong><span id="core-detail-requester">-</span></div>
                    <div><strong>Department</strong><span id="core-detail-department">-</span></div>
                    <div><strong>Amount</strong><span id="core-detail-amount">-</span></div>
                    <div><strong>Date</strong><span id="core-detail-date">-</span></div>
                    <div><strong>Status</strong><span id="core-detail-status">-</span></div>
                    <div><strong>Latest Workflow</strong><span id="core-detail-history-summary">-</span></div>
                </div>
                <div class="core-detail-block"><strong>Description</strong><div id="core-detail-description">-</div></div>
                <div class="core-detail-block" style="margin-top: 12px;"><strong>Remarks</strong><div id="core-detail-remarks">-</div></div>
                <div class="core-detail-block" style="margin-top: 12px;"><strong>Linked Records</strong><div id="core-detail-linked-records">No linked records.</div></div>
                <div class="core-detail-block" style="margin-top: 12px;"><strong>History</strong><ul id="core-detail-history" class="core-detail-history"></ul></div>
            </div>
        </div>
        <div id="core-action-modal" class="core-modal" hidden>
            <div class="core-modal-card" style="width: min(560px, 100%);">
                <div class="core-modal-head">
                    <div><h3 id="core-action-title">Confirm Action</h3><p id="core-action-subtitle" style="margin: 4px 0 0; color: var(--muted);">This action will update the request status and create an audit trail.</p></div>
                    <button type="button" class="core-modal-close" onclick="closeCoreActionModal()">Close</button>
                </div>
                <div id="core-action-message" style="color: var(--text); line-height: 1.5;">Are you sure?</div>
                <textarea id="core-action-remarks" class="core-confirm-textarea" placeholder="Add remarks for this decision."></textarea>
                <div id="core-action-error" style="display:none; color:#ff9b9b; margin-top:10px;">Remarks are required for this action.</div>
                <div class="core-confirm-actions">
                    <button type="button" class="btn subtle" onclick="closeCoreActionModal()">Cancel</button>
                    <button type="button" class="btn primary" id="core-action-submit" onclick="submitCoreAction()">Confirm</button>
                </div>
            </div>
        </div>
        <div id="core-arap-modal" class="core-modal" hidden>
            <div class="core-modal-card" style="width: min(560px, 100%);">
                <div class="core-modal-head">
                    <div><h3>Link CORE Request to AR/AP</h3><p id="core-arap-subtitle" style="margin: 4px 0 0; color: var(--muted);">Create an AR or AP record linked to this CORE request.</p></div>
                    <button type="button" class="core-modal-close" onclick="closeCoreArApModal()">Close</button>
                </div>
                <div class="form-row">
                    <label for="core-arap-type">Entry Type</label>
                    <select id="core-arap-type" class="input subtle">
                        <option value="AR">Accounts Receivable</option>
                        <option value="AP">Accounts Payable</option>
                    </select>
                </div>
                <div class="form-row">
                    <label for="core-arap-due-date">Due Date</label>
                    <input id="core-arap-due-date" class="input subtle" type="date">
                </div>
                <div class="form-row">
                    <label for="core-arap-remarks">Remarks</label>
                    <textarea id="core-arap-remarks" class="core-confirm-textarea" placeholder="Optional notes for the AR/AP link."></textarea>
                </div>
                <div class="core-confirm-actions">
                    <button type="button" class="btn subtle" onclick="closeCoreArApModal()">Cancel</button>
                    <button type="button" class="btn primary" onclick="submitCoreArApLink()">Create Link</button>
                </div>
            </div>
        </div>
        <div id="core-budget-modal" class="core-modal" hidden>
            <div class="core-modal-card" style="width: min(620px, 100%);">
                <div class="core-modal-head">
                    <div><h3>Link CORE Request to Budget</h3><p id="core-budget-subtitle" style="margin: 4px 0 0; color: var(--muted);">Assign this approved CORE request to a budget.</p></div>
                    <button type="button" class="core-modal-close" onclick="closeCoreBudgetModal()">Close</button>
                </div>
                <div class="form-row">
                    <label for="core-budget-select">Budget</label>
                    <select id="core-budget-select" class="input subtle">
                        <option value="0">Select budget</option>
                        <?php foreach ($budgetRows as $budget): ?>
                            <option value="<?= (int) ($budget['id'] ?? 0) ?>" data-remaining="<?= finance_h((string) ($budget['remaining_amount'] ?? 0)) ?>">
                                <?= finance_h((string) ($budget['budget_name'] ?? 'Budget')) ?> | Remaining PHP <?= finance_money($budget['remaining_amount'] ?? 0) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="core-budget-warning" style="display:none; color:#f4b568; margin-top: 10px;">This request is over the selected budget's remaining amount.</div>
                <div class="form-row">
                    <label for="core-budget-remarks">Remarks</label>
                    <textarea id="core-budget-remarks" class="core-confirm-textarea" placeholder="Optional notes for budget linkage."></textarea>
                </div>
                <div class="core-confirm-actions">
                    <button type="button" class="btn subtle" onclick="closeCoreBudgetModal()">Cancel</button>
                    <button type="button" class="btn primary" onclick="submitCoreBudgetLink()">Link Budget</button>
                </div>
            </div>
        </div>
        <script>
            let activeCoreActionForm = null;
            let activeCoreActionRequiresRemarks = false;
            let activeCoreArApForm = null;
            let activeCoreBudgetForm = null;
            let activeCoreBudgetAmount = 0;
            function openCoreDetails(button) {
                const historyLines = (button.dataset.history || '').split('\n').filter(Boolean);
                document.getElementById('core-detail-id').textContent = button.dataset.requestId || '-';
                document.getElementById('core-detail-type').textContent = button.dataset.requestType || '-';
                document.getElementById('core-detail-requester').textContent = button.dataset.requester || '-';
                document.getElementById('core-detail-department').textContent = button.dataset.department || '-';
                document.getElementById('core-detail-amount').textContent = button.dataset.amount || '-';
                document.getElementById('core-detail-date').textContent = button.dataset.date || '-';
                document.getElementById('core-detail-status').textContent = button.dataset.status || '-';
                document.getElementById('core-detail-description').textContent = button.dataset.description || '-';
                document.getElementById('core-detail-remarks').textContent = button.dataset.remarks || '-';
                document.getElementById('core-detail-linked-records').textContent = button.dataset.linkedRecords || 'No linked records.';
                document.getElementById('core-detail-history-summary').textContent = historyLines[0] || 'No previous history available.';
                const historyList = document.getElementById('core-detail-history');
                historyList.innerHTML = '';
                if (historyLines.length === 0) {
                    const item = document.createElement('li');
                    item.textContent = 'No previous history available.';
                    historyList.appendChild(item);
                } else {
                    historyLines.forEach((line) => {
                        const item = document.createElement('li');
                        item.textContent = line;
                        historyList.appendChild(item);
                    });
                }
                document.getElementById('core-details-modal').hidden = false;
            }
            function closeCoreDetails() { document.getElementById('core-details-modal').hidden = true; }
            function openCoreActionModal(button) {
                activeCoreActionForm = button.closest('form');
                activeCoreActionRequiresRemarks = button.dataset.requireRemarks === '1';
                document.getElementById('core-action-title').textContent = button.dataset.confirmTitle || 'Confirm Action';
                document.getElementById('core-action-message').textContent = button.dataset.confirmMessage || 'Are you sure you want to continue?';
                document.getElementById('core-action-subtitle').textContent = (button.dataset.requestCode || '') !== '' ? `Request ${button.dataset.requestCode} will be updated immediately.` : 'This action will update the request status immediately.';
                document.getElementById('core-action-remarks').value = '';
                document.getElementById('core-action-remarks').placeholder = activeCoreActionRequiresRemarks ? 'Remarks are required for this action.' : 'Optional remarks for this action.';
                document.getElementById('core-action-error').style.display = 'none';
                document.getElementById('core-action-modal').hidden = false;
            }
            function closeCoreActionModal() {
                document.getElementById('core-action-modal').hidden = true;
                document.getElementById('core-action-error').style.display = 'none';
                activeCoreActionForm = null;
                activeCoreActionRequiresRemarks = false;
                document.getElementById('core-action-submit').disabled = false;
            }
            function submitCoreAction() {
                if (!activeCoreActionForm) { return; }
                const remarks = document.getElementById('core-action-remarks').value.trim();
                if (activeCoreActionRequiresRemarks && remarks === '') {
                    document.getElementById('core-action-error').style.display = 'block';
                    return;
                }
                const remarksInput = activeCoreActionForm.querySelector('.js-core-remarks') || activeCoreActionForm.querySelector('input[name=\"remarks\"]');
                if (remarksInput) { remarksInput.value = remarks; }
                document.getElementById('core-action-submit').disabled = true;
                activeCoreActionForm.submit();
            }
            function openCoreArApModal(button) {
                activeCoreArApForm = button.closest('form');
                document.getElementById('core-arap-subtitle').textContent = `Create an AR or AP record for ${button.dataset.requestCode || 'this CORE request'}.`;
                document.getElementById('core-arap-type').value = 'AR';
                document.getElementById('core-arap-due-date').value = '';
                document.getElementById('core-arap-remarks').value = '';
                document.getElementById('core-arap-modal').hidden = false;
            }
            function closeCoreArApModal() {
                document.getElementById('core-arap-modal').hidden = true;
                activeCoreArApForm = null;
            }
            function submitCoreArApLink() {
                if (!activeCoreArApForm) { return; }
                const typeInput = activeCoreArApForm.querySelector('.js-core-arap-type');
                const dueDateInput = activeCoreArApForm.querySelector('.js-core-arap-due-date');
                const remarksInput = activeCoreArApForm.querySelector('.js-core-arap-remarks');
                if (typeInput) { typeInput.value = document.getElementById('core-arap-type').value; }
                if (dueDateInput) { dueDateInput.value = document.getElementById('core-arap-due-date').value; }
                if (remarksInput) { remarksInput.value = document.getElementById('core-arap-remarks').value.trim(); }
                activeCoreArApForm.submit();
            }
            function openCoreBudgetModal(button) {
                activeCoreBudgetForm = button.closest('form');
                activeCoreBudgetAmount = Number(button.dataset.requestAmount || 0);
                document.getElementById('core-budget-subtitle').textContent = `Assign ${button.dataset.requestCode || 'this CORE request'} to a budget.`;
                document.getElementById('core-budget-select').value = '0';
                document.getElementById('core-budget-remarks').value = '';
                document.getElementById('core-budget-warning').style.display = 'none';
                document.getElementById('core-budget-modal').hidden = false;
            }
            function closeCoreBudgetModal() {
                document.getElementById('core-budget-modal').hidden = true;
                activeCoreBudgetForm = null;
                activeCoreBudgetAmount = 0;
            }
            function refreshCoreBudgetWarning() {
                const select = document.getElementById('core-budget-select');
                const option = select.options[select.selectedIndex];
                const remaining = Number(option ? option.dataset.remaining || 0 : 0);
                document.getElementById('core-budget-warning').style.display = (select.value !== '0' && activeCoreBudgetAmount > remaining) ? 'block' : 'none';
            }
            function submitCoreBudgetLink() {
                if (!activeCoreBudgetForm) { return; }
                const budgetSelect = document.getElementById('core-budget-select');
                if (budgetSelect.value === '0') { return; }
                const budgetIdInput = activeCoreBudgetForm.querySelector('.js-core-budget-id');
                const remarksInput = activeCoreBudgetForm.querySelector('.js-core-budget-remarks');
                if (budgetIdInput) { budgetIdInput.value = budgetSelect.value; }
                if (remarksInput) { remarksInput.value = document.getElementById('core-budget-remarks').value.trim(); }
                activeCoreBudgetForm.submit();
            }
            document.getElementById('core-budget-select').addEventListener('change', refreshCoreBudgetWarning);
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeCoreDetails();
                    closeCoreActionModal();
                    closeCoreArApModal();
                    closeCoreBudgetModal();
                }
            });
        </script>
    </section>

    <section class="section-card" style="margin-top: 20px;">
        <div class="section-head">
            <div class="section-icon">HR</div>
            <div class="section-title">
                <h2>Incoming Requests from HR</h2>
                <p>Review and process HR-related financial requests.</p>
            </div>
        </div>
        <style>
            .hr-review-toolbar {
                display: flex;
                justify-content: space-between;
                align-items: end;
                gap: 14px;
                flex-wrap: wrap;
                margin-bottom: 16px;
            }
            .hr-review-filters {
                display: flex;
                gap: 12px;
                flex-wrap: wrap;
            }
            .hr-filter-group {
                display: flex;
                flex-direction: column;
                gap: 6px;
                min-width: 180px;
            }
            .hr-filter-group label {
                font-size: 11px;
                color: var(--muted);
                text-transform: uppercase;
                letter-spacing: 0.06em;
            }
            .hr-filter-group input,
            .hr-filter-group select {
                background: rgba(255, 255, 255, 0.03);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 10px;
                color: var(--text);
                padding: 10px 12px;
                min-height: 42px;
            }
            .hr-review-actions {
                display: flex;
                gap: 10px;
                align-items: center;
                flex-wrap: wrap;
            }
            .hr-summary-grid {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 14px;
                margin-bottom: 18px;
            }
            .hr-summary-card {
                background: rgba(255, 255, 255, 0.03);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 14px;
                padding: 16px;
            }
            .hr-summary-card .label {
                display: block;
                font-size: 11px;
                color: var(--muted);
                text-transform: uppercase;
                letter-spacing: 0.06em;
                margin-bottom: 8px;
            }
            .hr-summary-card strong {
                display: block;
                font-size: 22px;
                line-height: 1.1;
                color: var(--text);
            }
            .hr-summary-card.amount strong {
                color: var(--gold);
            }
            .hr-request-scroll {
                max-height: 430px;
                overflow-y: auto;
                overflow-x: hidden;
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 14px;
                background: rgba(7, 11, 18, 0.55);
                scrollbar-width: thin;
                scrollbar-color: rgba(214, 176, 92, 0.55) rgba(255, 255, 255, 0.04);
            }
            .hr-request-scroll::-webkit-scrollbar {
                width: 10px;
            }
            .hr-request-scroll::-webkit-scrollbar-track {
                background: rgba(255, 255, 255, 0.04);
                border-radius: 999px;
            }
            .hr-request-scroll::-webkit-scrollbar-thumb {
                background: linear-gradient(180deg, rgba(214, 176, 92, 0.72), rgba(214, 176, 92, 0.42));
                border-radius: 999px;
                border: 2px solid rgba(7, 11, 18, 0.65);
            }
            .hr-request-scroll::-webkit-scrollbar-thumb:hover {
                background: linear-gradient(180deg, rgba(214, 176, 92, 0.82), rgba(214, 176, 92, 0.52));
            }
            .hr-request-scroll .hr-review-table {
                margin: 0;
            }
            .hr-request-scroll .hr-review-table thead th {
                position: sticky;
                top: 0;
                z-index: 2;
                background: #111827;
                box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.08);
            }
            .hr-toolbar-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 10px 14px;
                min-height: 42px;
                border-radius: 10px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                background: rgba(255, 255, 255, 0.03);
                color: var(--text);
                font-size: 12px;
                font-weight: 600;
            }
            .hr-toolbar-btn.primary {
                background: rgba(60, 113, 206, 0.25);
                border-color: rgba(94, 148, 243, 0.35);
            }
            .hr-review-table tbody tr {
                transition: background-color 0.18s ease;
            }
            .hr-review-table tbody tr:hover {
                background: rgba(255, 255, 255, 0.03);
            }
            .hr-review-table .amount-cell {
                text-align: right;
                font-weight: 600;
                color: var(--gold);
                white-space: nowrap;
            }
            .hr-review-table .meta-line {
                display: block;
                color: var(--muted);
                font-size: 12px;
                margin-top: 4px;
            }
            .hr-review-table .desc-title {
                font-weight: 600;
            }
            .hr-review-table .status-pill {
                display: inline-flex;
                align-items: center;
                padding: 5px 10px;
                border-radius: 999px;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                border: 1px solid transparent;
            }
            .hr-review-table .status-pill.pending {
                background: rgba(245, 182, 43, 0.14);
                color: #f5c451;
                border-color: rgba(245, 182, 43, 0.32);
            }
            .hr-review-table .status-pill.approved {
                background: rgba(55, 211, 154, 0.14);
                color: #37d39a;
                border-color: rgba(55, 211, 154, 0.3);
            }
            .hr-review-table .status-pill.rejected {
                background: rgba(255, 92, 92, 0.14);
                color: #ff7a7a;
                border-color: rgba(255, 92, 92, 0.3);
            }
            .hr-review-table .status-pill.revision {
                background: rgba(255, 153, 51, 0.14);
                color: #ff9d42;
                border-color: rgba(255, 153, 51, 0.3);
            }
            .hr-review-table .action-stack {
                display: flex;
                justify-content: flex-end;
                gap: 8px;
                flex-wrap: wrap;
            }
            .hr-review-table .action-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 7px 12px;
                border-radius: 10px;
                font-size: 12px;
                font-weight: 600;
                border: 1px solid rgba(255, 255, 255, 0.12);
                background: rgba(255, 255, 255, 0.02);
                color: var(--text);
            }
            .hr-review-table .action-btn.view {
                background: rgba(255, 255, 255, 0.03);
            }
            .hr-review-table .action-btn.approve {
                background: rgba(55, 211, 154, 0.14);
                color: #37d39a;
                border-color: rgba(55, 211, 154, 0.3);
            }
            .hr-review-table .action-btn.reject {
                background: rgba(255, 92, 92, 0.14);
                color: #ff7a7a;
                border-color: rgba(255, 92, 92, 0.3);
            }
            .hr-review-table .action-btn.revision {
                background: rgba(255, 153, 51, 0.14);
                color: #ffb15a;
                border-color: rgba(255, 153, 51, 0.3);
            }
            .hr-pagination {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
                margin-top: 16px;
                color: var(--muted);
                font-size: 12px;
            }
            .hr-pagination-nav {
                display: flex;
                gap: 8px;
            }
            .hr-detail-modal[hidden] {
                display: none;
            }
            .hr-detail-modal {
                position: fixed;
                inset: 0;
                background: rgba(10, 14, 25, 0.74);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                z-index: 30;
            }
            .hr-detail-card {
                width: min(640px, 100%);
                background: #171d26;
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 18px;
                padding: 22px;
                box-shadow: 0 24px 48px rgba(0, 0, 0, 0.35);
            }
            .hr-detail-head {
                display: flex;
                justify-content: space-between;
                align-items: start;
                gap: 16px;
                margin-bottom: 18px;
            }
            .hr-detail-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 14px;
                margin-bottom: 16px;
            }
            .hr-detail-grid strong,
            .hr-detail-remarks strong {
                display: block;
                font-size: 11px;
                color: var(--muted);
                text-transform: uppercase;
                letter-spacing: 0.06em;
                margin-bottom: 6px;
            }
            .hr-detail-close {
                background: transparent;
                color: var(--text);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 10px;
                padding: 8px 12px;
            }
            .hr-detail-code {
                color: var(--muted);
                margin-top: 4px;
            }
            .hr-detail-remarks {
                border-top: 1px solid rgba(255, 255, 255, 0.08);
                padding-top: 14px;
            }
            .hr-detail-history {
                margin: 14px 0 0;
                padding-left: 18px;
                color: var(--muted);
            }
            .hr-detail-history li + li {
                margin-top: 8px;
            }
            @media (max-width: 900px) {
                .hr-summary-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
                .hr-detail-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <form method="get" class="hr-review-toolbar">
            <div class="hr-review-filters">
                <div class="hr-filter-group">
                    <label for="hr_q">Search Requests</label>
                    <input id="hr_q" type="text" name="hr_q" value="<?= finance_h($hrSearch) ?>" placeholder="Search code, type, employee">
                </div>
                <div class="hr-filter-group">
                    <label for="hr_status">Status</label>
                    <select id="hr_status" name="hr_status">
                        <?php foreach (['All', 'Pending', 'Approved', 'Ready for Disbursement', 'Released', 'Rejected', 'Needs Revision'] as $statusOption): ?>
                            <option value="<?= finance_h($statusOption) ?>" <?= strcasecmp($hrStatus, $statusOption) === 0 ? 'selected' : '' ?>><?= finance_h($statusOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="hr-review-actions">
                <button type="submit" class="hr-toolbar-btn primary">Apply Filter</button>
                <a class="hr-toolbar-btn" href="collection.php">Reset</a>
            </div>
        </form>
        <div class="hr-summary-grid">
            <div class="hr-summary-card">
                <span class="label">Total Pending Requests</span>
                <strong><?= number_format((int) ($hrSummary['total_pending_requests'] ?? 0)) ?></strong>
            </div>
            <div class="hr-summary-card">
                <span class="label">Approved Today</span>
                <strong><?= number_format((int) ($hrSummary['approved_today'] ?? 0)) ?></strong>
            </div>
            <div class="hr-summary-card">
                <span class="label">Rejected Requests</span>
                <strong><?= number_format((int) ($hrSummary['rejected_requests'] ?? 0)) ?></strong>
            </div>
            <div class="hr-summary-card amount">
                <span class="label">Total Pending Amount</span>
                <strong>PHP <?= finance_money((float) ($hrSummary['total_pending_amount'] ?? 0)) ?></strong>
            </div>
        </div>
        <div class="hr-request-scroll table-scroll-pane">
        <table class="notion-table hr-review-table">
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Request Date</th>
                    <th>Request Type</th>
                    <th>Employee Name</th>
                    <th>Description / Name</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Amount</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($display_hr_requests)): ?>
                    <?php foreach ($display_hr_requests as $request): ?>
                    <?php
                        $isExample = (bool) ($request['is_example'] ?? false);
                        $requestIdLabel = (string) ($request['request_code'] ?? finance_hr_request_code((int) ($request['id'] ?? 0)));
                        $requestTypeLabel = (string) ($request['request_type_label'] ?? finance_hr_request_type_label($request));
                        $employeeName = (string) ($request['employee_name'] ?? 'HR Staff');
                        $departmentLabel = (string) ($request['department_name'] ?? 'Human Resources');
                        $statusLabel = (string) ($request['status_label'] ?? finance_hr_status_label((string) ($request['status'] ?? 'Pending')));
                        $statusClass = (string) ($request['status_badge_class'] ?? finance_hr_status_badge_class((string) ($request['status'] ?? 'Pending')));
                        $titleLabel = (string) ($request['request_title'] ?? $request['title'] ?? $request['request_details'] ?? 'HR Request');
                        $descriptionLabel = (string) ($request['request_description'] ?? $request['description'] ?? $request['request_details'] ?? '');
                        $amountLabel = (string) ($request['amount_label'] ?? finance_hr_amount_label($request));
                        $remarksValue = (string) ($request['remarks'] ?? '');
                        $requestDateRaw = (string) ($request['request_date_display'] ?? $request['request_date'] ?? $request['created_at'] ?? '');
                        $flowSnapshot = !$isExample && trim((string) ($request['id'] ?? '')) !== ''
                            ? ($hrFlowMap[(string) ($request['id'] ?? '')] ?? finance_request_flow_snapshot($pdo, 'HR', (string) ($request['id'] ?? ''), $request))
                            : ['stage' => 'Pending', 'steps' => ['Approved' => false, 'Linked' => false, 'Disbursed' => false, 'Recorded' => false], 'badges' => [], 'linked_records' => []];
                        $linkedRecordsText = !empty($flowSnapshot['linked_records']) ? implode(' | ', (array) $flowSnapshot['linked_records']) : 'No linked records.';
                        $historyLines = $isExample ? ['No previous history for this sample request yet.'] : ($hrHistoryMap[(string) ($request['id'] ?? '')] ?? []);
                        $historyLabel = !empty($historyLines) ? implode("\n", $historyLines) : 'No previous history available.';
                    ?>
                <tr>
                    <td style="font-weight: 600;"><?= finance_h($requestIdLabel) ?></td>
                    <td style="color: var(--muted); font-size: 12px;"><?= $requestDateRaw !== '' ? date('M d, Y', strtotime($requestDateRaw)) : '-' ?></td>
                    <td><?= finance_h($requestTypeLabel) ?></td>
                    <td><?= finance_h($employeeName) ?></td>
                    <td style="font-weight: 500;">
                        <span class="desc-title"><?= finance_h($titleLabel) ?></span>
                        <?php if ($descriptionLabel !== ''): ?>
                            <span class="meta-line"><?= finance_h($descriptionLabel) ?></span>
                        <?php endif; ?>
                        <?php if ($isExample && $descriptionLabel === ''): ?>
                            <span class="meta-line">Sample HR request for demonstration.</span>
                        <?php endif; ?>
                    </td>
                    <td><?= finance_h($departmentLabel) ?></td>
                    <td>
                        <div class="request-status-stack">
                            <span class="status-pill <?= finance_h($statusClass) ?>"><?= finance_h($statusLabel) ?></span>
                            <span class="status-badge <?= collection_flow_stage_badge_class((string) ($flowSnapshot['stage'] ?? 'Pending')) ?>"><?= finance_h((string) ($flowSnapshot['stage'] ?? 'Pending')) ?></span>
                            <div class="request-flow-line">
                                <?php foreach (['Approved', 'Linked', 'Disbursed', 'Recorded'] as $index => $flowStep): ?>
                                    <span class="request-flow-step <?= !empty($flowSnapshot['steps'][$flowStep]) ? 'active' : '' ?>"><?= finance_h($flowStep) ?></span><?php if ($index < 3): ?><span class="request-flow-arrow">&rarr;</span><?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!empty($flowSnapshot['badges'])): ?>
                                <div class="impact-badges">
                                    <?php foreach ((array) $flowSnapshot['badges'] as $impactBadge): ?>
                                        <span class="impact-badge"><?= finance_h((string) $impactBadge) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($linkedRecordsText !== 'No linked records.'): ?><span class="linked-record-note"><?= finance_h($linkedRecordsText) ?></span><?php endif; ?>
                        </div>
                    </td>
                    <td class="amount-cell"><?= finance_h($amountLabel) ?></td>
                    <td class="table-actions">
                        <div class="action-stack">
                            <button
                                type="button"
                                class="action-btn view"
                                aria-label="View details for <?= finance_h($titleLabel) ?>"
                                data-request-code="<?= finance_h($requestIdLabel) ?>"
                                data-request-date="<?= finance_h($requestDateRaw !== '' ? date('M d, Y', strtotime($requestDateRaw)) : '-') ?>"
                                data-request-type="<?= finance_h($requestTypeLabel) ?>"
                                data-employee-name="<?= finance_h($employeeName) ?>"
                                data-title="<?= finance_h($titleLabel) ?>"
                                data-request-id="<?= finance_h((string) ($request['id'] ?? '0')) ?>"
                                data-description="<?= finance_h($descriptionLabel !== '' ? $descriptionLabel : 'No additional description provided.') ?>"
                                data-department="<?= finance_h($departmentLabel) ?>"
                                data-status="<?= finance_h($statusLabel) ?>"
                                data-amount="<?= finance_h($amountLabel) ?>"
                                data-remarks="<?= finance_h($remarksValue !== '' ? $remarksValue : 'No remarks yet.') ?>"
                                data-history="<?= finance_h($historyLabel) ?>"
                                data-linked-records="<?= finance_h($linkedRecordsText) ?>"
                                data-submitted-by="<?= finance_h($employeeName) ?>"
                                onclick="openHrDetails(this)"
                            >View Details</button>
                            <?php if ($isExample): ?>
                                <form class="inline-form" method="post" onsubmit="return confirm('Approve this example HR request?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve_request">
                                    <input type="hidden" name="request_id" value="0">
                                    <input type="hidden" name="request_type" value="hr">
                                    <input type="hidden" name="example_key" value="<?= finance_h((string) ($request['example_key'] ?? '')) ?>">
                                    <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                    <button type="submit" class="action-btn approve" aria-label="Approve example HR request for <?= finance_h($titleLabel) ?>">Approve</button>
                                </form>
                                <form class="inline-form" method="post" onsubmit="return hrRejectPrompt(this, 'Reject this example HR request?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reject_request">
                                    <input type="hidden" name="request_id" value="0">
                                    <input type="hidden" name="request_type" value="hr">
                                    <input type="hidden" name="example_key" value="<?= finance_h((string) ($request['example_key'] ?? '')) ?>">
                                    <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                    <input type="hidden" name="remarks" value="" class="js-hr-remarks">
                                    <button type="submit" class="action-btn reject" aria-label="Reject example HR request for <?= finance_h($titleLabel) ?>">Reject</button>
                                </form>
                                <form class="inline-form" method="post" onsubmit="return hrRevisionPrompt(this, 'Return this example HR request for revision?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="request_revision">
                                    <input type="hidden" name="request_id" value="0">
                                    <input type="hidden" name="request_type" value="hr">
                                    <input type="hidden" name="example_key" value="<?= finance_h((string) ($request['example_key'] ?? '')) ?>">
                                    <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                    <input type="hidden" name="remarks" value="" class="js-hr-revision-remarks">
                                    <button type="submit" class="action-btn revision" aria-label="Return example HR request for revision for <?= finance_h($titleLabel) ?>">Request Revision</button>
                                </form>
                            <?php elseif ($statusLabel === 'Pending'): ?>
                                <form class="inline-form" method="post" onsubmit="return confirm('Approve this request?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve_request">
                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                    <input type="hidden" name="request_type" value="hr">
                                    <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                    <button type="submit" class="action-btn approve" aria-label="Approve HR request for <?= finance_h($titleLabel) ?>">Approve</button>
                                </form>
                                <form class="inline-form" method="post" onsubmit="return hrRejectPrompt(this, 'Reject this request?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reject_request">
                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                    <input type="hidden" name="request_type" value="hr">
                                    <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                    <input type="hidden" name="remarks" value="" class="js-hr-remarks">
                                    <button type="submit" class="action-btn reject" aria-label="Reject HR request for <?= finance_h($titleLabel) ?>">Reject</button>
                                </form>
                                <form class="inline-form" method="post" onsubmit="return hrRevisionPrompt(this, 'Return this request for revision?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="request_revision">
                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                    <input type="hidden" name="request_type" value="hr">
                                    <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                    <input type="hidden" name="remarks" value="" class="js-hr-revision-remarks">
                                    <button type="submit" class="action-btn revision" aria-label="Return HR request for revision for <?= finance_h($titleLabel) ?>">Request Revision</button>
                                </form>
                            <?php else: ?>
                                <span class="meta-line"><?= finance_h(!empty($flowSnapshot['linked_records']) ? implode(' | ', (array) $flowSnapshot['linked_records']) : 'This request is already in the financial flow.') ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align: center; color: var(--muted); padding: 20px;">No pending requests from HR at this time.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        <div class="hr-pagination">
            <span>Showing <?= number_format(count($display_hr_requests)) ?> request(s)</span>
            <?php if ($hrTotalPages > 1): ?>
                <div class="hr-pagination-nav">
                    <?php if ($hrPage > 1): ?>
                        <a class="hr-toolbar-btn" href="?hr_q=<?= urlencode($hrSearch) ?>&hr_status=<?= urlencode($hrStatus) ?>&hr_page=<?= $hrPage - 1 ?>">Previous</a>
                    <?php endif; ?>
                    <span>Page <?= number_format($hrPage) ?> of <?= number_format($hrTotalPages) ?></span>
                    <?php if ($hrPage < $hrTotalPages): ?>
                        <a class="hr-toolbar-btn" href="?hr_q=<?= urlencode($hrSearch) ?>&hr_status=<?= urlencode($hrStatus) ?>&hr_page=<?= $hrPage + 1 ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <span>Status filter: <?= finance_h($hrStatus) ?></span>
            <?php endif; ?>
        </div>
        <div id="hr-detail-modal" class="hr-detail-modal" hidden>
            <div class="hr-detail-card">
                <div class="hr-detail-head">
                    <div>
                        <h3 id="hr-detail-title" class="heading-reset">Request Details</h3>
                        <p id="hr-detail-code" class="hr-detail-code"></p>
                    </div>
                    <button type="button" class="hr-detail-close" onclick="closeHrDetails()">Close</button>
                </div>
                <div class="hr-detail-grid">
                    <div><strong>Request ID</strong><span id="hr-detail-id">-</span></div>
                    <div><strong>Request Date</strong><span id="hr-detail-date">-</span></div>
                    <div><strong>Request Type</strong><span id="hr-detail-type">-</span></div>
                    <div><strong>Employee Name</strong><span id="hr-detail-employee">-</span></div>
                    <div><strong>Submitted By</strong><span id="hr-detail-submitted-by">-</span></div>
                    <div><strong>Department</strong><span id="hr-detail-department">-</span></div>
                    <div><strong>Status</strong><span id="hr-detail-status">-</span></div>
                    <div><strong>Amount</strong><span id="hr-detail-amount">-</span></div>
                </div>
                <div class="hr-detail-remarks" style="margin-bottom: 14px;">
                    <strong>Description</strong>
                    <div id="hr-detail-description">-</div>
                </div>
                <div class="hr-detail-remarks">
                    <strong>Remarks</strong>
                    <div id="hr-detail-remarks">-</div>
                </div>
                <div class="hr-detail-remarks">
                    <strong>Linked Records</strong>
                    <div id="hr-detail-linked-records">No linked records.</div>
                </div>
                <div class="hr-detail-remarks">
                    <strong>Previous Remarks / History</strong>
                    <ul id="hr-detail-history" class="hr-detail-history"></ul>
                </div>
            </div>
        </div>
        <script>
            function openHrDetails(button) {
                const modal = document.getElementById('hr-detail-modal');
                document.getElementById('hr-detail-title').textContent = button.dataset.title || 'Request Details';
                document.getElementById('hr-detail-code').textContent = button.dataset.requestCode || '-';
                document.getElementById('hr-detail-id').textContent = button.dataset.requestId || '-';
                document.getElementById('hr-detail-date').textContent = button.dataset.requestDate || '-';
                document.getElementById('hr-detail-type').textContent = button.dataset.requestType || '-';
                document.getElementById('hr-detail-employee').textContent = button.dataset.employeeName || '-';
                document.getElementById('hr-detail-submitted-by').textContent = button.dataset.submittedBy || button.dataset.employeeName || '-';
                document.getElementById('hr-detail-department').textContent = button.dataset.department || '-';
                document.getElementById('hr-detail-status').textContent = button.dataset.status || '-';
                document.getElementById('hr-detail-amount').textContent = button.dataset.amount || '-';
                document.getElementById('hr-detail-description').textContent = button.dataset.description || '-';
                document.getElementById('hr-detail-remarks').textContent = button.dataset.remarks || '-';
                document.getElementById('hr-detail-linked-records').textContent = button.dataset.linkedRecords || 'No linked records.';
                const historyList = document.getElementById('hr-detail-history');
                historyList.innerHTML = '';
                const historyLines = (button.dataset.history || '').split('\n').filter(Boolean);
                if (historyLines.length === 0) {
                    const emptyItem = document.createElement('li');
                    emptyItem.textContent = 'No previous history available.';
                    historyList.appendChild(emptyItem);
                } else {
                    historyLines.forEach((line) => {
                        const item = document.createElement('li');
                        item.textContent = line;
                        historyList.appendChild(item);
                    });
                }
                modal.hidden = false;
            }

            function closeHrDetails() {
                document.getElementById('hr-detail-modal').hidden = true;
            }

            function hrRejectPrompt(form, message) {
                if (!confirm(message)) {
                    return false;
                }
                const remarks = window.prompt('Enter remarks for this decision:', '');
                if (remarks === null) {
                    return false;
                }
                const input = form.querySelector('.js-hr-remarks');
                if (input) {
                    input.value = remarks.trim();
                }
                return true;
            }

            function hrRevisionPrompt(form, message) {
                if (!confirm(message)) {
                    return false;
                }
                const remarks = window.prompt('Enter revision remarks for HR:', '');
                if (remarks === null || remarks.trim() === '') {
                    return false;
                }
                const input = form.querySelector('.js-hr-revision-remarks');
                if (input) {
                    input.value = remarks.trim();
                }
                return true;
            }
        </script>
    </section>

    <section class="section-card" style="margin-top: 20px;">
        <div class="section-head">
            <div class="section-icon">LOG</div>
            <div class="section-title">
                <h2>Incoming Requests from Logistics</h2>
                <p>Review and process logistics-related financial requests.</p>
            </div>
        </div>
        <style>
            .logistics-review-toolbar {
                display: flex;
                justify-content: space-between;
                align-items: end;
                gap: 14px;
                flex-wrap: wrap;
                margin-bottom: 16px;
            }
            .logistics-review-filters {
                display: flex;
                gap: 12px;
                flex-wrap: wrap;
            }
            .logistics-filter-group {
                display: flex;
                flex-direction: column;
                gap: 6px;
                min-width: 180px;
            }
            .logistics-filter-group label {
                font-size: 11px;
                color: var(--muted);
                text-transform: uppercase;
                letter-spacing: 0.06em;
            }
            .logistics-filter-group input,
            .logistics-filter-group select {
                background: rgba(255, 255, 255, 0.03);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 10px;
                color: var(--text);
                padding: 10px 12px;
                min-height: 42px;
            }
            .logistics-review-actions {
                display: flex;
                gap: 10px;
                align-items: center;
                flex-wrap: wrap;
            }
            .logistics-summary-grid {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 14px;
                margin-bottom: 18px;
            }
            .logistics-summary-card {
                background: rgba(255, 255, 255, 0.03);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 14px;
                padding: 16px;
            }
            .logistics-summary-card .label {
                display: block;
                font-size: 11px;
                color: var(--muted);
                text-transform: uppercase;
                letter-spacing: 0.06em;
                margin-bottom: 8px;
            }
            .logistics-summary-card strong {
                display: block;
                font-size: 22px;
                line-height: 1.1;
                color: var(--text);
            }
            .logistics-summary-card.amount strong {
                color: var(--gold);
            }
            .logistics-request-scroll {
                max-height: 430px;
                overflow-y: auto;
                overflow-x: hidden;
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 14px;
                background: rgba(7, 11, 18, 0.55);
                scrollbar-width: thin;
                scrollbar-color: rgba(214, 176, 92, 0.55) rgba(255, 255, 255, 0.04);
            }
            .logistics-request-scroll::-webkit-scrollbar {
                width: 10px;
            }
            .logistics-request-scroll::-webkit-scrollbar-track {
                background: rgba(255, 255, 255, 0.04);
                border-radius: 999px;
            }
            .logistics-request-scroll::-webkit-scrollbar-thumb {
                background: linear-gradient(180deg, rgba(214, 176, 92, 0.72), rgba(214, 176, 92, 0.42));
                border-radius: 999px;
                border: 2px solid rgba(7, 11, 18, 0.65);
            }
            .logistics-request-scroll::-webkit-scrollbar-thumb:hover {
                background: linear-gradient(180deg, rgba(214, 176, 92, 0.82), rgba(214, 176, 92, 0.52));
            }
            .logistics-request-scroll .logistics-review-table {
                margin: 0;
            }
            .logistics-request-scroll .logistics-review-table thead th {
                position: sticky;
                top: 0;
                z-index: 2;
                background: #111827;
                box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.08);
            }
            .logistics-toolbar-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 10px 14px;
                min-height: 42px;
                border-radius: 10px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                background: rgba(255, 255, 255, 0.03);
                color: var(--text);
                font-size: 12px;
                font-weight: 600;
            }
            .logistics-toolbar-btn.primary {
                background: rgba(60, 113, 206, 0.25);
                border-color: rgba(94, 148, 243, 0.35);
            }
            .logistics-review-table tbody tr {
                transition: background-color 0.18s ease;
            }
            .logistics-review-table tbody tr:hover {
                background: rgba(255, 255, 255, 0.03);
            }
            .logistics-review-table .amount-cell {
                text-align: right;
                font-weight: 600;
                color: var(--gold);
                white-space: nowrap;
            }
            .logistics-review-table .amount-cell.high {
                color: #ffd166;
            }
            .logistics-review-table .amount-badge {
                display: inline-flex;
                justify-content: center;
                align-items: center;
                padding: 6px 10px;
                border-radius: 999px;
                font-size: 11px;
                font-weight: 700;
                background: rgba(245, 182, 43, 0.14);
                color: #f5c451;
                border: 1px solid rgba(245, 182, 43, 0.32);
            }
            .logistics-review-table .meta-line {
                display: block;
                color: var(--muted);
                font-size: 12px;
                margin-top: 4px;
            }
            .logistics-review-table .desc-title {
                font-weight: 600;
            }
            .logistics-review-table .status-pill {
                display: inline-flex;
                align-items: center;
                padding: 5px 10px;
                border-radius: 999px;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                border: 1px solid transparent;
            }
            .logistics-review-table .status-pill.pending {
                background: rgba(245, 182, 43, 0.14);
                color: #f5c451;
                border-color: rgba(245, 182, 43, 0.32);
            }
            .logistics-review-table .status-pill.approved {
                background: rgba(55, 211, 154, 0.14);
                color: #37d39a;
                border-color: rgba(55, 211, 154, 0.3);
            }
            .logistics-review-table .status-pill.rejected {
                background: rgba(255, 92, 92, 0.14);
                color: #ff7a7a;
                border-color: rgba(255, 92, 92, 0.3);
            }
            .logistics-review-table .status-pill.review {
                background: rgba(255, 153, 51, 0.14);
                color: #ff9d42;
                border-color: rgba(255, 153, 51, 0.3);
            }
            .logistics-review-table .status-pill.revision {
                background: rgba(255, 153, 51, 0.14);
                color: #ff9d42;
                border-color: rgba(255, 153, 51, 0.3);
            }
            .logistics-review-table .action-stack {
                display: flex;
                justify-content: flex-end;
                gap: 8px;
                flex-wrap: wrap;
            }
            .logistics-review-table .action-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 7px 12px;
                border-radius: 10px;
                font-size: 12px;
                font-weight: 600;
                border: 1px solid rgba(255, 255, 255, 0.12);
                background: rgba(255, 255, 255, 0.02);
                color: var(--text);
            }
            .logistics-review-table .action-btn.view {
                background: rgba(255, 255, 255, 0.03);
                color: var(--text);
            }
            .logistics-review-table .action-btn.approve {
                background: rgba(55, 211, 154, 0.14);
                color: #37d39a;
                border-color: rgba(55, 211, 154, 0.3);
            }
            .logistics-review-table .action-btn.reject {
                background: rgba(255, 92, 92, 0.14);
                color: #ff7a7a;
                border-color: rgba(255, 92, 92, 0.3);
            }
            .logistics-review-table .action-btn.revision {
                background: rgba(255, 153, 51, 0.14);
                color: #ffb15a;
                border-color: rgba(255, 153, 51, 0.3);
            }
            .logistics-pagination {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
                margin-top: 16px;
                color: var(--muted);
                font-size: 12px;
            }
            .logistics-pagination-nav {
                display: flex;
                gap: 8px;
            }
            .logistics-detail-modal[hidden] {
                display: none;
            }
            .logistics-detail-modal {
                position: fixed;
                inset: 0;
                background: rgba(10, 14, 25, 0.74);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                z-index: 30;
            }
            .logistics-detail-card {
                width: min(640px, 100%);
                background: #171d26;
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 18px;
                padding: 22px;
                box-shadow: 0 24px 48px rgba(0, 0, 0, 0.35);
            }
            .logistics-detail-head {
                display: flex;
                justify-content: space-between;
                align-items: start;
                gap: 16px;
                margin-bottom: 18px;
            }
            .logistics-detail-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 14px;
                margin-bottom: 16px;
            }
            .logistics-detail-grid strong,
            .logistics-detail-remarks strong {
                display: block;
                font-size: 11px;
                color: var(--muted);
                text-transform: uppercase;
                letter-spacing: 0.06em;
                margin-bottom: 6px;
            }
            .logistics-detail-close {
                background: transparent;
                color: var(--text);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 10px;
                padding: 8px 12px;
            }
            .logistics-detail-history {
                margin: 14px 0 0;
                padding-left: 18px;
                color: var(--muted);
            }
            .logistics-detail-history li + li {
                margin-top: 8px;
            }
            @media (max-width: 900px) {
                .logistics-summary-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
                .logistics-detail-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <form method="get" class="logistics-review-toolbar">
            <div class="logistics-review-filters">
                <div class="logistics-filter-group">
                    <label for="log_q">Search Requests</label>
                    <input id="log_q" type="text" name="log_q" value="<?= finance_h($logisticsSearch) ?>" placeholder="Search code, description, type, requester">
                </div>
                <div class="logistics-filter-group">
                    <label for="log_status">Status</label>
                    <select id="log_status" name="log_status">
                        <?php foreach (['All', 'Pending', 'Approved', 'Ready for Disbursement', 'Released', 'Rejected', 'Needs Revision'] as $statusOption): ?>
                            <option value="<?= finance_h($statusOption) ?>" <?= strcasecmp($logisticsStatus, $statusOption) === 0 ? 'selected' : '' ?>><?= finance_h($statusOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="logistics-review-actions">
                <button type="submit" class="logistics-toolbar-btn primary">Apply Filter</button>
                <a class="logistics-toolbar-btn" href="collection.php">Reset</a>
            </div>
        </form>
        <div class="logistics-summary-grid">
            <div class="logistics-summary-card">
                <span class="label">Total Pending Requests</span>
                <strong><?= number_format((int) ($logisticsSummary['total_pending_requests'] ?? 0)) ?></strong>
            </div>
            <div class="logistics-summary-card">
                <span class="label">Approved Today</span>
                <strong><?= number_format((int) ($logisticsSummary['approved_today'] ?? 0)) ?></strong>
            </div>
            <div class="logistics-summary-card amount">
                <span class="label">Total Amount</span>
                <strong>PHP <?= finance_money((float) ($logisticsSummary['total_amount'] ?? 0)) ?></strong>
            </div>
            <div class="logistics-summary-card">
                <span class="label">Pending Disbursement</span>
                <strong><?= number_format((int) ($logisticsSummary['pending_disbursement'] ?? 0)) ?></strong>
            </div>
            <div class="logistics-summary-card">
                <span class="label">Released Today</span>
                <strong><?= number_format((int) ($logisticsSummary['released_today'] ?? 0)) ?></strong>
            </div>
        </div>
        <div class="logistics-request-scroll table-scroll-pane">
        <table class="notion-table logistics-review-table">
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Request Date</th>
                    <th>Request Type</th>
                    <th>Description / Name</th>
                    <th>Requested By</th>
                    <th>Department</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Amount</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($display_logistic_requests)): ?>
                    <?php foreach ($display_logistic_requests as $request): ?>
                    <?php
                        $isExample = (bool) ($request['is_example'] ?? false);
                        $requestIdLabel = (string) ($request['request_code'] ?? finance_logistics_request_code((int) ($request['id'] ?? 0)));
                        $requestTypeLabel = (string) ($request['request_type_label'] ?? finance_logistics_request_type_label($request));
                        $requestedBy = (string) ($request['requested_by_name'] ?? 'Logistics Staff');
                        $departmentLabel = (string) ($request['department_name'] ?? 'Logistics');
                        $titleLabel = (string) ($request['request_title'] ?? $request['title'] ?? $request['item_name'] ?? 'Logistics Request');
                        $descriptionLabel = (string) ($request['request_description'] ?? $request['description'] ?? $request['destination'] ?? '');
                        $amountLabel = (string) ($request['amount_label'] ?? finance_logistics_amount_label($request));
                        $remarksValue = (string) ($request['remarks'] ?? '');
                        $requestDateRaw = (string) ($request['request_date_display'] ?? $request['request_date'] ?? $request['created_at'] ?? '');
                        $dueDateRaw = (string) ($request['due_date'] ?? '');
                        $linkedRecords = !$isExample ? finance_get_logistics_linked_records($pdo, (int) ($request['id'] ?? 0)) : ['disbursement' => null, 'ap' => null];
                        $linkedDisbursement = $linkedRecords['disbursement'] ?? null;
                        $linkedAp = $linkedRecords['ap'] ?? null;
                        $displayStatusLabel = finance_logistics_display_status_label($request);
                        $displayStatusClass = finance_logistics_display_status_badge_class($request);
                        $isActionable = finance_logistics_is_actionable($request);
                        $hasFinalAmount = finance_logistics_has_final_amount($request);
                        $isApprovedState = in_array($displayStatusLabel, ['Approved', 'Ready for Disbursement'], true);
                        $hasDisbursementLink = $linkedDisbursement !== null || (int) ($request['related_disbursement_id'] ?? 0) > 0;
                        $hasApLink = $linkedAp !== null || (int) ($request['related_ar_ap_id'] ?? 0) > 0;
                        $linkedDisbursementLabel = $linkedDisbursement ? ((string) ($linkedDisbursement['reference_no'] ?? 'DIS-' . (string) ($linkedDisbursement['id'] ?? ''))) : 'No linked disbursement';
                        $linkedApLabel = $linkedAp ? ('AP #' . (string) ($linkedAp['reference_no'] ?? $linkedAp['id'])) : 'No linked payable';
                        $flowSnapshot = !$isExample && trim((string) ($request['id'] ?? '')) !== ''
                            ? ($logisticsFlowMap[(string) ($request['id'] ?? '')] ?? finance_request_flow_snapshot($pdo, 'LOGISTICS', (string) ($request['id'] ?? ''), $request))
                            : ['stage' => 'Pending', 'steps' => ['Approved' => false, 'Linked' => false, 'Disbursed' => false, 'Recorded' => false], 'badges' => [], 'linked_records' => []];
                        $linkedRecordsText = !empty($flowSnapshot['linked_records']) ? implode(' | ', (array) $flowSnapshot['linked_records']) : 'No linked records.';
                        $historyLines = $isExample ? ['No previous history for this sample request yet.'] : ($logisticsHistoryMap[(string) ($request['id'] ?? '')] ?? []);
                        $historyLabel = !empty($historyLines) ? implode("\n", $historyLines) : 'No previous history available.';
                    ?>
                <tr>
                    <td style="font-weight: 600;"><?= finance_h($requestIdLabel) ?></td>
                    <td style="color: var(--muted); font-size: 12px;"><?= $requestDateRaw !== '' ? date('M d, Y', strtotime($requestDateRaw)) : '-' ?></td>
                    <td><?= finance_h($requestTypeLabel) ?></td>
                    <td style="font-weight: 500;">
                        <span class="desc-title"><?= finance_h($titleLabel) ?></span>
                        <?php if ($descriptionLabel !== ''): ?>
                            <span class="meta-line"><?= finance_h($descriptionLabel) ?></span>
                        <?php endif; ?>
                        <?php if ($isExample && $descriptionLabel === ''): ?>
                            <span class="meta-line">Sample logistics request prepared for financial review.</span>
                        <?php endif; ?>
                    </td>
                    <td><?= finance_h($requestedBy) ?></td>
                    <td><?= finance_h($departmentLabel) ?></td>
                    <td style="color: var(--muted); font-size: 12px;"><?= $dueDateRaw !== '' ? date('M d, Y', strtotime($dueDateRaw)) : '-' ?></td>
                    <td>
                        <div class="request-status-stack">
                            <span class="status-pill <?= finance_h($displayStatusClass) ?>"><?= finance_h($displayStatusLabel) ?></span>
                            <span class="status-badge <?= collection_flow_stage_badge_class((string) ($flowSnapshot['stage'] ?? 'Pending')) ?>"><?= finance_h((string) ($flowSnapshot['stage'] ?? 'Pending')) ?></span>
                            <div class="request-flow-line">
                                <?php foreach (['Approved', 'Linked', 'Disbursed', 'Recorded'] as $index => $flowStep): ?>
                                    <span class="request-flow-step <?= !empty($flowSnapshot['steps'][$flowStep]) ? 'active' : '' ?>"><?= finance_h($flowStep) ?></span><?php if ($index < 3): ?><span class="request-flow-arrow">&rarr;</span><?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!empty($flowSnapshot['badges'])): ?>
                                <div class="impact-badges">
                                    <?php foreach ((array) $flowSnapshot['badges'] as $impactBadge): ?>
                                        <span class="impact-badge"><?= finance_h((string) $impactBadge) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($linkedRecordsText !== 'No linked records.'): ?><span class="linked-record-note"><?= finance_h($linkedRecordsText) ?></span><?php endif; ?>
                        </div>
                    </td>
                    <td class="amount-cell<?= $hasFinalAmount && (float) ($request['amount'] ?? 0) >= 100000 ? ' high' : '' ?>">
                        <?php if ($hasFinalAmount): ?>
                            <?= finance_h($amountLabel) ?>
                        <?php else: ?>
                            <span class="amount-badge">Pending Quotation</span>
                        <?php endif; ?>
                    </td>
                    <td class="table-actions">
                        <div class="action-stack">
                            <button
                                type="button"
                                class="action-btn view"
                                aria-label="View details for <?= finance_h($titleLabel) ?>"
                                data-request-code="<?= finance_h($requestIdLabel) ?>"
                                data-request-id="<?= finance_h((string) ($request['id'] ?? '0')) ?>"
                                data-request-date="<?= finance_h($requestDateRaw !== '' ? date('M d, Y', strtotime($requestDateRaw)) : '-') ?>"
                                data-request-type="<?= finance_h($requestTypeLabel) ?>"
                                data-title="<?= finance_h($titleLabel) ?>"
                                data-description="<?= finance_h($descriptionLabel !== '' ? $descriptionLabel : 'No additional description provided.') ?>"
                                data-requested-by="<?= finance_h($requestedBy) ?>"
                                data-department="<?= finance_h($departmentLabel) ?>"
                                data-due-date="<?= finance_h($dueDateRaw !== '' ? date('M d, Y', strtotime($dueDateRaw)) : '-') ?>"
                                data-status="<?= finance_h($displayStatusLabel) ?>"
                                data-amount="<?= finance_h($hasFinalAmount ? $amountLabel : 'Pending Quotation') ?>"
                                data-remarks="<?= finance_h($remarksValue !== '' ? $remarksValue : 'No remarks yet.') ?>"
                                data-history="<?= finance_h($historyLabel) ?>"
                                data-linked-disbursement="<?= finance_h($linkedDisbursementLabel) ?>"
                                data-linked-ap="<?= finance_h($linkedApLabel) ?>"
                                onclick="openLogisticsDetails(this)"
                            >View Details</button>
                            <?php if ($isActionable && $isExample): ?>
                                <form class="inline-form" method="post" onsubmit="return confirm('Approve this example logistics request?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve_request">
                                    <input type="hidden" name="request_id" value="0">
                                    <input type="hidden" name="request_type" value="logistic">
                                    <input type="hidden" name="example_key" value="<?= finance_h((string) ($request['example_key'] ?? '')) ?>">
                                    <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                    <button type="submit" class="action-btn approve" aria-label="Approve example logistics request for <?= finance_h($titleLabel) ?>">Approve</button>
                                </form>
                                <form class="inline-form" method="post" onsubmit="return logisticsRejectPrompt(this, 'Reject this example logistics request?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reject_request">
                                    <input type="hidden" name="request_id" value="0">
                                    <input type="hidden" name="request_type" value="logistic">
                                    <input type="hidden" name="example_key" value="<?= finance_h((string) ($request['example_key'] ?? '')) ?>">
                                    <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                    <input type="hidden" name="remarks" value="" class="js-logistics-remarks">
                                    <button type="submit" class="action-btn reject" aria-label="Reject example logistics request for <?= finance_h($titleLabel) ?>">Reject</button>
                                </form>
                                <form class="inline-form" method="post" onsubmit="return logisticsRevisionPrompt(this, 'Return this example logistics request for revision?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="request_revision">
                                    <input type="hidden" name="request_id" value="0">
                                    <input type="hidden" name="request_type" value="logistic">
                                    <input type="hidden" name="example_key" value="<?= finance_h((string) ($request['example_key'] ?? '')) ?>">
                                    <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                    <input type="hidden" name="remarks" value="" class="js-logistics-revision-remarks">
                                    <button type="submit" class="action-btn revision" aria-label="Request revision for example logistics request for <?= finance_h($titleLabel) ?>">Request Revision</button>
                                </form>
                            <?php elseif ($isActionable): ?>
                                <form class="inline-form" method="post" onsubmit="return confirm('Approve this request?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve_request">
                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                    <input type="hidden" name="request_type" value="logistic">
                                    <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                    <button type="submit" class="action-btn approve" aria-label="Approve logistics request for <?= finance_h($titleLabel) ?>">Approve</button>
                                </form>
                                <form class="inline-form" method="post" onsubmit="return logisticsRejectPrompt(this, 'Reject this request?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reject_request">
                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                    <input type="hidden" name="request_type" value="logistic">
                                    <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                    <input type="hidden" name="remarks" value="" class="js-logistics-remarks">
                                    <button type="submit" class="action-btn reject" aria-label="Reject logistics request for <?= finance_h($titleLabel) ?>">Reject</button>
                                </form>
                                <form class="inline-form" method="post" onsubmit="return logisticsRevisionPrompt(this, 'Return this logistics request for revision?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="request_revision">
                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                    <input type="hidden" name="request_type" value="logistic">
                                    <input type="hidden" name="request_amount" value="<?= finance_h((string) ($request['amount'] ?? '')) ?>">
                                    <input type="hidden" name="remarks" value="" class="js-logistics-revision-remarks">
                                    <button type="submit" class="action-btn revision" aria-label="Request revision for logistics request for <?= finance_h($titleLabel) ?>">Request Revision</button>
                                </form>
                            <?php elseif ($isApprovedState): ?>
                                <?php if (!$hasDisbursementLink && !$hasApLink): ?>
                                    <form class="inline-form" method="post" onsubmit="return confirm('Release funds for this logistics request? This will create a disbursement record.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="release_logistics_funds">
                                        <input type="hidden" name="request_id" value="<?= (int) ($request['id'] ?? 0) ?>">
                                        <input type="hidden" name="payment_method" value="Bank Transfer">
                                        <button type="submit" class="action-btn approve" aria-label="Release funds for logistics request <?= finance_h($titleLabel) ?>">Release Funds</button>
                                    </form>
                                    <form class="inline-form" method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="create_logistics_payable">
                                        <input type="hidden" name="request_id" value="<?= (int) ($request['id'] ?? 0) ?>">
                                        <input type="hidden" name="vendor_name" value="" class="js-logistics-payable-vendor">
                                        <input type="hidden" name="due_date" value="<?= finance_h($dueDateRaw) ?>" class="js-logistics-payable-due-date">
                                        <input type="hidden" name="remarks" value="" class="js-logistics-payable-remarks">
                                        <button type="button" class="action-btn view" onclick="openLogisticsPayableModal(this)" data-request-id="<?= (int) ($request['id'] ?? 0) ?>" data-request-code="<?= finance_h($requestIdLabel) ?>" data-vendor-name="<?= finance_h($requestedBy) ?>" data-due-date="<?= finance_h($dueDateRaw) ?>">Create Payable</button>
                                    </form>
                                <?php else: ?>
                                    <span class="meta-line"><?= finance_h($hasDisbursementLink ? 'Released through Disbursement.' : 'Tracked through Accounts Payable.') ?></span>
                                <?php endif; ?>
                            <?php elseif ($displayStatusLabel === 'Released'): ?>
                                <span class="meta-line">Funds already released.</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" style="text-align: center; color: var(--muted); padding: 20px;">No requests from Logistics found in Supabase for the selected filter.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        <div class="logistics-pagination">
            <span>Showing <?= number_format(count($display_logistic_requests)) ?> request(s)</span>
            <?php if ($logisticsTotalPages > 1): ?>
                <div class="logistics-pagination-nav">
                    <?php if ($logisticsPage > 1): ?>
                        <a class="logistics-toolbar-btn" href="?log_q=<?= urlencode($logisticsSearch) ?>&log_status=<?= urlencode($logisticsStatus) ?>&log_page=<?= $logisticsPage - 1 ?>">Previous</a>
                    <?php endif; ?>
                    <span>Page <?= number_format($logisticsPage) ?> of <?= number_format($logisticsTotalPages) ?></span>
                    <?php if ($logisticsPage < $logisticsTotalPages): ?>
                        <a class="logistics-toolbar-btn" href="?log_q=<?= urlencode($logisticsSearch) ?>&log_status=<?= urlencode($logisticsStatus) ?>&log_page=<?= $logisticsPage + 1 ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <span>Status filter: <?= finance_h($logisticsStatus) ?></span>
            <?php endif; ?>
        </div>
        <div id="logistics-detail-modal" class="logistics-detail-modal" hidden>
            <div class="logistics-detail-card">
                <div class="logistics-detail-head">
                    <div>
                        <h3 id="logistics-detail-title" class="heading-reset">Request Details</h3>
                        <p id="logistics-detail-code" style="color: var(--muted); margin-top: 4px;"></p>
                    </div>
                    <button type="button" class="logistics-detail-close" onclick="closeLogisticsDetails()">Close</button>
                </div>
                <div class="logistics-detail-grid">
                    <div><strong>Request ID</strong><span id="logistics-detail-id">-</span></div>
                    <div><strong>Request Date</strong><span id="logistics-detail-date">-</span></div>
                    <div><strong>Request Type</strong><span id="logistics-detail-type">-</span></div>
                    <div><strong>Requested By</strong><span id="logistics-detail-requested-by">-</span></div>
                    <div><strong>Department</strong><span id="logistics-detail-department">-</span></div>
                    <div><strong>Due Date</strong><span id="logistics-detail-due-date">-</span></div>
                    <div><strong>Status</strong><span id="logistics-detail-status">-</span></div>
                    <div><strong>Amount</strong><span id="logistics-detail-amount">-</span></div>
                    <div><strong>Linked Disbursement</strong><span id="logistics-detail-disbursement">-</span></div>
                    <div><strong>Linked Payable</strong><span id="logistics-detail-ap">-</span></div>
                </div>
                <div class="logistics-detail-remarks" style="margin-bottom: 14px;">
                    <strong>Description</strong>
                    <div id="logistics-detail-description">-</div>
                </div>
                <div class="logistics-detail-remarks">
                    <strong>Remarks</strong>
                    <div id="logistics-detail-remarks">-</div>
                </div>
                <div class="logistics-detail-remarks">
                    <strong>Previous Remarks / History</strong>
                    <ul id="logistics-detail-history" class="logistics-detail-history"></ul>
                </div>
            </div>
        </div>
        <div id="logistics-payable-modal" class="logistics-detail-modal" hidden>
            <div class="logistics-detail-card" style="max-width: 520px;">
                <div class="logistics-detail-head">
                    <div>
                        <h3 class="heading-reset">Create Payable from Logistics</h3>
                        <p id="logistics-payable-subtitle" style="color: var(--muted); margin-top: 4px;">Create an AP record linked to this logistics request.</p>
                    </div>
                    <button type="button" class="logistics-detail-close" onclick="closeLogisticsPayableModal()">Close</button>
                </div>
                <div class="form-row">
                    <label for="logistics-payable-vendor">Vendor / Payee</label>
                    <input id="logistics-payable-vendor" class="input subtle" type="text" placeholder="Enter vendor or payee name">
                </div>
                <div class="form-row">
                    <label for="logistics-payable-due-date">Due Date</label>
                    <input id="logistics-payable-due-date" class="input subtle" type="date">
                </div>
                <div class="form-row">
                    <label for="logistics-payable-remarks">Remarks</label>
                    <textarea id="logistics-payable-remarks" class="core-confirm-textarea" placeholder="Optional remarks for the payable link."></textarea>
                </div>
                <div class="core-confirm-actions">
                    <button type="button" class="btn subtle" onclick="closeLogisticsPayableModal()">Cancel</button>
                    <button type="button" class="btn primary" onclick="submitLogisticsPayableLink()">Create Payable</button>
                </div>
            </div>
        </div>
        <script>
            let activeLogisticsPayableForm = null;
            function openLogisticsDetails(button) {
                const modal = document.getElementById('logistics-detail-modal');
                document.getElementById('logistics-detail-title').textContent = button.dataset.title || 'Request Details';
                document.getElementById('logistics-detail-code').textContent = button.dataset.requestCode || '-';
                document.getElementById('logistics-detail-id').textContent = button.dataset.requestId || '-';
                document.getElementById('logistics-detail-date').textContent = button.dataset.requestDate || '-';
                document.getElementById('logistics-detail-type').textContent = button.dataset.requestType || '-';
                document.getElementById('logistics-detail-requested-by').textContent = button.dataset.requestedBy || '-';
                document.getElementById('logistics-detail-department').textContent = button.dataset.department || '-';
                document.getElementById('logistics-detail-due-date').textContent = button.dataset.dueDate || '-';
                document.getElementById('logistics-detail-status').textContent = button.dataset.status || '-';
                document.getElementById('logistics-detail-amount').textContent = button.dataset.amount || '-';
                document.getElementById('logistics-detail-disbursement').textContent = button.dataset.linkedDisbursement || 'No linked disbursement';
                document.getElementById('logistics-detail-ap').textContent = button.dataset.linkedAp || 'No linked payable';
                document.getElementById('logistics-detail-description').textContent = button.dataset.description || '-';
                document.getElementById('logistics-detail-remarks').textContent = button.dataset.remarks || '-';
                const historyList = document.getElementById('logistics-detail-history');
                historyList.innerHTML = '';
                const historyLines = (button.dataset.history || '').split('\n').filter(Boolean);
                if (historyLines.length === 0) {
                    const emptyItem = document.createElement('li');
                    emptyItem.textContent = 'No previous history available.';
                    historyList.appendChild(emptyItem);
                } else {
                    historyLines.forEach((line) => {
                        const item = document.createElement('li');
                        item.textContent = line;
                        historyList.appendChild(item);
                    });
                }
                modal.hidden = false;
            }

            function closeLogisticsDetails() {
                document.getElementById('logistics-detail-modal').hidden = true;
            }

            function openLogisticsPayableModal(button) {
                activeLogisticsPayableForm = button.closest('form');
                document.getElementById('logistics-payable-subtitle').textContent = 'Create an AP entry linked to ' + (button.dataset.requestCode || 'this logistics request') + '.';
                document.getElementById('logistics-payable-vendor').value = button.dataset.vendorName || '';
                document.getElementById('logistics-payable-due-date').value = button.dataset.dueDate || '';
                document.getElementById('logistics-payable-remarks').value = '';
                document.getElementById('logistics-payable-modal').hidden = false;
            }

            function closeLogisticsPayableModal() {
                document.getElementById('logistics-payable-modal').hidden = true;
                activeLogisticsPayableForm = null;
            }

            function submitLogisticsPayableLink() {
                if (!activeLogisticsPayableForm) {
                    return;
                }
                const vendorName = document.getElementById('logistics-payable-vendor').value.trim();
                if (vendorName === '') {
                    window.alert('Vendor / payee is required.');
                    return;
                }
                const vendorInput = activeLogisticsPayableForm.querySelector('.js-logistics-payable-vendor');
                const dueDateInput = activeLogisticsPayableForm.querySelector('.js-logistics-payable-due-date');
                const remarksInput = activeLogisticsPayableForm.querySelector('.js-logistics-payable-remarks');
                if (vendorInput) {
                    vendorInput.value = vendorName;
                }
                if (dueDateInput) {
                    dueDateInput.value = document.getElementById('logistics-payable-due-date').value;
                }
                if (remarksInput) {
                    remarksInput.value = document.getElementById('logistics-payable-remarks').value.trim();
                }
                closeLogisticsPayableModal();
                activeLogisticsPayableForm.submit();
            }

            function logisticsRejectPrompt(form, message) {
                if (!confirm(message)) {
                    return false;
                }
                const remarks = window.prompt('Enter remarks for this decision:', '');
                if (remarks === null) {
                    return false;
                }
                const input = form.querySelector('.js-logistics-remarks');
                if (input) {
                    input.value = remarks.trim();
                }
                return true;
            }

            function logisticsRevisionPrompt(form, message) {
                if (!confirm(message)) {
                    return false;
                }
                const remarks = window.prompt('Enter revision remarks for Logistics:', '');
                if (remarks === null || remarks.trim() === '') {
                    return false;
                }
                const input = form.querySelector('.js-logistics-revision-remarks');
                if (input) {
                    input.value = remarks.trim();
                }
                return true;
            }
        </script>
    </section>

    <section class="section-card" style="margin-top: 20px;">
        <div class="section-head">
            <div class="section-icon">LOG</div>
            <div class="section-title">
                <h2>Request Action Logs</h2>
                <p>History of approved and rejected financial requests now lives in a dedicated page.</p>
            </div>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-start; justify-content: space-between; padding: 18px 0 4px;">
            <div style="max-width: 680px;">
                <div class="status-badge" style="background: var(--stat-bg); margin-bottom: 12px;">
                    <?= number_format($requestLogTotalRows) ?> total log entr<?= $requestLogTotalRows === 1 ? 'y' : 'ies' ?>
                </div>
                <?php if (!empty($approved_requests)): ?>
                    <div style="display: grid; gap: 10px;">
                        <?php foreach ($approved_requests as $request): ?>
                            <div style="padding: 12px 14px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.08); background: rgba(255,255,255,0.02);">
                                <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 6px;">
                                    <span class="status-badge" style="background: var(--stat-bg);"><?= finance_h((string) ($request['module'] ?? '-')) ?></span>
                                    <span class="status-badge <?= (($request['action'] ?? 'APPROVE') === 'APPROVE') ? 'badge-paid' : 'badge-overdue' ?>"><?= finance_h((string) ($request['action'] ?? 'APPROVE')) ?></span>
                                    <span style="color: var(--muted); font-size: 12px;"><?= date('M d, Y g:i A', strtotime((string) ($request['approved_at'] ?? 'now'))) ?></span>
                                </div>
                                <div style="font-weight: 600; margin-bottom: 4px;"><?= finance_h((string) ($request['description'] ?? '-')) ?></div>
                                <div style="color: var(--muted); font-size: 12px;">
                                    <?= trim((string) ($request['remarks'] ?? '')) !== '' ? finance_h((string) $request['remarks']) : 'No remarks recorded.' ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="color: var(--muted);">No request actions found in history yet.</div>
                <?php endif; ?>
            </div>
            <div style="display: grid; gap: 12px; min-width: 220px;">
                <a class="btn subtle" href="/FinancialSM/financial/request-action-logs.php">View Request Logs</a>
                <a class="btn subtle" href="/FinancialSM/financial/export_request_action_log_pdf.php" target="_blank" rel="noopener">Export to PDF</a>
            </div>
        </div>
    </section>

      <div class="panel-grid">
        <form class="form-card" method="post">
          <h3 class="form-title"><?= $editRow ? 'Edit Collection' : 'Add Collection' ?></h3>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_collection">
          <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">

          <div class="form-row"><label for="reference_no">Reference No</label><input id="reference_no" name="reference_no" type="text" value="<?= finance_h((string) ($editRow['reference_no'] ?? '')) ?>" placeholder="Auto-generated when blank"></div>
          <div class="form-row"><label for="payer_name">Payer Name</label><input id="payer_name" name="payer_name" type="text" required value="<?= finance_h((string) ($editRow['payer_name'] ?? '')) ?>"></div>
          <div class="form-row split">
            <div>
              <label for="source_type">Source Type</label>
              <select id="source_type" name="source_type">
                <?php foreach (['MANUAL', 'AR'] as $sourceType): ?>
                  <option value="<?= finance_h($sourceType) ?>" <?= (strtoupper((string) ($editRow['source_type'] ?? 'MANUAL')) === $sourceType) ? 'selected' : '' ?>><?= finance_h($sourceType) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div><label for="source_id">Source ID</label><input id="source_id" name="source_id" type="number" min="0" step="1" value="<?= finance_h((string) ($editRow['source_id'] ?? '0')) ?>"></div>
          </div>
          <div class="form-row split">
            <div><label for="amount">Amount</label><input id="amount" name="amount" type="number" min="0.01" step="0.01" required value="<?= finance_h((string) ($editRow['amount'] ?? '')) ?>"></div>
            <div><label for="payment_method">Payment Method</label><select id="payment_method" name="payment_method"><?php foreach (['Cash','Bank Transfer','Check','Online'] as $method): ?><option value="<?= finance_h($method) ?>" <?= (($editRow['payment_method'] ?? '') === $method) ? 'selected' : '' ?>><?= finance_h($method) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="form-row split">
            <div><label for="payment_date">Payment Date</label><input id="payment_date" name="payment_date" type="date" required value="<?= finance_h((string) ($editRow['payment_date'] ?? date('Y-m-d'))) ?>"></div>
            <div>
              <label for="related_budget_id">Fund Budget (Optional)</label>
              <select id="related_budget_id" name="related_budget_id">
                <option value="0">-- No Budget --</option>
                <?php foreach ($budgetRows as $budget): ?>
                  <option value="<?= (int)$budget['id'] ?>" <?= ((int)($editRow['related_budget_id'] ?? 0) === (int)$budget['id']) ? 'selected' : '' ?>>
                    <?= finance_h($budget['budget_name']) ?> (<?= finance_h($budget['department']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-row">
            <label for="status">Status</label>
            <select id="status" name="status">
              <?php foreach (['Posted','Pending','Cancelled'] as $statusOption): ?>
                <option value="<?= finance_h($statusOption) ?>" <?= (($editRow['status'] ?? 'Posted') === $statusOption) ? 'selected' : '' ?>><?= finance_h($statusOption) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-row split">
            <div><label for="debit_account_title">Debit Account</label><select id="debit_account_title" name="debit_account_title"><?php foreach ($accounts as $account): ?><option value="<?= finance_h($account['account_title']) ?>" <?= (($editRow['debit_account_title'] ?? 'Cash') === $account['account_title']) ? 'selected' : '' ?>><?= finance_h(trim((string) ($account['account_code'] ?? '')) . ' - ' . $account['account_title']) ?></option><?php endforeach; ?></select></div>
            <div><label for="credit_account_title">Credit Account</label><select id="credit_account_title" name="credit_account_title"><?php foreach ($accounts as $account): ?><option value="<?= finance_h($account['account_title']) ?>" <?= (($editRow['credit_account_title'] ?? 'Accounts Receivable') === $account['account_title']) ? 'selected' : '' ?>><?= finance_h(trim((string) ($account['account_code'] ?? '')) . ' - ' . $account['account_title']) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="form-row"><label for="remarks">Remarks</label><textarea id="remarks" name="remarks" rows="3"><?= finance_h((string) ($editRow['remarks'] ?? '')) ?></textarea></div>
          <div class="form-actions"><?php if ($editRow): ?><a class="btn subtle" href="collection.php">Cancel</a><?php endif; ?><button class="btn primary" type="submit"><?= $editRow ? 'Update Collection' : 'Record Collection' ?></button></div>
        </form>

        <div class="table-card">
          <div class="table-title">Collection Register</div>
          <form class="panel-tools wrap-tools" method="get" action="">
            <input class="input subtle" type="text" name="q" value="<?= finance_h($search) ?>" placeholder="Search reference, payer, method, status">
            <button class="btn subtle" type="submit">Search</button>
          </form>
          <div class="table-wrap table-scroll-pane"><table class="notion-table"><thead><tr><th>Reference</th><th>Payer</th><th>Amount</th><th>Budget Link</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php if (!$rows): ?><tr><td colspan="7" class="muted-cell">No collection records found.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?>
              <?php 
                $linkedBudget = null;
                if ((int)($row['related_budget_id'] ?? 0) > 0) {
                  foreach ($budgetRows as $b) {
                    if ((int)$b['id'] === (int)$row['related_budget_id']) {
                      $linkedBudget = $b;
                      break;
                    }
                  }
                }
              ?>
              <tr>
                <td><?= finance_h((string) ($row['reference_no'] ?? '-')) ?></td>
                <td><?= finance_h((string) ($row['payer_name'] ?? '-')) ?></td>
                <td><?= finance_money($row['amount'] ?? 0) ?></td>
                <td><?= $linkedBudget ? finance_h($linkedBudget['budget_name']) : '<span class="muted-text">None</span>' ?></td>
                <td><?= finance_h((string) ($row['payment_date'] ?? '-')) ?></td>
                <td><span class="status-badge <?= collection_badge_class((string) ($row['status'] ?? '')) ?>"><?= finance_h((string) ($row['status'] ?? '-')) ?></span></td>
                <td class="table-actions">
                  <a class="btn-link" href="collection.php?edit=<?= (int) $row['id'] ?>">Edit</a>
                  <form class="inline-form" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_collection">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button class="btn-link danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>

          <?php if ($totalPages > 1): ?>
            <div class="form-actions" style="justify-content: space-between; margin-top: 12px;">
              <div class="muted-cell">Page <?= $page ?> of <?= $totalPages ?></div>
              <div style="display:flex; gap:8px;">
                <?php if ($page > 1): ?><a class="btn subtle" href="?q=<?= urlencode($search) ?>&page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
                <?php if ($page < $totalPages): ?><a class="btn subtle" href="?q=<?= urlencode($search) ?>&page=<?= $page + 1 ?>">Next</a><?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </main>
</div>
</body>
</html>
