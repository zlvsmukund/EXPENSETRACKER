<?php
session_start();

require __DIR__ . '/config.php';

function normalizeMonth(string $value): string
{
    $date = DateTime::createFromFormat('Y-m', $value);

    return ($date && $date->format('Y-m') === $value) ? $value : date('Y-m');
}

function redirectToMonth(string $month): void
{
    header('Location: index.php?month=' . urlencode($month));
    exit;
}

function joinCategoryList(array $categories): string
{
    $count = count($categories);

    if ($count === 0) {
        return '';
    }

    if ($count === 1) {
        return (string)$categories[0];
    }

    if ($count === 2) {
        return $categories[0] . ' and ' . $categories[1];
    }

    $lastCategory = array_pop($categories);

    return implode(', ', $categories) . ', and ' . $lastCategory;
}

$errors = [];
$successMessage = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if ($flashError !== '') {
    $errors[] = $flashError;
}

$connectionError = '';
$selectedMonth = normalizeMonth($_GET['month'] ?? date('Y-m'));
$monthStart = (new DateTimeImmutable($selectedMonth . '-01'))->format('Y-m-01');
$monthEnd = (new DateTimeImmutable($selectedMonth . '-01'))->modify('last day of this month')->format('Y-m-d');

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS expenses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            category VARCHAR(100) NOT NULL,
            description TEXT NULL,
            expense_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columnCheck = $pdo->prepare("SHOW COLUMNS FROM expenses LIKE 'description'");
    $columnCheck->execute();
    if (!$columnCheck->fetch()) {
        $pdo->exec('ALTER TABLE expenses ADD COLUMN description TEXT NULL AFTER category');
    }
} catch (Throwable $e) {
    http_response_code(500);
    $connectionError = 'Database connection failed. Check your MySQL settings in config.php.';
}

$categoryOptions = ['Food', 'Transport', 'Bills', 'Groceries', 'Entertainment', 'Healthcare', 'Education', 'Other'];
$currentDate = date('Y-m-d');

