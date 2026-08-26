<?php
session_start();

require_once __DIR__ . '/backend/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

function clean($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';

/* =========================================================
   HANDLE ACTIONS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* CREATE TASK */
    if ($action === 'create') {

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'medium';
        $due_date = $_POST['due_date'] ?? '';

        if (!in_array($priority, ['low', 'medium', 'high'], true)) {
            $priority = 'medium';
        }

        if ($title === '') {
            $error = 'Task title is required.';
        } else {

            $dueDateTime = null;

            if ($due_date !== '') {
                $timestamp = strtotime($due_date . ' 23:59:59');

                if ($timestamp !== false) {
                    $dueDateTime = date('Y-m-d H:i:s', $timestamp);
                }
            }

            $stmt = $conn->prepare("
                INSERT INTO tasks
                (
                    user_id,
                    title,
                    description,
                    priority,
                    status,
                    due_date
                )
                VALUES (?, ?, ?, ?, 'pending', ?)
            ");

            $stmt->bind_param(
                "issss",
                $user_id,
                $title,
                $description,
                $priority,
                $dueDateTime
            );

            if ($stmt->execute()) {
                $message = 'Task created successfully.';
            } else {
                $error = 'Unable to create task.';
            }

            $stmt->close();
        }
    }

    /* EDIT TASK */
elseif ($action === 'edit') {

    $task_id = (int)($_POST['task_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $due_date = $_POST['due_date'] ?? '';

    if (!in_array($priority, ['low', 'medium', 'high'], true)) {
        $priority = 'medium';
    }

    if ($task_id <= 0 || $title === '') {
        $error = 'Task title is required.';
    } else {

        $dueDateTime = null;

        if ($due_date !== '') {
            $timestamp = strtotime($due_date . ' 23:59:59');

            if ($timestamp !== false) {
                $dueDateTime = date('Y-m-d H:i:s', $timestamp);
            }
        }

        $stmt = $conn->prepare("
            UPDATE tasks
            SET title = ?,
                description = ?,
                priority = ?,
                due_date = ?
            WHERE id = ?
            AND user_id = ?
        ");

        $stmt->bind_param(
            "ssssii",
            $title,
            $description,
            $priority,
            $dueDateTime,
            $task_id,
            $user_id
        );

        if ($stmt->execute()) {
            $message = 'Task updated successfully.';
        } else {
            $error = 'Unable to update task.';
        }

        $stmt->close();
    }
}

    /* TOGGLE TASK */
    elseif ($action === 'toggle') {

        $task_id = (int) ($_POST['task_id'] ?? 0);

        if ($task_id > 0) {

            $stmt = $conn->prepare("
                UPDATE tasks
                SET status =
                    CASE
                        WHEN status = 'completed'
                        THEN 'pending'
                        ELSE 'completed'
                    END
                WHERE id = ?
                AND user_id = ?
            ");

            $stmt->bind_param(
                "ii",
                $task_id,
                $user_id
            );

            if ($stmt->execute()) {
                $message = 'Task status updated.';
            } else {
                $error = 'Unable to update task.';
            }

            $stmt->close();
        }
    }


    /* DELETE TASK */
    elseif ($action === 'delete') {

        $task_id = (int) ($_POST['task_id'] ?? 0);

        if ($task_id > 0) {

            $stmt = $conn->prepare("
                DELETE FROM tasks
                WHERE id = ?
                AND user_id = ?
            ");

            $stmt->bind_param(
                "ii",
                $task_id,
                $user_id
            );

            if ($stmt->execute()) {
                $message = 'Task deleted successfully.';
            } else {
                $error = 'Unable to delete task.';
            }

            $stmt->close();
        }
    }
}


/* =========================================================
   CURRENT VIEW
========================================================= */

$view = $_GET['view'] ?? 'all';

$allowedViews = [
    'all',
    'pending',
    'completed',
    'high',
    'overdue',
    'today'
];

if (!in_array($view, $allowedViews, true)) {
    $view = 'all';
}


/* =========================================================
   SEARCH / FILTER
========================================================= */

$search = trim($_GET['search'] ?? '');
$priorityFilter = $_GET['priority'] ?? '';


/* =========================================================
   COUNTS
========================================================= */

$countQuery = "
    SELECT
        COUNT(*) AS total,
        SUM(status = 'pending') AS pending,
        SUM(status = 'completed') AS completed,
        SUM(priority = 'high' AND status = 'pending') AS high,
        SUM(
            status = 'pending'
            AND due_date IS NOT NULL
            AND due_date < NOW()
        ) AS overdue,
        SUM(
            status = 'pending'
            AND due_date IS NOT NULL
            AND DATE(due_date) = CURDATE()
        ) AS today
    FROM tasks
    WHERE user_id = ?
";

$stmt = $conn->prepare($countQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();

$countResult = $stmt->get_result();
$counts = $countResult->fetch_assoc();

$stmt->close();

$total = (int)($counts['total'] ?? 0);
$pending = (int)($counts['pending'] ?? 0);
$completed = (int)($counts['completed'] ?? 0);
$high = (int)($counts['high'] ?? 0);
$overdue = (int)($counts['overdue'] ?? 0);
$today = (int)($counts['today'] ?? 0);

$progress = $total > 0
    ? round(($completed / $total) * 100)
    : 0;


/* =========================================================
   TASK QUERY
========================================================= */

$sql = "
    SELECT *
    FROM tasks
    WHERE user_id = ?
";

$params = [$user_id];
$types = "i";


if ($view === 'pending') {

    $sql .= " AND status = 'pending'";

} elseif ($view === 'completed') {

    $sql .= " AND status = 'completed'";

} elseif ($view === 'high') {

    $sql .= "
        AND priority = 'high'
        AND status = 'pending'
    ";

} elseif ($view === 'overdue') {

    $sql .= "
        AND status = 'pending'
        AND due_date IS NOT NULL
        AND due_date < NOW()
    ";

} elseif ($view === 'today') {

    $sql .= "
        AND status = 'pending'
        AND due_date IS NOT NULL
        AND DATE(due_date) = CURDATE()
    ";
}


/* SEARCH */

if ($search !== '') {

    $sql .= "
        AND (
            title LIKE ?
            OR description LIKE ?
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;

    $types .= "ss";
}


/* PRIORITY */

if (
    $priorityFilter !== '' &&
    in_array($priorityFilter, ['low', 'medium', 'high'], true)
) {

    $sql .= " AND priority = ?";

    $params[] = $priorityFilter;
    $types .= "s";
}


/* ORDER */

$sql .= "
    ORDER BY
        CASE
            WHEN status = 'pending' THEN 0
            ELSE 1
        END,
        CASE
            WHEN priority = 'high' THEN 1
            WHEN priority = 'medium' THEN 2
            ELSE 3
        END,
        due_date IS NULL,
        due_date ASC,
        created_at DESC
";


$stmt = $conn->prepare($sql);

$stmt->bind_param(
    $types,
    ...$params
);

$stmt->execute();

$tasksResult = $stmt->get_result();
$tasks = $tasksResult->fetch_all(MYSQLI_ASSOC);

$stmt->close();


/* =========================================================
   PAGE TITLE
========================================================= */

$pageTitles = [
    'all' => 'All Tasks',
    'pending' => 'Pending Tasks',
    'completed' => 'Completed Tasks',
    'high' => 'High Priority',
    'overdue' => 'Overdue Tasks',
    'today' => 'Due Today'
];

$pageTitle = $pageTitles[$view];

$userName = $_SESSION['user_name']
    ?? $_SESSION['name']
    ?? 'User';

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= clean($pageTitle) ?> | TaskFlow</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="css/style.css"
    >

</head>


<body>


<!-- ======================================================
     SIDEBAR
======================================================= -->

<aside class="sidebar">

    <div class="sidebar-brand">

        <i class="bi bi-check2-square"></i>

        Task<span>Flow</span>

    </div>


    <nav>

        <a
            href="dashboard.php?view=all"
            class="<?= $view === 'all' ? 'active' : '' ?>"
        >
            <i class="bi bi-grid"></i>
            Dashboard
        </a>


        <a
            href="dashboard.php?view=all"
            class="<?= $view === 'all' ? 'active' : '' ?>"
        >
            <i class="bi bi-list-task"></i>
            All Tasks
        </a>


        <a
            href="dashboard.php?view=pending"
            class="<?= $view === 'pending' ? 'active' : '' ?>"
        >
            <i class="bi bi-hourglass-split"></i>
            Pending

            <span class="ms-auto">
                <?= $pending ?>
            </span>
        </a>


        <a
            href="dashboard.php?view=completed"
            class="<?= $view === 'completed' ? 'active' : '' ?>"
        >
            <i class="bi bi-check-circle"></i>
            Completed

            <span class="ms-auto">
                <?= $completed ?>
            </span>
        </a>


        <a
            href="dashboard.php?view=high"
            class="<?= $view === 'high' ? 'active' : '' ?>"
        >
            <i class="bi bi-flag"></i>
            High Priority

            <span class="ms-auto">
                <?= $high ?>
            </span>
        </a>


        <a
            href="dashboard.php?view=overdue"
            class="<?= $view === 'overdue' ? 'active' : '' ?>"
        >
            <i class="bi bi-exclamation-circle"></i>
            Overdue

            <span class="ms-auto">
                <?= $overdue ?>
            </span>
        </a>


        <a
            href="dashboard.php?view=today"
            class="<?= $view === 'today' ? 'active' : '' ?>"
        >
            <i class="bi bi-calendar-event"></i>
            Due Today

            <span class="ms-auto">
                <?= $today ?>
            </span>
        </a>

    </nav>


    <div class="sidebar-bottom">

        <a href="logout.php">

            <i class="bi bi-box-arrow-left"></i>

            Logout

        </a>

    </div>

</aside>



<!-- ======================================================
     MAIN
======================================================= -->

<main class="dashboard-main">


    <div class="dashboard-header">

        <div>

            <span class="small-label">
                TASK MANAGEMENT
            </span>

            <h1>
                Good day, <?= clean($userName) ?> 👋
            </h1>

            <p>
                Stay organized and get things done.
            </p>

        </div>


        <button
            type="button"
            class="btn btn-primary-custom"
            data-bs-toggle="modal"
            data-bs-target="#createTaskModal"
        >

            <i class="bi bi-plus-lg"></i>

            New Task

        </button>

    </div>



    <!-- ALERTS -->

    <?php if ($message): ?>

        <div
            class="alert alert-success alert-dismissible fade show"
        >

            <i class="bi bi-check-circle me-2"></i>

            <?= clean($message) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div
            class="alert alert-danger alert-dismissible fade show"
        >

            <i class="bi bi-exclamation-circle me-2"></i>

            <?= clean($error) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>



    <!-- ==================================================
         STAT CARDS
    =================================================== -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-list-check"></i>
                </div>

                <div>
                    <span>Total Tasks</span>
                    <h3><?= $total ?></h3>
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-hourglass-split"></i>
                </div>

                <div>
                    <span>Pending</span>
                    <h3><?= $pending ?></h3>
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-check-circle"></i>
                </div>

                <div>
                    <span>Completed</span>
                    <h3><?= $completed ?></h3>
                </div>

            </div>

        </div>


        <div class="col-6 col-lg-3">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-exclamation-circle"></i>
                </div>

                <div>
                    <span>Overdue</span>
                    <h3><?= $overdue ?></h3>
                </div>

            </div>

        </div>

    </div>



    <!-- ==================================================
         PROGRESS
    =================================================== -->

    <div class="progress-card mb-4">

        <div class="d-flex justify-content-between align-items-center">

            <div>

                <strong>
                    Overall Progress
                </strong>

                <p class="mb-0">
                    <?= $completed ?> of <?= $total ?> tasks completed
                </p>

            </div>

            <strong>
                <?= $progress ?>%
            </strong>

        </div>


        <div class="progress mt-3">

            <div
                class="progress-bar"
                style="width: <?= $progress ?>%"
            ></div>

        </div>

    </div>



    <!-- ==================================================
         SEARCH
    =================================================== -->

    <form
        method="GET"
        class="search-form mb-4"
    >

        <input
            type="hidden"
            name="view"
            value="<?= clean($view) ?>"
        >

        <div class="row g-2">

            <div class="col-md-7">

                <div class="input-group">

                    <span class="input-group-text">
                        <i class="bi bi-search"></i>
                    </span>

                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Search tasks..."
                        value="<?= clean($search) ?>"
                    >

                </div>

            </div>


            <div class="col-md-3">

                <select
                    name="priority"
                    class="form-select"
                >

                    <option value="">
                        All Priorities
                    </option>

                    <option
                        value="high"
                        <?= $priorityFilter === 'high' ? 'selected' : '' ?>
                    >
                        High
                    </option>

                    <option
                        value="medium"
                        <?= $priorityFilter === 'medium' ? 'selected' : '' ?>
                    >
                        Medium
                    </option>

                    <option
                        value="low"
                        <?= $priorityFilter === 'low' ? 'selected' : '' ?>
                    >
                        Low
                    </option>

                </select>

            </div>


            <div class="col-md-2">

                <button
                    type="submit"
                    class="btn btn-primary-custom w-100"
                >
                    Search
                </button>

            </div>

        </div>

    </form>



    <!-- ==================================================
         TASK LIST
    =================================================== -->

    <section class="task-section">

        <div class="mb-3">

            <h4 class="mb-1">
                <?= clean($pageTitle) ?>
            </h4>

            <p class="text-muted mb-0">
                Manage your tasks and stay productive.
            </p>

        </div>



        <?php if (empty($tasks)): ?>

            <div class="empty-state">

                <i class="bi bi-check2-circle"></i>

                <h5>
                    No tasks found
                </h5>

                <p>
                    <?= $view === 'completed'
                        ? 'Completed tasks will appear here.'
                        : 'Create a new task to get started.' ?>
                </p>


                <?php if ($view !== 'completed'): ?>

                    <button
                        type="button"
                        class="btn btn-primary-custom"
                        data-bs-toggle="modal"
                        data-bs-target="#createTaskModal"
                    >

                        <i class="bi bi-plus-lg"></i>

                        Create Task

                    </button>

                <?php endif; ?>

            </div>


        <?php else: ?>


            <?php foreach ($tasks as $task): ?>

                <?php

                $isCompleted =
                    $task['status'] === 'completed';

                $isOverdue =
                    !$isCompleted &&
                    !empty($task['due_date']) &&
                    strtotime($task['due_date']) < time();

                $priorityClass =
                    strtolower($task['priority']);

                ?>


                <div
                    class="task-card
                    <?= $isCompleted ? 'task-completed' : '' ?>
                    <?= $isOverdue ? 'task-overdue' : '' ?>"
                >


                    <!-- CHECK -->

                    <div class="task-check-wrapper">

                        <form method="POST">

                            <input
                                type="hidden"
                                name="action"
                                value="toggle"
                            >

                            <input
                                type="hidden"
                                name="task_id"
                                value="<?= (int)$task['id'] ?>"
                            >

                            <button
                                type="submit"
                                class="task-check-button"
                                title="<?= $isCompleted
                                    ? 'Mark as pending'
                                    : 'Mark as completed' ?>"
                            >

                                <?php if ($isCompleted): ?>

                                    <i class="bi bi-check-lg"></i>

                                <?php endif; ?>

                            </button>

                        </form>

                    </div>



                    <!-- CONTENT -->

                    <div class="task-content">

                        <h5>
                            <?= clean($task['title']) ?>
                        </h5>


                        <?php if (!empty($task['description'])): ?>

                            <p>
                                <?= clean($task['description']) ?>
                            </p>

                        <?php endif; ?>


                        <div class="task-meta">

                            <span
                                class="priority-badge <?= $priorityClass ?>"
                            >
                                <?= clean($task['priority']) ?>
                            </span>


                            <?php if (!empty($task['due_date'])): ?>

                                <span>

                                    <i class="bi bi-calendar3"></i>

                                    <?= date(
                                        'd M Y',
                                        strtotime($task['due_date'])
                                    ) ?>

                                </span>

                            <?php endif; ?>


                            <?php if ($isOverdue): ?>

                                <span class="text-danger fw-semibold">

                                    <i class="bi bi-exclamation-circle"></i>

                                    Overdue

                                </span>

                            <?php endif; ?>

                        </div>

                    </div>



                    <!-- ACTIONS -->

                    <div class="task-actions d-flex gap-2">


                        <!-- COMPLETE / UNCOMPLETE -->

                        <form method="POST">

                            <input
                                type="hidden"
                                name="action"
                                value="toggle"
                            >

                            <input
                                type="hidden"
                                name="task_id"
                                value="<?= (int)$task['id'] ?>"
                            >
<button
    type="button"
    class="btn btn-outline-primary"
    data-bs-toggle="modal"
    data-bs-target="#editTask<?= (int)$task['id'] ?>"
    title="Edit task"
>
    <i class="bi bi-pencil"></i>
</button>

                            <button
                                type="submit"
                                class="btn <?= $isCompleted
                                    ? 'btn-outline-secondary'
                                    : 'btn-outline-success' ?>"
                                title="<?= $isCompleted
                                    ? 'Move to pending'
                                    : 'Complete task' ?>"
                            >

                                <i class="bi <?= $isCompleted
                                    ? 'bi-arrow-counterclockwise'
                                    : 'bi-check-lg' ?>"></i>

                            </button>

                        </form>



                        <!-- DELETE -->

                        <form
                            method="POST"
                            onsubmit="return confirm('Delete this task permanently?');"
                        >

                            <input
                                type="hidden"
                                name="action"
                                value="delete"
                            >

                            <input
                                type="hidden"
                                name="task_id"
                                value="<?= (int)$task['id'] ?>"
                            >

                            <button
                                type="submit"
                                class="btn btn-outline-danger"
                                title="Delete task"
                            >

                                <i class="bi bi-trash"></i>

                            </button>

                        </form>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

</main>



<!-- ======================================================
     CREATE TASK MODAL
======================================================= -->

<div
    class="modal fade"
    id="createTaskModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">


            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="bi bi-plus-circle me-2"></i>

                    Create New Task

                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>



            <form method="POST">

                <input
                    type="hidden"
                    name="action"
                    value="create"
                >


                <div class="modal-body">


                    <!-- TITLE -->

                    <div class="mb-3">

                        <label class="form-label">
                            Task Title
                        </label>

                        <input
                            type="text"
                            name="title"
                            class="form-control"
                            placeholder="e.g. Complete project documentation"
                            required
                        >

                    </div>



                    <!-- DESCRIPTION -->

                    <div class="mb-3">

                        <label class="form-label">
                            Description
                        </label>

                        <textarea
                            name="description"
                            class="form-control"
                            rows="3"
                            placeholder="Describe your task..."
                        ></textarea>

                    </div>



                    <div class="row g-3">


                        <!-- PRIORITY -->

                        <div class="col-md-6">

                            <label class="form-label">
                                Priority
                            </label>

                            <select
                                name="priority"
                                class="form-select"
                            >

                                <option value="low">
                                    Low
                                </option>

                                <option
                                    value="medium"
                                    selected
                                >
                                    Medium
                                </option>

                                <option value="high">
                                    High
                                </option>

                            </select>

                        </div>



                        <!-- DUE DATE -->

                        <div class="col-md-6">

                            <label class="form-label">
                                Due Date
                            </label>

                            <input
                                type="date"
                                name="due_date"
                                class="form-control"
                            >

                        </div>

                    </div>

                </div>



                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>


                    <button
                        type="submit"
                        class="btn btn-primary-custom"
                    >

                        <i class="bi bi-plus-lg"></i>

                        Create Task

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>



<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>