<?php
declare(strict_types=1);

require __DIR__ . '/../config/db.php';

$tables = [
    'collection',
    'disbursement',
    'ar_ap',
    'general_ledger',
    'budget_management',
    'accounts',
];

$stmt = $pdo->prepare("
    SELECT
        table_name,
        column_name,
        data_type,
        is_nullable
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name = :table_name
    ORDER BY ordinal_position
");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Finance Schema Inspector</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; background: #f8fafc; color: #0f172a; }
        .card { background: #fff; border: 1px solid #dbe3ee; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        th { background: #f1f5f9; }
        code { background: #e2e8f0; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>Finance Schema Inspector</h1>
    <p>Use this page to compare the real Supabase columns against the PHP helper assumptions.</p>

    <?php foreach ($tables as $table): ?>
        <section class="card">
            <h2><code><?= htmlspecialchars($table, ENT_QUOTES, 'UTF-8') ?></code></h2>
            <?php
            $stmt->execute([':table_name' => $table]);
            $rows = $stmt->fetchAll();
            ?>
            <table>
                <thead>
                    <tr>
                        <th>Column</th>
                        <th>Type</th>
                        <th>Nullable</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="3">Table not found or has no columns.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['column_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['data_type'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['is_nullable'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endforeach; ?>
</body>
</html>