$userId = (int)($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['user_name'] ?? '';
if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if ($connectionError === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $returnMonth = normalizeMonth($_POST['return_month'] ?? $selectedMonth);

    if ($action === 'delete') {
        $expenseId = (int)($_POST['expense_id'] ?? 0);

        if ($expenseId <= 0) {
            $errors[] = 'Choose a valid expense to delete.';
        }

        if (!$errors) {
            try {
                $statement = $pdo->prepare('DELETE FROM expenses WHERE id = :id AND user_id = :user_id');
                $statement->execute([':id' => $expenseId, ':user_id' => $userId]);

                $_SESSION['flash_success'] = 'Expense deleted successfully.';
                redirectToMonth($returnMonth);
            } catch (Throwable $e) {
                $errors[] = 'Could not delete the expense. Check the database connection and table setup.';
            }
        }
    } else {
        $expenseId = (int)($_POST['expense_id'] ?? 0);
        $amount = trim($_POST['amount'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if($description !=='' && !preg_match("/^[A-Za-z\s]+$/", $description)){
            $errors[] = 'Should contain Alphabets';
        }
        $expenseDate = trim($_POST['expense_date'] ?? '');

        if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
            $errors[] = 'Enter a valid amount greater than zero.';
        }

        if ($category === '') {
            $errors[] = 'Enter a category.';
        }

        $dateObject = DateTime::createFromFormat('Y-m-d', $expenseDate);
        $dateIsValid = $dateObject && $dateObject->format('Y-m-d') === $expenseDate;

        if (!$dateIsValid) {
            $errors[] = 'Choose a valid date.';
        }

        // Disallow future dates (only allow past and present)
        if ($dateIsValid) {
            if ($expenseDate > date('Y-m-d')) {
                $errors[] = 'Choose a date not in the future.';
                $dateIsValid = false;
            }
        }

        if ($action === 'update' && $expenseId <= 0) {
            $errors[] = 'Choose a valid expense to update.';
        }

        if (!$errors) {
            try {
                if ($action === 'update') {
                    $statement = $pdo->prepare(
                        'UPDATE expenses
                         SET amount = :amount, category = :category, description = :description, expense_date = :expense_date
                         WHERE id = :id AND user_id = :user_id'
                    );
                    $statement->execute([
                        ':amount' => number_format((float)$amount, 2, '.', ''),
                        ':category' => $category,
                        ':description' => $description !== '' ? $description : null,
                        ':expense_date' => $expenseDate,
                        ':id' => $expenseId,
                        ':user_id' => $userId,
                    ]);

                    $_SESSION['flash_success'] = 'Expense updated successfully.';
                } else {
                    $statement = $pdo->prepare('INSERT INTO expenses (user_id, amount, category, description, expense_date) VALUES (:user_id, :amount, :category, :description, :expense_date)');
                    $statement->execute([
                        ':user_id' => $userId,
                        ':amount' => number_format((float)$amount, 2, '.', ''),
                        ':category' => $category,
                        ':description' => $description !== '' ? $description : null,
                        ':expense_date' => $expenseDate,
                    ]);

                    $_SESSION['flash_success'] = 'Expense saved successfully.';
                }

                redirectToMonth($returnMonth);
            } catch (Throwable $e) {
                $errors[] = 'Could not save the expense. Check the database connection and table setup.';
            }
        }
    }
}

$expenses = [];
$summaryRows = [];
$totalSpent = 0.0;
$expenseCount = 0;
$chartMax = 0.0;
$topCategoryLabels = [];

if ($connectionError === '') {
    $statement = $pdo->prepare(
                'SELECT id, amount, category, description, expense_date, created_at
         FROM expenses
         WHERE expense_date BETWEEN :month_start AND :month_end
           AND user_id = :user_id
         ORDER BY expense_date DESC, id DESC'
    );
    $statement->execute([
        ':month_start' => $monthStart,
        ':month_end' => $monthEnd,
        ':user_id' => $userId,
    ]);
    $expenses = $statement->fetchAll();

    $statement = $pdo->prepare(
        'SELECT category, SUM(amount) AS total_amount, COUNT(*) AS expense_count
         FROM expenses
         WHERE expense_date BETWEEN :month_start AND :month_end
           AND user_id = :user_id
         GROUP BY category
         ORDER BY total_amount DESC, category ASC'
    );
    $statement->execute([
        ':month_start' => $monthStart,
        ':month_end' => $monthEnd,
        ':user_id' => $userId,
    ]);
    $summaryRows = $statement->fetchAll();

    $statement = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0), COUNT(*)
         FROM expenses
         WHERE expense_date BETWEEN :month_start AND :month_end
           AND user_id = :user_id'
    );
    $statement->execute([
        ':month_start' => $monthStart,
        ':month_end' => $monthEnd,
        ':user_id' => $userId,
    ]);
    $summaryTotals = $statement->fetch(PDO::FETCH_NUM);
    $totalSpent = (float)($summaryTotals[0] ?? 0);
    $expenseCount = (int)($summaryTotals[1] ?? 0);

    foreach ($summaryRows as $row) {
        $chartMax = max($chartMax, (float)$row['total_amount']);
    }

    if ($summaryRows) {
        $topTotal = (float)$summaryRows[0]['total_amount'];

        foreach ($summaryRows as $row) {
            if ((float)$row['total_amount'] !== $topTotal) {
                break;
            }

            $topCategoryLabels[] = $row['category'];
        }
    }
}

$formValues = [
    'amount' => $_POST['amount'] ?? '',
    'category' => $_POST['category'] ?? '',
    'description' => $_POST['description'] ?? '',
    'expense_date' => $_POST['expense_date'] ?? $currentDate,
];

$visibleMonthLabel = DateTime::createFromFormat('Y-m', $selectedMonth)->format('F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Tracker</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page-shell">
        <header class="hero">
            <div>
                <p class="eyebrow">Personal Finance Tracker</p>
                <h1>Log expenses and see where your money goes.</h1>
                <p class="hero-copy">Add daily spending, group it by category, filter by month, and review live summary totals.</p>
            </div>
            <div class="hero-right">
                <div class="user-card">
                    <div class="account-info" id="account-toggle" role="button" tabindex="0" aria-expanded="false">
                        Signed in as <?php echo htmlspecialchars($userName); ?>
                        <div class="account-dropdown" id="account-dropdown" aria-hidden="true">
                            <a href="account.php">Account settings</a>
                            <a href="logout.php">Sign out</a>
                        </div>
                    </div>
                </div>

                <div class="hero-card">
                    <span>Total spent this month</span>
                    <strong><?php echo htmlspecialchars(number_format($totalSpent, 2)); ?></strong>
                    <small><?php echo htmlspecialchars($visibleMonthLabel); ?> | <?php echo (int)$expenseCount; ?> expense<?php echo $expenseCount === 1 ? '' : 's'; ?></small>
                </div>
            </div>
        </header>

        <?php if ($connectionError !== ''): ?>
            <section class="alert error"><?php echo htmlspecialchars($connectionError); ?></section>
        <?php endif; ?>

        <?php if ($successMessage): ?>
            <section class="alert success"><?php echo htmlspecialchars($successMessage); ?></section>
        <?php endif; ?>

        <?php if ($errors): ?>
            <section class="alert error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <main class="stack-layout">
            <section class="panel form-panel">
                <div class="panel-heading">
                    <h2>Add Expense</h2>
                    <p>Record a new expense with amount, category, optional description, and date.</p>
                </div>

                <form method="post" action="" class="expense-form">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="return_month" value="<?php echo htmlspecialchars($selectedMonth); ?>">

                    <label>
                        <span>Amount</span>
                        <input type="number" name="amount" step="0.01" min="0.01" placeholder="0.00" value="<?php echo htmlspecialchars($formValues['amount']); ?>" required>
                    </label>

                    <label>
                        <span>Category</span>
                        <input list="category-list" name="category" placeholder="Food" value="<?php echo htmlspecialchars($formValues['category']); ?>" required>
                        <datalist id="category-list">
                            <?php foreach ($categoryOptions as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </label>

                    <label>
                        <span>Description (optional)</span>
                        <textarea name="description" rows="3" placeholder="Add a short note about this expense"><?php echo htmlspecialchars($formValues['description']); ?></textarea>
                    </label>

                    <label>
                        <span>Date</span>
                        <input type="date" name="expense_date" value="<?php echo htmlspecialchars($formValues['expense_date']); ?>" max="<?php echo htmlspecialchars($currentDate); ?>" required>
                    </label>

                    <button type="submit">Save Expense</button>
                </form>
            </section>

            <section class="panel controls-panel">
                <div class="panel-heading">
                    <h2>Month Filter</h2>
                    <p>Choose a month to view matching expenses and totals.</p>
                </div>
                <form method="get" action="" class="filter-form">
                    <label>
                        <span>Filter by month</span>
                        <input type="month" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                    </label>
                    <button type="submit">Apply Filter</button>
                </form>
                <p class="controls-note">Showing expenses for <?php echo htmlspecialchars($visibleMonthLabel); ?>.</p>
            </section>

            <section class="panel summary-panel">
                <div class="panel-heading">
                    <h2>Summary by Category</h2>
                    <p>Totals for <?php echo htmlspecialchars($visibleMonthLabel); ?> update automatically from your saved expenses.</p>
                </div>

                <?php if ($summaryRows): ?>
                    <div class="summary-list">
                        <?php foreach ($summaryRows as $row): ?>
                            <article class="summary-item">
                                <div>
                                    <h3><?php echo htmlspecialchars($row['category']); ?></h3>
                                    <p><?php echo (int)$row['expense_count']; ?> expense<?php echo ((int)$row['expense_count'] === 1) ? '' : 's'; ?></p>
                                </div>
                                <strong><?php echo htmlspecialchars(number_format((float)$row['total_amount'], 2)); ?></strong>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="chart-block">
                        <?php foreach ($summaryRows as $row): ?>
                            <?php
                                $rowTotal = (float)$row['total_amount'];
                                $barWidth = $chartMax > 0 ? max(8, ($rowTotal / $chartMax) * 100) : 0;
                            ?>
                            <div class="chart-row">
                                <div class="chart-labels">
                                    <span><?php echo htmlspecialchars($row['category']); ?></span>
                                    <strong><?php echo htmlspecialchars(number_format($rowTotal, 2)); ?></strong>
                                </div>
                                <div class="chart-track" aria-hidden="true">
                                    <span class="chart-bar" style="width: <?php echo htmlspecialchars(number_format($barWidth, 2)); ?>%;"></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="empty-state">No expenses recorded yet.</p>
                <?php endif; ?>
            </section>
        </main>

        <section class="panel snapshot-panel">
            <div class="panel-heading">
                <h2>Monthly Snapshot</h2>
                <p>A quick look at how spending is distributed this month.</p>
            </div>

            <?php if ($summaryRows): ?>
                <div class="snapshot-grid">
                    <article class="snapshot-card">
                        <span>Expenses</span>
                        <strong><?php echo (int)$expenseCount; ?></strong>
                    </article>
                    <article class="snapshot-card">
                        <span>Total spent</span>
                        <strong><?php echo htmlspecialchars(number_format($totalSpent, 2)); ?></strong>
                    </article>
                    <article class="snapshot-card">
                        <span>Top category</span>
                        <strong><?php echo htmlspecialchars(joinCategoryList($topCategoryLabels)); ?></strong>
                    </article>
                </div>
            <?php else: ?>
                <p class="empty-state">No spending data available for this month.</p>
            <?php endif; ?>
        </section>

        <section class="panel table-panel">
            <div class="panel-heading">
                <h2>Recent Expenses</h2>
                <p>Track every entry for <?php echo htmlspecialchars($visibleMonthLabel); ?> in date order.</p>
            </div>

            <?php if ($expenses): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Added</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenses as $expense): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('M j, Y', strtotime($expense['expense_date']))); ?></td>
                                    <td><?php echo htmlspecialchars($expense['category']); ?></td>
                                    <td><?php echo htmlspecialchars($expense['description'] ?: '—'); ?></td>
                                    <td><?php echo htmlspecialchars(number_format((float)$expense['amount'], 2)); ?></td>
                                    <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($expense['created_at']))); ?></td>
                                    <td>
                                        <div class="row-actions">
                                            <button
                                                type="button"
                                                class="action-link edit-button"
                                                data-edit-expense="1"
                                                data-expense-id="<?php echo (int)$expense['id']; ?>"
                                                data-amount="<?php echo htmlspecialchars(number_format((float)$expense['amount'], 2, '.', '')); ?>"
                                                data-category="<?php echo htmlspecialchars($expense['category']); ?>"
                                                data-description="<?php echo htmlspecialchars($expense['description'] ?? '', ENT_QUOTES); ?>"
                                                data-date="<?php echo htmlspecialchars($expense['expense_date']); ?>"
                                                data-return-month="<?php echo htmlspecialchars($selectedMonth); ?>"
                                            >Edit</button>
                                            <form method="post" action="" class="inline-form" onsubmit="return confirm('Delete this expense?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="expense_id" value="<?php echo (int)$expense['id']; ?>">
                                                <input type="hidden" name="return_month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                                                <button type="submit" class="danger-button">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-state">Add your first expense to see it here.</p>
            <?php endif; ?>
        </section>
    </div>

    <div class="edit-modal" id="edit-modal" aria-hidden="true">
        <div class="edit-modal__backdrop" data-close-edit-modal></div>
        <div class="edit-modal__panel" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
            <div class="edit-modal__header">
                <div>
                    <p class="edit-modal__eyebrow">Quick edit</p>
                    <h2 id="edit-modal-title">Edit amount, category, or date</h2>
                </div>
                <button type="button" class="edit-modal__close" data-close-edit-modal aria-label="Close edit dialog">×</button>
            </div>

            <p class="edit-modal__copy">Update the fields you want to change, then save the expense.</p>

            <form method="post" action="" class="edit-modal__form" id="edit-modal-form">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="expense_id" id="edit-expense-id">
                <input type="hidden" name="return_month" value="<?php echo htmlspecialchars($selectedMonth); ?>">

                <div class="edit-options">
                    <label>
                        <span>Expense amount</span>
                        <input type="number" name="amount" id="edit-amount" step="0.01" min="0.01" required>
                    </label>

                    <label>
                        <span>Category</span>
                        <input list="modal-category-list" name="category" id="edit-category" required>
                    </label>

                    <label>
                        <span>Description (optional)</span>
                        <textarea name="description" id="edit-description" rows="3" placeholder="Add a short note about this expense"></textarea>
                    </label>

                    <label>
                        <span>Date</span>
                        <input type="date" name="expense_date" id="edit-date" max="<?php echo htmlspecialchars($currentDate); ?>" required>
                    </label>
                </div>

                <datalist id="modal-category-list">
                    <?php foreach ($categoryOptions as $option): ?>
                        <option value="<?php echo htmlspecialchars($option); ?>"></option>
                    <?php endforeach; ?>
                </datalist>

                <div class="edit-modal__actions">
                    <button type="button" class="edit-modal__secondary" data-close-edit-modal>Cancel</button>
                    <button type="submit" class="edit-modal__primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const editButtons = document.querySelectorAll('[data-edit-expense="1"]');
            const modal = document.getElementById('edit-modal');
            const modalForm = document.getElementById('edit-modal-form');
            const amountField = document.getElementById('edit-amount');
            const categoryField = document.getElementById('edit-category');
            const descriptionField = document.getElementById('edit-description');
            const dateField = document.getElementById('edit-date');
            const expenseIdField = document.getElementById('edit-expense-id');
            const closeButtons = document.querySelectorAll('[data-close-edit-modal]');

            function openModal(button) {
                expenseIdField.value = button.dataset.expenseId;
                amountField.value = button.dataset.amount;
                categoryField.value = button.dataset.category;
                descriptionField.value = button.dataset.description || '';
                dateField.value = button.dataset.date;
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                amountField.focus();
            }

            function closeModal() {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
            }

            editButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    openModal(button);
                });
            });

            closeButtons.forEach((button) => {
                button.addEventListener('click', closeModal);
            });

            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModal();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeModal();
                }
            });

            modalForm.addEventListener('submit', () => {
                closeModal();
            });
        }());
    </script>

    <script>
        (function () {
            const toggle = document.getElementById('account-toggle');
            const dropdown = document.getElementById('account-dropdown');

            if (!toggle || !dropdown) return;

            function open() {
                toggle.classList.add('open');
                toggle.setAttribute('aria-expanded', 'true');
                dropdown.setAttribute('aria-hidden', 'false');
            }

            function close() {
                toggle.classList.remove('open');
                toggle.setAttribute('aria-expanded', 'false');
                dropdown.setAttribute('aria-hidden', 'true');
            }

            toggle.addEventListener('click', (e) => {
                e.stopPropagation();
                if (toggle.classList.contains('open')) close(); else open();
            });

            toggle.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggle.click();
                } else if (e.key === 'Escape') {
                    close();
                }
            });

            document.addEventListener('click', (e) => {
                if (!toggle.contains(e.target)) close();
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') close();
            });
        }());
    </script>
</body>
</html>
