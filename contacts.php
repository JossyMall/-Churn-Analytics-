<?php
session_start(); // Ensure session is started at the very beginning

// --- IMPORTANT: Include DB connection here, before any form processing or HTML output ---
require_once 'includes/db.php';

// Check if user is logged in before proceeding with any user-specific logic
if (!isset($_SESSION['user_id'])) {
    // Redirect to login page if not logged in. Use BASE_URL from db.php (or define it globally if needed).
    // Ensure BASE_URL is defined here or accessible. It is defined in db.php.
    header('Location: ' . BASE_URL . '/auth/login.php'); // Assuming your login is at auth/login.php
    exit;
}

$user_id = $_SESSION['user_id'];

// --- Helper function for stream access (copied from contact_notes.php) ---
/**
 * Checks if the current user has view access to a given stream.
 * A user has view access if:
 * 1. They are the direct owner of the stream.
 * 2. The stream is owned by a team they are a member of.
 * 3. The stream is explicitly shared with a team they are a member of.
 * @param PDO $pdo
 * @param int $current_user_id
 * @param int $stream_id
 * @return bool
 */
function user_has_stream_view_access($pdo, $current_user_id, $stream_id) {
    // Check if current user is direct owner of the stream
    $stmt_owner = $pdo->prepare("SELECT COUNT(*) FROM streams WHERE id = ? AND user_id = ?");
    $stmt_owner->execute([$stream_id, $current_user_id]);
    if ($stmt_owner->fetchColumn() > 0) {
        return true;
    }

    // Check if stream is owned by a team the user is a member of
    $stmt_team_owned = $pdo->prepare("
        SELECT COUNT(s.id)
        FROM streams s
        JOIN team_members tm ON s.team_id = tm.team_id
        WHERE s.id = ? AND tm.user_id = ? AND s.team_id IS NOT NULL
    ");
    $stmt_team_owned->execute([$stream_id, $current_user_id]);
    if ($stmt_team_owned->fetchColumn() > 0) {
        return true;
    }

    // Check if stream is explicitly shared with a team the user is a member of
    $stmt_shared_team = $pdo->prepare("
        SELECT COUNT(s.id)
        FROM streams s
        JOIN team_streams ts ON s.id = ts.stream_id
        JOIN team_members tm ON ts.team_id = tm.team_id
        WHERE s.id = ? AND tm.user_id = ?
    ");
    $stmt_shared_team->execute([$stream_id, $current_user_id]);
    if ($stmt_shared_team->fetchColumn() > 0) {
        return true;
    }

    return false;
}

/**
 * Gets an array of stream IDs that the current user has access to.
 * This is a composite of streams they own, streams owned by their teams,
 * and streams shared with their teams.
 * @param PDO $pdo
 * @param int $user_id
 * @return array
 */
function get_accessible_stream_ids($pdo, $user_id) {
    $accessible_stream_ids = [];

    // Streams directly owned by the user
    $stmt_owned = $pdo->prepare("SELECT id FROM streams WHERE user_id = ?");
    $stmt_owned->execute([$user_id]);
    while ($row = $stmt_owned->fetch(PDO::FETCH_ASSOC)) {
        $accessible_stream_ids[] = $row['id'];
    }

    // Streams owned by a team the user is part of
    $stmt_team_owned = $pdo->prepare("
        SELECT s.id
        FROM streams s
        JOIN team_members tm ON s.team_id = tm.team_id
        WHERE tm.user_id = ? AND s.team_id IS NOT NULL
    ");
    $stmt_team_owned->execute([$user_id]);
    while ($row = $stmt_team_owned->fetch(PDO::FETCH_ASSOC)) {
        $accessible_stream_ids[] = $row['id'];
    }

    // Streams explicitly shared with a team the user is a member of
    $stmt_shared_team = $pdo->prepare("
        SELECT s.id
        FROM streams s
        JOIN team_streams ts ON s.id = ts.stream_id
        JOIN team_members tm ON ts.team_id = tm.team_id
        WHERE tm.user_id = ?
    ");
    $stmt_shared_team->execute([$user_id]);
    while ($row = $stmt_shared_team->fetch(PDO::FETCH_ASSOC)) {
        $accessible_stream_ids[] = $row['id'];
    }

    return array_unique($accessible_stream_ids); // Return unique IDs
}

$accessible_stream_ids = get_accessible_stream_ids($pdo, $user_id);


// --- AJAX Handler for Get Contact Metrics (MOVED TO TOP FOR CLEAN EXIT) ---
// This block must be BEFORE any other HTML output.
if (isset($_POST['action']) && $_POST['action'] === 'get_contact_metrics') {
    header('Content-Type: application/json');
    // $pdo is already available from the require_once 'includes/db.php'; above.

    // Initializing churn_metric_ids for get_trend_data function
    $churn_metric_ids = [];
    try {
        $stmt_metrics = $pdo->query("SELECT id, name FROM churn_metrics");
        while ($row = $stmt_metrics->fetch(PDO::FETCH_ASSOC)) {
            $churn_metric_ids[$row['name']] = $row['id'];
        }
    } catch (PDOException $e) {
        error_log("Database error fetching churn_metrics for AJAX: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error preparing metrics definitions.']);
        exit;
    }

    // Helper function (copied from explore.php logic) for AJAX only
    function get_trend_data_ajax($metric_name, $contact_id, $churn_metric_ids_array, $pdo_instance, $period_days = 30) {
        $trend = [];
        $metric_id = $churn_metric_ids_array[$metric_name] ?? null;

        try {
            if ($metric_name === 'churn_probability') {
                $stmt = $pdo_instance->prepare("
                    SELECT DATE_FORMAT(scored_at, '%Y-%m-%d') as date, score as value
                    FROM churn_scores
                    WHERE contact_id = :contact_id AND scored_at >= DATE_SUB(NOW(), INTERVAL :period_days DAY)
                    GROUP BY date
                    ORDER BY date ASC
                ");
                $stmt->bindValue(':contact_id', $contact_id, PDO::PARAM_INT);
                $stmt->bindValue(':period_days', (int)$period_days, PDO::PARAM_INT);
            } else if ($metric_id) {
                $stmt = $pdo_instance->prepare("
                    SELECT DATE_FORMAT(recorded_at, '%Y-%m-%d') as date, COUNT(id) as value
                    FROM metric_data
                    WHERE contact_id = :contact_id AND metric_id = :metric_id AND recorded_at >= DATE_SUB(NOW(), INTERVAL :period_days DAY)
                    GROUP BY date
                    ORDER BY date ASC
                ");
                $stmt->bindValue(':contact_id', $contact_id, PDO::PARAM_INT);
                $stmt->bindValue(':metric_id', $metric_id, PDO::PARAM_INT);
                $stmt->bindValue(':period_days', (int)$period_days, PDO::PARAM_INT);
            } else {
                // Populate with zero values for the full period if metric_id not found
                for ($i = $period_days - 1; $i >= 0; $i--) {
                    $date = (clone new DateTime())->modify("-$i days");
                    $trend[] = ['x' => $date->getTimestamp() * 1000, 'y' => 0.0];
                }
                return $trend;
            }

            $stmt->execute();
            $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $current_date_dt = new DateTime();
            for ($i = $period_days - 1; $i >= 0; $i--) {
                $date_point = (clone $current_date_dt)->modify("-$i days");
                $date_str = $date_point->format('Y-m-d');
                $found = false;
                foreach ($raw_data as $row) {
                    if ($row['date'] === $date_str) {
                        $trend[] = ['x' => $date_point->getTimestamp() * 1000, 'y' => (float)$row['value']];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $trend[] = ['x' => $date_point->getTimestamp() * 1000, 'y' => 0.0];
                }
            }
        } catch (PDOException $e) {
            error_log("Database error fetching trend for {$metric_name} in AJAX: " . $e->getMessage());
            // Fallback to empty/zero data on error
            $trend = [];
            for ($i = 0; $i < $period_days; $i++) {
                $date_point = (clone new DateTime())->modify("-$i days");
                $trend[] = ['x' => $date_point->getTimestamp() * 1000, 'y' => 0.0];
            }
        }
        return $trend;
    }

    $contact_id = (int)($_POST['contact_id'] ?? 0);
    $user_id_from_session = $_SESSION['user_id']; // Ensure current user ID is used

    if ($contact_id === 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid contact ID.']);
        exit;
    }

    try {
        // Verify ownership or access of the contact by the current user
        $stmt_verify = $pdo->prepare("SELECT c.id, c.stream_id, s.name AS stream_name FROM contacts c JOIN streams s ON c.stream_id = s.id WHERE c.id = ?");
        $stmt_verify->execute([$contact_id]);
        $contact_info = $stmt_verify->fetch(PDO::FETCH_ASSOC);

        if (!$contact_info || !user_has_stream_view_access($pdo, $user_id_from_session, $contact_info['stream_id'])) {
            echo json_encode(['success' => false, 'error' => 'Contact not found or unauthorized.']);
            exit;
        }

        // Fetch churn score for this contact
        $churn_score = null;
        $stmt_score = $pdo->prepare("SELECT score, scored_at, report, model_used FROM churn_scores WHERE contact_id = ? ORDER BY scored_at DESC LIMIT 1");
        $stmt_score->execute([$contact_id]);
        $latest_churn_score = $stmt_score->fetch(PDO::FETCH_ASSOC);
        if ($latest_churn_score) {
            $churn_score = [
                'score' => number_format($latest_churn_score['score'], 2),
                'scored_at' => $latest_churn_score['scored_at'],
                'report' => $latest_churn_score['report'],
                'model_used' => $latest_churn_score['model_used']
            ];
        }

        // Fetch custom fields for this contact
        $custom_fields_data = [];
        $stmt_custom_fields = $pdo->prepare("SELECT field_name, field_value FROM contact_custom_fields WHERE contact_id = ?");
        $stmt_custom_fields->execute([$contact_id]);
        $custom_fields_data = $stmt_custom_fields->fetchAll(PDO::FETCH_ASSOC);

        // Fetch cohorts for this contact
        $contact_cohort_names = [];
        $stmt_cohorts = $pdo->prepare("SELECT co.name FROM contact_cohorts cc JOIN cohorts co ON cc.cohort_id = co.id WHERE cc.contact_id = ?");
        $stmt_cohorts->execute([$contact_id]);
        $contact_cohort_names = $stmt_cohorts->fetchAll(PDO::FETCH_COLUMN);

        // Check churned/resurrected status for this contact
        $is_churned = false;
        $stmt_churned = $pdo->prepare("SELECT COUNT(*) FROM churned_users WHERE contact_id = ?");
        $stmt_churned->execute([$contact_id]);
        if ($stmt_churned->fetchColumn() > 0) $is_churned = true;

        $is_resurrected = false;
        $stmt_resurrected = $pdo->prepare("SELECT COUNT(*) FROM resurrected_users WHERE contact_id = ?");
        $stmt_resurrected->execute([$contact_id]);
        if ($stmt_resurrected->fetchColumn() > 0) $is_resurrected = true;

        // Fetch trend data for sparklines using the helper function
        $churn_scores_trend = get_trend_data_ajax('churn_probability', $contact_id, $churn_metric_ids, $pdo);
        $competitor_visits_trend = get_trend_data_ajax('competitor_visit', $contact_id, $churn_metric_ids, $pdo);
        $feature_usage_trend = get_trend_data_ajax('feature_usage', $contact_id, $churn_metric_ids, $pdo);

        // Fetch all individual metric_data entries
        $metrics_query = "
            SELECT
                md.value,
                md.recorded_at,
                md.source,
                COALESCE(cm.name, cum.name) AS metric_name,
                COALESCE(cm.category, 'custom') AS metric_category
            FROM
                metric_data md
            LEFT JOIN
                churn_metrics cm ON md.metric_id = cm.id
            LEFT JOIN
                custom_metrics cum ON md.custom_metric_id = cum.id
            WHERE
                md.contact_id = ?
            ORDER BY
                md.recorded_at DESC;
        ";
        $stmt = $pdo->prepare($metrics_query);
        $stmt->execute([$contact_id]);
        $all_individual_metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'contact_id' => $contact_id,
            'stream_name' => $contact_info['stream_name'], // Pass stream name
            'churn_score' => $churn_score,
            'is_churned' => $is_churned,
            'is_resurrected' => $is_resurrected,
            'custom_fields_data' => $custom_fields_data,
            'cohort_names' => $contact_cohort_names,
            'churn_scores_trend' => $churn_scores_trend,
            'competitor_visits_trend' => $competitor_visits_trend,
            'feature_usage_trend' => $feature_usage_trend,
            'all_individual_metrics' => $all_individual_metrics
        ]);
        exit; // IMPORTANT: Exit immediately after sending JSON
    } catch (PDOException $e) {
        error_log("Error fetching contact metrics for contact ID {$contact_id}: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit; // IMPORTANT: Exit immediately on error
    }
}
// --- END AJAX Handler ---


// --- Handle Add Contact Form Submission ---
// This block must be BEFORE any other HTML output.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_contact'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $external_id = trim($_POST['external_id'] ?? '');
    $stream_id = (int)($_POST['stream_id'] ?? 0);
    $cohorts = $_POST['cohorts'] ?? [];
    $custom_data = $_POST['custom_data'] ?? '';

    // Basic validation
    if (empty($email)) {
        $_SESSION['error'] = "Email is required.";
        header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
        exit;
    }

    // Verify stream access by the current user for adding contact
    if (!user_has_stream_view_access($pdo, $user_id, $stream_id)) {
        $_SESSION['error'] = "Unauthorized: Cannot add contact to this stream.";
        header("Location: contacts.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Insert contact
        $stmt = $pdo->prepare("INSERT INTO contacts (stream_id, username, email, external_id, custom_data) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$stream_id, $username ?: null, $email, $external_id ?: null, $custom_data ?: null]);
        $contact_id = $pdo->lastInsertId();

        // Add to selected cohorts
        if (!empty($cohorts)) {
            $cohort_insert = $pdo->prepare("INSERT INTO contact_cohorts (contact_id, cohort_id) VALUES (?, ?)");
            foreach ($cohorts as $cohort_id_val) {
                $cohort_id_val = (int)$cohort_id_val;
                if ($cohort_id_val > 0) {
                    // Verify cohort belongs to an accessible stream for the user (or the selected stream)
                    $stmt_check_cohort_stream = $pdo->prepare("SELECT id FROM cohorts WHERE id = ? AND stream_id IN (" . implode(',', array_map('intval', $accessible_stream_ids)) . ")");
                    $stmt_check_cohort_stream->execute([$cohort_id_val]);
                    if ($stmt_check_cohort_stream->fetch()) {
                        $cohort_insert->execute([$contact_id, $cohort_id_val]);
                    }
                }
            }
        }

        $pdo->commit();
        $_SESSION['success'] = "Contact added successfully!";
        header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error adding contact: " . $e->getMessage();
        header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
        exit;
    }
}
// --- END Handle Add Contact Form Submission ---


// --- Handle Delete Contact Form Submission ---
// This block must be BEFORE any other HTML output.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_contact'])) {
    $contact_id_to_delete = (int)($_POST['contact_id'] ?? 0);

    if ($contact_id_to_delete === 0) {
        $_SESSION['error'] = "Invalid contact ID for deletion.";
        header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
        exit;
    }

    try {
        $pdo->beginTransaction();

        // First, verify ownership or access of the contact by the current user
        $stmt_verify_contact_stream = $pdo->prepare("SELECT stream_id FROM contacts WHERE id = ?");
        $stmt_verify_contact_stream->execute([$contact_id_to_delete]);
        $contact_stream_id = $stmt_verify_contact_stream->fetchColumn();

        if (!$contact_stream_id || !user_has_stream_view_access($pdo, $user_id, $contact_stream_id)) {
            $_SESSION['error'] = "Unauthorized: Contact not found or does not belong to an accessible stream.";
            header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
            exit;
        }

        // Check if the user has 'edit' or 'owner' role if it's a team-owned/shared stream
        // For simplicity, we'll allow delete if they have ANY access (view, edit, owner).
        // If delete should be restricted to ONLY stream owners/team owners, the logic in user_can_manage_notes_on_stream (or similar) is needed here.
        // For now, assuming anyone with access can delete.

        // Delete related data first due to foreign key constraints
        $pdo->prepare("DELETE FROM contact_cohorts WHERE contact_id = ?")->execute([$contact_id_to_delete]);
        $pdo->prepare("DELETE FROM contact_custom_fields WHERE contact_id = ?")->execute([$contact_id_to_delete]);
        $pdo->prepare("DELETE FROM churn_scores WHERE contact_id = ?")->execute([$contact_id_to_delete]);
        $pdo->prepare("DELETE FROM metric_data WHERE contact_id = ?")->execute([$contact_id_to_delete]);
        $pdo->prepare("DELETE FROM churned_users WHERE contact_id = ?")->execute([$contact_id_to_delete]);
        $pdo->prepare("DELETE FROM resurrected_users WHERE contact_id = ?")->execute([$contact_id_to_delete]);
        $pdo->prepare("DELETE FROM email_logs WHERE contact_id = ?")->execute([$contact_id_to_delete]);
        $pdo->prepare("DELETE FROM contact_notes WHERE contact_id = ?")->execute([$contact_id_to_delete]); // New: Delete associated notes

        // Finally, delete the contact itself
        $stmt_delete_contact = $pdo->prepare("DELETE FROM contacts WHERE id = ?");
        $stmt_delete_contact->execute([$contact_id_to_delete]);

        $pdo->commit();
        $_SESSION['success'] = "Contact and all associated data deleted successfully.";
        header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error deleting contact ID {$contact_id_to_delete}: " . $e->getMessage());
        $_SESSION['error'] = "Database error deleting contact: " . $e->getMessage();
        header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
        exit;
    }
}


// --- Handle Import Contacts Form Submission ---
// This block must be BEFORE any other HTML output.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_contacts'])) {
    $stream_id = (int)($_POST['stream_id'] ?? 0);
    $cohorts_to_assign = $_POST['cohorts'] ?? [];
    $custom_data_json = trim($_POST['custom_data'] ?? '');

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "Please upload a CSV file.";
        header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
        exit;
    }

    $file_tmp_path = $_FILES['csv_file']['tmp_name'];
    $file_mime_type = mime_content_type($file_tmp_path);

    if ($file_mime_type !== 'text/csv' && $file_mime_type !== 'application/csv' && $file_mime_type !== 'text/plain') { // Added text/plain for broader CSV compatibility
        $_SESSION['error'] = "Invalid file type. Only CSV files are supported.";
        header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
        exit;
    }

    // Verify stream access for import
    if (!user_has_stream_view_access($pdo, $user_id, $stream_id)) {
        $_SESSION['error'] = "Unauthorized: Cannot import contacts to this stream.";
        header("Location: contacts.php");
        exit;
    }

    $imported_count = 0;
    $skipped_count = 0;
    $errors = [];

    // Get membership limits (only for streams directly owned by the user, if this limit applies across all accessible streams, this query needs adjustment)
    $stmt_membership = $pdo->prepare("SELECT ml.max_contacts FROM user_subscriptions us JOIN membership_levels ml ON us.membership_id = ml.id WHERE us.user_id = ? AND us.is_active = 1");
    $stmt_membership->execute([$user_id]);
    $membership_limit_data = $stmt_membership->fetch();
    $max_contacts_allowed = $membership_limit_data['max_contacts'] ?? 50; // Default to 50 if not found

    // Get current contact count across all streams owned by the user
    // If membership limits apply to *all accessible* streams (team-owned/shared), this query needs to include accessible_stream_ids
    $stmt_current_contacts = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE stream_id IN (SELECT id FROM streams WHERE user_id = ?)");
    $stmt_current_contacts->execute([$user_id]);
    $current_contacts_count = $stmt_current_contacts->fetchColumn();

    try {
        $pdo->beginTransaction();

        if (($handle = fopen($file_tmp_path, "r")) !== FALSE) {
            $header = fgetcsv($handle); // Read header row

            // Map header columns to expected fields (case-insensitive)
            $column_map = [
                'username' => -1,
                'email' => -1,
                'external_id' => -1
            ];
            foreach ($header as $index => $col_name) {
                $col_name_lower = strtolower(trim($col_name));
                if (isset($column_map[$col_name_lower])) {
                    $column_map[$col_name_lower] = $index;
                }
            }

            // Check if email column exists
            if ($column_map['email'] === -1) {
                $_SESSION['error'] = "CSV must contain an 'email' column.";
                fclose($handle);
                $pdo->rollBack();
                header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
                exit;
            }

            $insert_contact_stmt = $pdo->prepare("INSERT INTO contacts (stream_id, username, email, external_id, custom_data) VALUES (?, ?, ?, ?, ?)");
            $insert_cohort_stmt = $pdo->prepare("INSERT INTO contact_cohorts (contact_id, cohort_id) VALUES (?, ?)");

            while (($data = fgetcsv($handle)) !== FALSE) {
                // Check if current contact count is approaching or exceeded limit
                if (($current_contacts_count + $imported_count) >= $max_contacts_allowed) {
                    $errors[] = "Membership limit of {$max_contacts_allowed} contacts reached. Stopped import.";
                    // This estimation of skipped count is not perfectly accurate if there are malformed rows
                    $skipped_count += (count(file($file_tmp_path)) - 1 - $imported_count - $skipped_count);
                    break;
                }

                $email = trim($data[$column_map['email']] ?? '');
                $username = trim($data[$column_map['username']] ?? '');
                $external_id = trim($data[$column_map['external_id']] ?? '');

                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Skipped row due to invalid or missing email: " . htmlspecialchars(implode(', ', $data));
                    $skipped_count++;
                    continue;
                }

                try {
                    $insert_contact_stmt->execute([
                        $stream_id,
                        $username ?: null,
                        $email,
                        $external_id ?: null,
                        $custom_data_json ?: null
                    ]);
                    $contact_id = $pdo->lastInsertId();
                    $imported_count++;

                    // Assign contacts to selected cohorts
                    if (!empty($cohorts_to_assign)) {
                        foreach ($cohorts_to_assign as $cohort_id_to_assign) {
                            $cohort_id_to_assign = (int)$cohort_id_to_assign;
                            if ($cohort_id_to_assign > 0) {
                                // Verify cohort belongs to an accessible stream
                                $stmt_check_cohort = $pdo->prepare("SELECT id FROM cohorts WHERE id = ? AND stream_id IN (" . implode(',', array_map('intval', $accessible_stream_ids)) . ")");
                                $stmt_check_cohort->execute([$cohort_id_to_assign]);
                                if ($stmt_check_cohort->fetch()) {
                                    $insert_cohort_stmt->execute([$contact_id, $cohort_id_to_assign]);
                                }
                            }
                        }
                    }
                } catch (PDOException $e) {
                    if ($e->getCode() == '23000') { // Duplicate entry error code for unique constraints
                        $errors[] = "Skipped duplicate contact email: " . htmlspecialchars($email);
                    } else {
                        $errors[] = "Database error for email " . htmlspecialchars($email) . ": " . $e->getMessage();
                    }
                    $skipped_count++;
                }
            }
            fclose($handle);
        } else {
            $_SESSION['error'] = "Could not open uploaded file.";
            header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
            exit;
        }

        $pdo->commit();

        if ($imported_count > 0) {
            $_SESSION['success'] = "Import complete: {$imported_count} contacts added.";
            if ($skipped_count > 0) {
                $_SESSION['success'] .= " ({$skipped_count} skipped).";
            }
            if (!empty($errors)) {
                 $_SESSION['error'] = implode("<br>", $errors); // Store errors for display
            }
        } else {
            $_SESSION['error'] = "No new contacts imported. " . (!empty($errors) ? implode("<br>", $errors) : "Check CSV format and limits.");
        }

        header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error during import: " . $e->getMessage());
        $_SESSION['error'] = "A critical error occurred during import: " . $e->getMessage();
        header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
        exit;
    }
}
// --- END Import Contacts Form Submission ---


// --- Handle Send Email Form Submission ---
// This block must be BEFORE any other HTML output.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email_to_contact'])) {
    $contact_id_email = (int)($_POST['contact_id'] ?? 0);
    $sender_name = trim($_POST['sender_name'] ?? '');
    $email_subject = trim($_POST['email_subject'] ?? '');
    $email_content = $_POST['email_content'] ?? ''; // HTML content, no trim for raw HTML

    if (empty($contact_id_email) || empty($sender_name) || empty($email_subject) || empty($email_content)) {
        $_SESSION['error'] = "All email fields are required.";
        header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
        exit;
    }

    try {
        // Fetch contact details for placeholders and verification
        $stmt = $pdo->prepare("
            SELECT c.email, c.username, c.external_id, s.name AS stream_name, s.id AS stream_id
            FROM contacts c
            JOIN streams s ON c.stream_id = s.id
            WHERE c.id = ?
        ");
        $stmt->execute([$contact_id_email]);
        $contact_details = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verify access to contact's stream before sending email
        if (!$contact_details || !user_has_stream_view_access($pdo, $user_id, $contact_details['stream_id'])) {
            $_SESSION['error'] = "Contact not found or unauthorized for email sending.";
            header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
            exit;
        }

        // Fetch contact's cohorts
        $stmt_cohorts = $pdo->prepare("SELECT co.name FROM contact_cohorts cc JOIN cohorts co ON cc.cohort_id = co.id WHERE cc.contact_id = ?");
        $stmt_cohorts->execute([$contact_id_email]);
        $contact_cohort_names = $stmt_cohorts->fetchAll(PDO::FETCH_COLUMN);

        // Fetch contact's custom fields
        $stmt_custom_fields = $pdo->prepare("SELECT field_name, field_value FROM contact_custom_fields WHERE contact_id = ?");
        $stmt_custom_fields->execute([$contact_id_email]);
        $contact_custom_fields = $stmt_custom_fields->fetchAll(PDO::FETCH_KEY_PAIR); // ['field_name' => 'field_value']

        // Prepare placeholders for replacement
        $placeholders = [
            '{username}' => htmlspecialchars($contact_details['username'] ?: 'N/A'),
            '{email}' => htmlspecialchars($contact_details['email']),
            '{stream_name}' => htmlspecialchars($contact_details['stream_name']),
            '{stream_id}' => htmlspecialchars($contact_details['stream_id']),
            '{cohort_name}' => htmlspecialchars(implode(', ', $contact_cohort_names) ?: 'N/A'),
            '{external_id}' => htmlspecialchars($contact_details['external_id'] ?: 'N/A')
        ];

        // Add custom fields to placeholders
        foreach ($contact_custom_fields as $field_name => $field_value) {
            $placeholders['{' . htmlspecialchars($field_name) . '}'] = htmlspecialchars($field_value);
        }

        // Perform placeholder replacements in subject and content
        $final_subject = str_replace(array_keys($placeholders), array_values($placeholders), $email_subject);
        $final_content = str_replace(array_keys($placeholders), array_values($placeholders), $email_content);

        // --- Simulate Email Sending (Replace with actual email sending logic) ---
        // For demonstration, we'll just log it. In a real application, you'd use PHPMailer, SwiftMailer, SendGrid, etc.
        // The actual sending part is outside the scope of this file.
        $simulated_email_sent = true; // Assume success for now

        if ($simulated_email_sent) {
            // Log the email in email_logs table
            $stmt_log_email = $pdo->prepare("INSERT INTO email_logs (contact_id, user_id, sender_name, recipient_email, subject, content, sent_at, status) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt_log_email->execute([
                $contact_id_email,
                $user_id,
                $sender_name,
                $contact_details['email'],
                $final_subject,
                $final_content,
                'sent'
            ]);

            $_SESSION['success'] = "Email simulated and logged successfully to " . htmlspecialchars($contact_details['email']) . "!";
        } else {
            $_SESSION['error'] = "Failed to send email to " . htmlspecialchars($contact_details['email']) . ".";
        }

        header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
        exit;

    } catch (PDOException $e) {
        error_log("Error sending email to contact ID {$contact_id_email}: " . $e->getMessage());
        $_SESSION['error'] = "Database error during email sending: " . $e->getMessage();
        header("Location: contacts.php" . ($stream_id ? "?stream_id=$stream_id" : ""));
        exit;
    }
}
// --- END Send Email Form Submission ---


// --- Helper function to fetch trend data (copied from explore.php logic) ---
// This function needs access to $pdo and $churn_metric_ids
// Initializing churn_metric_ids for get_trend_data
$churn_metric_ids = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM churn_metrics");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $churn_metric_ids[$row['name']] = $row['id'];
    }
    // Add 'competitor_visit' if not already present in churn_metrics (adjust ID if needed)
    // You should ideally seed this in your database if it's a standard metric.
    if (!isset($churn_metric_ids['competitor_visit'])) {
          // Example: Assign a placeholder ID or ensure it's seeded.
          // For now, this means get_trend_data for 'competitor_visit' will return empty data.
    }
} catch (PDOException $e) {
    error_log("Database error fetching churn_metrics for get_trend_data: " . $e->getMessage());
    // $churn_metric_ids remains empty array if error occurs
}


function get_trend_data($metric_name, $contact_id, $churn_metric_ids_array, $pdo_instance, $period_days = 30) {
    $trend = [];
    $metric_id = $churn_metric_ids_array[$metric_name] ?? null;

    try {
        if ($metric_name === 'churn_probability') {
            $stmt = $pdo_instance->prepare("
                SELECT DATE_FORMAT(scored_at, '%Y-%m-%d') as date, score as value
                FROM churn_scores
                WHERE contact_id = :contact_id AND scored_at >= DATE_SUB(NOW(), INTERVAL :period_days DAY)
                GROUP BY date
                ORDER BY date ASC
            ");
            $stmt->bindValue(':contact_id', $contact_id, PDO::PARAM_INT);
            $stmt->bindValue(':period_days', (int)$period_days, PDO::PARAM_INT);
        } else if ($metric_id) {
            // This handles both 'feature_usage' and 'competitor_visit' if they exist in churn_metrics
            $stmt = $pdo_instance->prepare("
                SELECT DATE_FORMAT(recorded_at, '%Y-%m-%d') as date, COUNT(id) as value
                FROM metric_data
                WHERE contact_id = :contact_id AND metric_id = :metric_id AND recorded_at >= DATE_SUB(NOW(), INTERVAL :period_days DAY)
                GROUP BY date
                ORDER BY date ASC
            ");
            $stmt->bindValue(':contact_id', $contact_id, PDO::PARAM_INT);
            $stmt->bindValue(':metric_id', $metric_id, PDO::PARAM_INT);
            $stmt->bindValue(':period_days', (int)$period_days, PDO::PARAM_INT);
        } else {
            // Metric ID not found or invalid, return empty trend with correct dates
            // Populate with zero values for the full period
            for ($i = $period_days - 1; $i >= 0; $i--) {
                $date = (clone new DateTime())->modify("-$i days");
                $trend[] = ['x' => $date->getTimestamp() * 1000, 'y' => 0.0];
            }
            return $trend;
        }

        $stmt->execute();
        $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Populate daily data, filling zeros for missing days to ensure continuous line
        $current_date_dt = new DateTime();
        for ($i = $period_days - 1; $i >= 0; $i--) {
            $date_point = (clone $current_date_dt)->modify("-$i days");
            $date_str = $date_point->format('Y-m-d');
            $found = false;
            foreach ($raw_data as $row) {
                if ($row['date'] === $date_str) {
                    $trend[] = ['x' => $date_point->getTimestamp() * 1000, 'y' => (float)$row['value']];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $trend[] = ['x' => $date_point->getTimestamp() * 1000, 'y' => 0.0];
            }
        }
    } catch (PDOException $e) {
        error_log("Database error fetching trend for $metric_name: " . $e->getMessage());
        // Fallback to empty/zero data on error
        $trend = []; // Clear any partial data
        for ($i = 0; $i < $period_days; $i++) {
            $date_point = (clone new DateTime())->modify("-$i days");
            $trend[] = ['x' => $date_point->getTimestamp() * 1000, 'y' => 0.0];
        }
    }
    return $trend;
}
// --- End Helper function ---


// --- Fetch User-Specific Data for JS pre-population ---

// 1. Fetch Email Templates
$user_email_templates = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, subject, content, sender_name FROM email_templates WHERE user_id = ? OR user_id IS NULL ORDER BY name ASC");
    $stmt->execute([$user_id]);
    $user_email_templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching email templates for contacts.php: " . $e->getMessage());
    // Gracefully continue, templates array will be empty
}

// 2. Fetch Cohorts, grouped by stream_id
$user_cohorts_by_stream = [];
try {
    // Select cohorts that belong to accessible streams
    $placeholders = implode(',', array_fill(0, count($accessible_stream_ids), '?'));
    if (!empty($accessible_stream_ids)) {
        $stmt = $pdo->prepare("SELECT id, name, stream_id FROM cohorts WHERE stream_id IN ($placeholders) ORDER BY stream_id, name");
        $stmt->execute($accessible_stream_ids);
        $all_user_cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($all_user_cohorts as $cohort) {
            // Ensure the stream_id key exists before adding the cohort
            if (!isset($user_cohorts_by_stream[$cohort['stream_id']])) {
                $user_cohorts_by_stream[$cohort['stream_id']] = [];
            }
            $user_cohorts_by_stream[$cohort['stream_id']][] = [
                'id' => $cohort['id'],
                'name' => $cohort['name']
            ];
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching cohorts for contacts.php: " . $e->getMessage());
    // Gracefully continue, cohorts array will be empty
}

// --- End Fetch User-Specific Data ---


// Get membership info
$stmt = $pdo->prepare("SELECT ml.name, ml.max_contacts
                     FROM user_subscriptions us
                     JOIN membership_levels ml ON us.membership_id = ml.id
                     WHERE us.user_id = ? AND us.is_active = 1");
$stmt->execute([$user_id]);
$membership = $stmt->fetch();

$max_contacts = $membership['max_contacts'] ?? 50;
$membership_name = $membership['name'] ?? 'Free';

// Get current contact count (across all streams owned by the user)
// NOTE: If membership limit should apply to ALL ACCESSIBLE streams (including team-owned/shared),
// this query needs to be updated to use $accessible_stream_ids.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE stream_id IN (SELECT id FROM streams WHERE user_id = ?)");
$stmt->execute([$user_id]);
$current_contacts = $stmt->fetchColumn();


// Get stream_id from query parameter, verify access
$selected_stream_id_filter = isset($_GET['stream_id']) ? (int)$_GET['stream_id'] : null;

// Validate if the requested stream_id is actually accessible by the user
if ($selected_stream_id_filter && !in_array($selected_stream_id_filter, $accessible_stream_ids)) {
    $selected_stream_id_filter = null; // Reset if not accessible
    $_SESSION['error'] = "You do not have access to the requested stream.";
}


// Get all streams for dropdown (only accessible ones)
$streams_for_dropdown = [];
if (!empty($accessible_stream_ids)) {
    $placeholders = implode(',', array_fill(0, count($accessible_stream_ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name FROM streams WHERE id IN ($placeholders) ORDER BY name");
    $stmt->execute($accessible_stream_ids);
    $streams_for_dropdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// --- Fetch Contacts for Display (Main Table) ---
$query = "SELECT c.*, s.name as stream_name
           FROM contacts c
           JOIN streams s ON c.stream_id = s.id";

$contact_params = [];

if (!empty($accessible_stream_ids)) {
    $placeholders = implode(',', array_fill(0, count($accessible_stream_ids), '?'));
    $query .= " WHERE c.stream_id IN ($placeholders)";
    $contact_params = $accessible_stream_ids;
} else {
    // If no accessible streams, return no contacts
    $contacts = [];
    $total_display_contacts = 0;
    goto end_contact_fetch; // Skip further fetching if no streams are accessible
}


if ($selected_stream_id_filter) {
    $query .= " AND c.stream_id = ?";
    $contact_params[] = $selected_stream_id_filter;
}

$query .= " ORDER BY c.created_at DESC LIMIT 500"; // Apply a limit for display performance

$stmt = $pdo->prepare($query);
$stmt->execute($contact_params);
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_display_contacts = count($contacts); // Count contacts currently being displayed

end_contact_fetch: // Label for goto


// Fetch cohorts and custom fields for each contact for display in the main table
foreach ($contacts as &$contact) {
    // Fetch cohorts for current contact
    $stmt = $pdo->prepare("SELECT co.name
                             FROM contact_cohorts cc
                             JOIN cohorts co ON cc.cohort_id = co.id
                             WHERE cc.contact_id = ?");
    $stmt->execute([$contact['id']]);
    $contact['cohorts'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch custom fields for current contact
    $stmt = $pdo->prepare("SELECT field_name, field_value
                             FROM contact_custom_fields
                             WHERE contact_id = ?");
    $stmt->execute([$contact['id']]);
    $contact['custom_fields_display'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($contact); // Unset the reference

?>

<?php require_once 'includes/header.php'; ?>
<style>
/* Add the necessary styles here, as they were in the previous header.php */
/* These styles should ideally be in a contacts.css or similar */
.send-email-btn, .view-metrics-btn, .view-notes-btn { /* Added .view-notes-btn */
    background-color: #000; /* Assuming --primary is defined globally or in contacts.css */
    color: white;
    border: none;
    padding: 8px 12px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 0.9em;
    margin-left: 5px;
    transition: background-color 0.2s ease;
}

.send-email-btn:hover, .view-metrics-btn:hover, .view-notes-btn:hover { /* Added .view-notes-btn */
    background-color: var(--primary-dark); /* Assuming --primary-dark is defined globally or in contacts.css */
}

.modal-content {
    max-height: 90vh; /* Allow modal content to scroll */
    overflow-y: auto; /* Enable vertical scrolling */
    transform: translate3d(0,0,0); /* Ensure hardware acceleration for smooth dragging */
    position: relative; /* Needed for z-index and positioning contexts */
}

/* Draggable handle styling */
.modal-content h2 {
    cursor: grab;
    user-select: none; /* Prevent text selection during drag */
}

.modal {
    z-index: 1000; /* Ensure modals are on top */
}

/* Styles for metrics display within the modal */
.metrics-display-card {
    background-color: var(--light-bg); /* Assuming --light-bg is defined */
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.metrics-section-header {
    font-size: 1.2em;
    color: var(--dark); /* Assuming --dark is defined */
    border-bottom: 1px solid var(--border-color); /* Assuming --border-color is defined */
    padding-bottom: 10px;
    margin-bottom: 15px;
}

.churn-score-display {
    text-align: center;
    padding: 10px;
    background-color: var(--white); /* Assuming --white is defined */
    border-radius: 5px;
    border: 1px solid var(--border-color);
}

.sparkline-section {
    margin-bottom: 20px;
    background-color: var(--white);
    padding: 15px;
    border-radius: 5px;
    border: 1px solid var(--border-color);
}

.sparkline-label {
    font-weight: bold;
    margin-bottom: 10px;
    color: var(--dark);
}

.sparkline {
    width: 100%;
    height: 100px; /* Adjust height as needed */
}

.sparkline-no-data {
    text-align: center;
    color: var(--text-color-light); /* Assuming --text-color-light is defined */
    padding: 20px;
}

.metrics-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

.metrics-table th, .metrics-table td {
    border: 1px solid var(--border-color);
    padding: 8px;
    text-align: left;
    font-size: 0.9em;
}

.metrics-table th {
    background-color: var(--secondary-bg); /* Assuming --secondary-bg is defined */
    color: var(--dark);
}

.metrics-table tbody tr:nth-child(even) {
    background-color: var(--light-bg);
}

/* Tooltip for sparklines */
#sparklineTooltipModal {
    position: absolute;
    background-color: rgba(0, 0, 0, 0.8);
    color: white;
    padding: 8px 12px;
    border-radius: 5px;
    font-size: 0.8em;
    pointer-events: none; /* Allows clicks to pass through to elements below */
    opacity: 0;
    transition: opacity 0.2s ease-in-out;
    z-index: 1001; /* Above modals */
}

/* Status Badges (copied from explore.php if not already in contacts.css) */
.status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 0.8em;
    font-weight: bold;
    color: white;
    text-transform: capitalize;
    margin-left: 5px;
}

.status-active {
    background-color: var(--success); /* Assuming --success is defined */
}

.status-churned {
    background-color: var(--danger); /* Assuming --danger is defined */
}

.status-resurrected {
    background-color: var(--info); /* Assuming --info is defined */
}
</style>
<link rel="stylesheet" href="assets/css/contacts.css">
<link href="build/nv.d3.css" rel="stylesheet" type="text/css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/3.5.2/d3.min.js" charset="utf-8"></script>
<script src="build/nv.d3.js"></script>


<div class="contacts-container">
    <div class="contacts-header">
        <h1>Contacts Management</h1>

        <div class="contacts-actions">
            <select id="streamFilter">
                <option value="">All Accessible Streams</option>
                <?php foreach ($streams_for_dropdown as $stream): /* Use filtered streams */ ?>
                    <option value="<?= $stream['id'] ?>" <?= $stream['id'] === $selected_stream_id_filter ? 'selected' : '' ?>>
                        <?= htmlspecialchars($stream['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button class="add-contact-btn" id="addContactBtn">Add Contact</button>
            <button class="import-btn" id="importBtn">Import Contacts</button>
        </div>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <div class="contacts-count">
        Showing <?= $total_display_contacts ?> of <?= $current_contacts ?> contacts
        (Limit: <?= $max_contacts ?>, Membership: <span class="membership-level"><?= htmlspecialchars($membership_name) ?></span>)
    </div>

    <div class="progress-container">
        <div class="progress-bar" style="width: <?= min(100, ($current_contacts / $max_contacts) * 100) ?>%"></div>
    </div>

    <?php if (count($contacts)): ?>
        <table class="contacts-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Stream</th>
                    <th>Cohorts</th>
                    <th>Custom Data</th>
                    <th>Added On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contacts as $contact): ?>
                    <tr>
                        <td><?= htmlspecialchars($contact['username'] ?: 'N/A') ?></td>
                        <td><?= htmlspecialchars($contact['email']) ?></td>
                        <td><?= htmlspecialchars($contact['stream_name']) ?></td>
                        <td>
                            <?php if (!empty($contact['cohorts'])): ?>
                                <?= implode(', ', array_map('htmlspecialchars', $contact['cohorts'])) ?>
                            <?php else: ?>
                                None
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $json_data_for_display = '';
                            if (!empty($contact['custom_data'])) {
                                // Prefer the raw JSON string from 'custom_data' column if available
                                $json_data_for_display = $contact['custom_data'];
                            } elseif (!empty($contact['custom_fields_display'])) {
                                // Otherwise, construct JSON from 'custom_fields_display'
                                $temp_custom_fields = [];
                                foreach ($contact['custom_fields_display'] as $field) {
                                    $temp_custom_fields[$field['field_name']] = $field['field_value'];
                                }
                                $json_data_for_display = json_encode($temp_custom_fields);
                            }
                            ?>
                            <?php if (!empty($json_data_for_display)): ?>
                                <span class="custom-data-hover" data-json='<?= htmlspecialchars(json_encode(json_decode($json_data_for_display, true), JSON_PRETTY_PRINT)) ?>'>View JSON</span>
                            <?php else: ?>
                                None
                            <?php endif; ?>
                        </td>
                        <td><?= date('M j, Y', strtotime($contact['created_at'])) ?></td>
                        <td class="contact-actions-cell">
                            <form method="POST" class="delete-form" onsubmit="return confirm('Are you sure you want to delete this contact and all associated data?');">
                                <input type="hidden" name="contact_id" value="<?= $contact['id'] ?>">
                                <button type="submit" name="delete_contact" class="delete-btn">Delete</button>
                            </form>
                            <button class="send-email-btn"
                                data-contact-id="<?= $contact['id'] ?>"
                                data-contact-email="<?= htmlspecialchars($contact['email']) ?>"
                                data-contact-username="<?= htmlspecialchars($contact['username'] ?: '') ?>"
                                data-contact-stream-name="<?= htmlspecialchars($contact['stream_name']) ?>"
                                data-contact-cohort-names="<?= htmlspecialchars(implode(', ', $contact['cohorts'])) ?>"
                                data-contact-custom-fields='<?= json_encode($contact['custom_fields_display']) ?>'
                                >Send Email</button>
                            <button class="view-metrics-btn"
                                data-contact-id="<?= $contact['id'] ?>"
                                data-contact-email="<?= htmlspecialchars($contact['email']) ?>"
                                data-contact-username="<?= htmlspecialchars($contact['username'] ?: '') ?>"
                                >View Metrics</button>
                            <a href="contact_notes.php?contact_id=<?= $contact['id'] ?>" class="view-notes-btn">View Notes</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="no-contacts">
            <p>No contacts found in accessible streams. Add or import contacts to get started.</p>
        </div>
    <?php endif; ?>
</div>

<div class="modal" id="importModal">
    <div class="modal-content">
        <span class="close" data-modal="importModal">&times;</span>
        <h2>Import Contacts</h2>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="importStreamSelect">Select Stream</label>
                <select name="stream_id" id="importStreamSelect" required>
                    <?php foreach ($streams_for_dropdown as $stream): /* Use filtered streams */ ?>
                        <option value="<?= $stream['id'] ?>" <?= $stream['id'] === $selected_stream_id_filter ? 'selected' : '' ?>>
                            <?= htmlspecialchars($stream['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Select Cohorts (Optional)</label>
                <div class="cohort-selector" id="importCohortSelector">
                    <p class="loading-cohorts">Select a stream first</p>
                </div>
            </div>

            <div class="form-group">
                <label for="importCustomData">Custom Data (JSON format)</label>
                <textarea name="custom_data" id="importCustomData" rows="3" placeholder='{"key":"value","key2":"value2"}'></textarea>
                <small>Optional JSON data for custom contact properties.</small>
            </div>

            <div class="form-group">
                <label for="csv_file">File (CSV only)</label>
                <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                <small>File columns: username,email,external_id (optional). Only CSV files are supported without PhpSpreadsheet.</small>
            </div>

            <div class="form-group">
                <label>Remaining Contacts:</label>
                <span><?= $max_contacts - $current_contacts ?></span>
            </div>

            <button type="submit" name="import_contacts" class="submit-btn">Import</button>
        </form>
    </div>
</div>

<div class="modal" id="addContactModal">
    <div class="modal-content">
        <span class="close" data-modal="addContactModal">&times;</span>
        <h2>Add New Contact</h2>

        <form method="POST">
            <div class="form-group">
                <label for="addUsername">Username</label>
                <input type="text" name="username" id="addUsername" placeholder="Optional">
            </div>

            <div class="form-group">
                <label for="addEmail">Email*</label>
                <input type="email" name="email" id="addEmail" required>
            </div>

            <div class="form-group">
                <label for="addExternalId">External ID</label>
                <input type="text" name="external_id" id="addExternalId" placeholder="Optional">
            </div>

            <div class="form-group">
                <label for="addContactStreamSelect">Select Stream*</label>
                <select name="stream_id" id="addContactStreamSelect" required>
                    <?php foreach ($streams_for_dropdown as $stream): /* Use filtered streams */ ?>
                        <option value="<?= $stream['id'] ?>" <?= $stream['id'] === $selected_stream_id_filter ? 'selected' : '' ?>>
                            <?= htmlspecialchars($stream['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Select Cohorts (Optional)</label>
                <div class="cohort-selector" id="addContactCohortSelector">
                    <p class="loading-cohorts">Select a stream first</p>
                </div>
            </div>

            <div class="form-group">
                <label for="addCustomData">Custom Data (JSON format)</label>
                <textarea name="custom_data" id="addCustomData" rows="3" placeholder='{"key":"value","key2":"value2"}'></textarea>
                <small>Optional JSON data for custom contact properties.</small>
            </div>

            <div class="form-group">
                <label>Remaining Contacts:</label>
                <span><?= $max_contacts - $current_contacts ?></span>
            </div>

            <button type="submit" name="add_contact" class="submit-btn">Add Contact</button>
        </form>
    </div>
</div>

<div class="modal" id="sendEmailModal">
    <div class="modal-content">
        <span class="close" data-modal="sendEmailModal">&times;</span>
        <h2>Send Email to <span id="contactEmailDisplay"></span></h2>

        <form method="POST" id="sendEmailForm">
            <input type="hidden" name="contact_id" id="sendEmailContactId">

            <div class="form-group">
                <label for="emailTemplateSelect">Select Template (Optional)</label>
                <select id="emailTemplateSelect">
                    <option value="">-- Create New Email --</option>
                    </select>
            </div>

            <div class="form-group">
                <label for="senderName">Sender Name*</label>
                <input type="text" name="sender_name" id="senderName" required>
            </div>

            <div class="form-group">
                <label for="emailSubject">Subject*</label>
                <input type="text" name="email_subject" id="emailSubject" required>
            </div>

            <div class="form-group">
                <label for="emailContent">Email Content (HTML Supported)*</label>
                <textarea name="email_content" id="emailContent" class="email-content-html-editor" required placeholder="Enter your HTML email content here."></textarea>
                <small>You can use HTML tags directly. Available placeholders: <code>{username}</code>, <code>{email}</code>, <code>{stream_name}</code>, <code>{cohort_name}</code>, <code>{stream_id}</code>. For custom fields, use <code>{field_name}</code> (e.g., <code>{phone_number}</code>).</small>
            </div>

            <button type="submit" name="send_email_to_contact" class="submit-btn">Send Email</button>
        </form>
    </div>
</div>

<div class="modal" id="jsonViewerModal">
    <div class="modal-content">
        <span class="close" data-modal="jsonViewerModal">&times;</span>
        <h2>Custom Data</h2>
        <pre id="jsonDisplay"></pre>
    </div>
</div>

<div class="modal" id="metricsViewerModal">
    <div class="modal-content">
        <span class="close" data-modal="metricsViewerModal">&times;</span>
        <h2>Metrics Data for <span id="metricsContactDisplay"></span></h2>
        <div id="metricsContent">
            <p>Loading metrics...</p>
        </div>
    </div>
</div>

<div id="sparklineTooltipModal"></div>


<script>
    // --- PHP-provided data for JavaScript ---
    const ALL_EMAIL_TEMPLATES = <?= json_encode($user_email_templates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const ALL_COHORTS_BY_STREAM = <?= json_encode($user_cohorts_by_stream, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;


    // --- Global Modal Functionality ---
    const modals = document.querySelectorAll('.modal');
    const closeBtns = document.querySelectorAll('.modal .close');

    // Attach close functionality to all close buttons
    closeBtns.forEach(button => {
        button.addEventListener('click', function() {
            const modalId = this.dataset.modal;
            document.getElementById(modalId).style.display = 'none';
            // Reset modal position and scroll when closed
            const modalContent = document.getElementById(modalId).querySelector('.modal-content');
            if (modalContent) {
                   modalContent.style.transform = "translate3d(0px, 0px, 0)";
                   modalContent.scrollTop = 0; // Reset scroll position
            }
        });
    });

    // Close modal when clicking outside
    window.addEventListener('click', (e) => {
        modals.forEach(modal => {
            if (e.target === modal) {
                modal.style.display = 'none';
                // Reset modal position and scroll when clicking outside
                const modalContent = modal.querySelector('.modal-content');
                if (modalContent) {
                    modalContent.style.transform = "translate3d(0px, 0px, 0)";
                    modalContent.scrollTop = 0; // Reset scroll position
                }
            }
        });
    });

    // --- Draggable Modal Logic ---
    modals.forEach(modal => {
        const modalContent = modal.querySelector('.modal-content');
        // Check if modal-content exists, as the modal itself might be clicked
        if (!modalContent) return;

        const header = modalContent.querySelector('h2'); // Assumes h2 is the draggable handle

        // Initialize position variables for each modal
        modalContent.style.transform = "translate3d(0px, 0px, 0)";
        let xOffset = 0;
        let yOffset = 0;

        if (header) {
            let isDragging = false;
            let initialX;
            let initialY;

            header.addEventListener('mousedown', dragStart);

            function dragStart(e) {
                initialX = e.clientX - xOffset;
                initialY = e.clientY - yOffset;

                // Only start dragging if the primary mouse button is pressed (e.button === 0)
                if (e.button === 0) {
                    isDragging = true;
                    header.style.cursor = 'grabbing';
                    // Add event listeners to the document to ensure drag works even if mouse leaves modal header
                    document.addEventListener('mousemove', drag);
                    document.addEventListener('mouseup', dragEnd);
                }
            }

            function drag(e) {
                if (isDragging) {
                    e.preventDefault(); // Prevent text selection etc.
                    currentX = e.clientX - initialX;
                    currentY = e.clientY - initialY;

                    xOffset = currentX;
                    yOffset = currentY;

                    setTranslate(currentX, currentY, modalContent);
                }
            }

            function dragEnd() {
                isDragging = false;
                header.style.cursor = 'grab';
                // Remove document-wide listeners
                document.removeEventListener('mousemove', drag);
                document.removeEventListener('mouseup', dragEnd);
            }

            function setTranslate(xPos, yPos, el) {
                el.style.transform = "translate3d(" + xPos + "px, " + yPos + "px, 0)";
            }
        }
    });

    // --- Page Specific Logic ---
    const streamFilter = document.getElementById('streamFilter');
    const importBtn = document.getElementById('importBtn');
    const addContactBtn = document.getElementById('addContactBtn');
    const sendEmailBtns = document.querySelectorAll('.send-email-btn');
    const viewMetricsBtns = document.querySelectorAll('.view-metrics-btn'); // New metrics button selector
    const customDataHovers = document.querySelectorAll('.custom-data-hover');


    // Stream filter
    streamFilter.addEventListener('change', function() {
        const streamId = this.value;
        window.location.href = `contacts.php${streamId ? '?stream_id=' + streamId : ''}`;
    });

    // Open Modals
    importBtn.addEventListener('click', () => { importModal.style.display = 'block'; });
    addContactBtn.addEventListener('click', () => { addContactModal.style.display = 'block'; });

    // Event listener for "Send Email" buttons
    sendEmailBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const contactId = this.dataset.contactId;
            const contactEmail = this.dataset.contactEmail;
            const contactUsername = this.dataset.contactUsername;
            const contactStreamName = this.dataset.contactStreamName;
            const contactCohortNames = this.dataset.contactCohortNames;
            const contactCustomFields = JSON.parse(this.dataset.contactCustomFields || '[]');

            document.getElementById('sendEmailContactId').value = contactId;
            document.getElementById('contactEmailDisplay').textContent = contactUsername ? `${contactUsername} (${contactEmail})` : contactEmail; // Display username or email

            // Clear previous template selection and form fields
            document.getElementById('emailTemplateSelect').value = '';
            document.getElementById('senderName').value = '';
            document.getElementById('emailSubject').value = '';
            document.getElementById('emailContent').value = '';

            // Store contact details for placeholder replacement
            sendEmailModal.dataset.contactUsername = contactUsername;
            sendEmailModal.dataset.contactEmail = contactEmail;
            sendEmailModal.dataset.contactStreamName = contactStreamName;
            sendEmailModal.dataset.contactCohortNames = contactCohortNames;
            sendEmailModal.dataset.contactCustomFields = JSON.stringify(contactCustomFields);

            populateEmailTemplates(); // Populate templates from ALL_EMAIL_TEMPLATES
            sendEmailModal.style.display = 'block';
            // Reset modal position when opened
            const modalContent = sendEmailModal.querySelector('.modal-content');
            modalContent.style.transform = "translate3d(0px, 0px, 0)";
            modalContent.scrollTop = 0; // Reset scroll position
        });
    });

    // --- Sparkline Initialization Function (Adapted from explore.php) ---
    // Make sure sparklineTooltipModal is appended to body (done in HTML)
    const sparklineTooltipModal = document.getElementById('sparklineTooltipModal');

    function initSparkline(containerId, data, lineColor = 'var(--primary)') {
        // Remove existing SVG content if any
        d3.select(containerId).select("svg").remove();

        const hasData = data && data.length > 0 && data.some(point => point.y !== 0);

        if (!hasData) {
            d3.select(containerId).html('<div class="sparkline-no-data">No data yet.</div>');
            return;
        }

        try {
            nv.addGraph(function() {
                var chart = nv.models.sparklinePlus()
                    .margin({ left: 30, right: 30 }) // Adjusted margins
                    .x(function(d, i) { return d.x; })
                    .y(function(d) { return d.y; })
                    .showLastValue(true)
                    .showCurrentValue(false) // Only show last value
                    .showMinMax(false)
                    .xScale(d3.time.scale())
                    .xTickFormat(function(d) { return d3.time.format('%b %d')(new Date(d)); })
                    .color([lineColor]);

                d3.select(containerId)
                    .datum([{values: data}])
                    .call(chart);

                // Attach tooltip behavior
                d3.select(containerId).selectAll('.nv-point').on('mouseover', function(d, i) {
                    const rect = this.getBoundingClientRect();
                    sparklineTooltipModal.innerHTML = `Value: ${d.y.toFixed(1)} <br> Date: ${d3.time.format('%b %d, %Y')(new Date(d.x))}`;
                    sparklineTooltipModal.style.left = (rect.left + window.scrollX + rect.width / 2) - (sparklineTooltipModal.offsetWidth / 2) + 'px';
                    sparklineTooltipModal.style.top = (rect.top + window.scrollY - sparklineTooltipModal.offsetHeight - 10) + 'px';
                    sparklineTooltipModal.style.opacity = '1';
                }).on('mouseout', function() {
                    sparklineTooltipModal.style.opacity = '0';
                });

                d3.select(containerId).selectAll('.nv-point.nv-lastValue') // Only target last value if you want it
                    .style('fill', 'var(--dark)') // Color of the last value point
                    .style('stroke', 'var(--white)')
                    .style('stroke-width', '2px')
                    .attr('r', 5); // Radius of the point

                return chart;
            });
        } catch (error) {
            console.error(`Error rendering sparkline for ${containerId}:`, error);
            d3.select(containerId).html(`<div class="sparkline-no-data">Error rendering chart: ${escapeHtml(error.message)}</div>`);
        }
    }


    // Event listener for "View Metrics" buttons
    viewMetricsBtns.forEach(btn => {
        btn.addEventListener('click', async function() {
            const contactId = this.dataset.contactId;
            const contactEmail = this.dataset.contactEmail;
            const contactUsername = this.dataset.contactUsername; // Corrected to use contactUsername
            const metricsContactDisplay = document.getElementById('metricsContactDisplay');
            const metricsContentDiv = document.getElementById('metricsContent'); // Target for dynamic content

            metricsContactDisplay.textContent = contactUsername ? `${contactUsername} (${contactEmail})` : contactEmail;
            metricsContentDiv.innerHTML = '<p>Loading metrics...</p>'; // Show loading message
            metricsViewerModal.style.display = 'block'; // Open modal

            // Reset modal position and scroll when opened
            const modalContent = metricsViewerModal.querySelector('.modal-content');
            modalContent.style.transform = "translate3d(0px, 0px, 0)";
            modalContent.scrollTop = 0; // Reset scroll position
            
            try {
                const response = await fetch('contacts.php', { // Send AJAX request to current page
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=get_contact_metrics&contact_id=${contactId}`
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    throw new Error(`HTTP error! Status: ${response.status} - ${response.statusText}. Response: ${errorText.substring(0, 500)}...`); // Log more of the response
                }

                // Check if the response is actually JSON before parsing
                const contentType = response.headers.get("content-type");
                if (contentType && contentType.indexOf("application/json") !== -1) {
                    const result = await response.json();
                    console.log("Metrics API Response:", result); // Debugging: Check the full response

                    if (result.success) {
                        let displayHtml = '';
                        const contactData = result; // The entire result object contains the data we need

                        // Section 1: Contact General Info (mimicking details from explore.php card)
                        displayHtml += `<div class="metrics-display-card">
                                            <h3 class="metrics-section-header">General Info</h3>
                                            <p><strong>Username:</strong> ${escapeHtml(contactUsername || 'N/A')}</p>
                                            <p><strong>Email:</strong> ${escapeHtml(contactEmail)}</p>
                                            <p><strong>Stream:</strong> ${escapeHtml(contactData.stream_name || 'N/A')}</p> 
                                            <p><strong>Cohorts:</strong> ${escapeHtml(contactData.cohort_names.join(', ') || 'None')}</p>
                                            <p><strong>Status:</strong> `;
                        if (contactData.is_resurrected) {
                            displayHtml += `<span class="status-badge status-resurrected">Resurrected</span>`;
                        } else if (contactData.is_churned) {
                            displayHtml += `<span class="status-badge status-churned">Churned</span>`;
                        } else {
                            displayHtml += `<span class="status-badge status-active">Active</span>`;
                        }
                        displayHtml += `</p>`;
                        
                        // Add Custom Fields
                        if (contactData.custom_fields_data && contactData.custom_fields_data.length > 0) {
                            displayHtml += `<p><strong>Custom Fields:</strong></p><ul>`;
                            contactData.custom_fields_data.forEach(field => {
                                displayHtml += `<li><strong>${escapeHtml(field.field_name)}:</strong> ${escapeHtml(field.field_value)}</li>`;
                            });
                            displayHtml += `</ul>`;
                        } else {
                            displayHtml += `<p>No custom fields found.</p>`;
                        }
                        displayHtml += `</div><br>`;


                        // Section 2: Churn Score
                        displayHtml += `<div class="metrics-display-card">
                                                <div class="churn-score-display">
                                                    <strong>Latest Churn Score:</strong> ${escapeHtml(contactData.churn_score ? contactData.churn_score.score + '%' : 'N/A')} <br>
                                                    <small>Scored on: ${escapeHtml(contactData.churn_score ? new Date(contactData.churn_score.scored_at).toLocaleString() : 'N/A')}</small> <br>
                                                    <small>Model: ${escapeHtml(contactData.churn_score ? contactData.churn_score.model_used : 'N/A')}</small>`;
                        if (contactData.churn_score && contactData.churn_score.report) {
                            displayHtml += `<br><small>Report: ${escapeHtml(contactData.churn_score.report).substring(0, 150)}...</small>`;
                        }
                        displayHtml += `</div></div><br>`;

                        // Section 3: Sparklines
                        displayHtml += `<div class="metrics-display-card">
                                            <h3 class="metrics-section-header">Trends (Last 30 Days)</h3>
                                            <div class="sparkline-section">
                                                <div class="sparkline-label">Churn Probability</div>
                                                <svg id="modal-churn-sparkline-${contactId}" class="sparkline"></svg>
                                            </div>
                                            <div class="sparkline-section">
                                                <div class="sparkline-label">Competitor Visits</div>
                                                <svg id="modal-competitor-sparkline-${contactId}" class="sparkline"></svg>
                                            </div>
                                            <div class="sparkline-section">
                                                <div class="sparkline-label">Feature Usage</div>
                                                <svg id="modal-feature-sparkline-${contactId}" class="sparkline"></svg>
                                            </div>
                                        </div><br>`;
                        
                        // Section 4: All Individual Metrics
                        if (contactData.all_individual_metrics && contactData.all_individual_metrics.length > 0) {
                            displayHtml += `<div class="metrics-display-card">
                                                    <h3 class="metrics-section-header">All Recorded Metrics</h3>
                                                    <table class="metrics-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Metric Name</th>
                                                                <th>Category</th>
                                                                <th>Value</th>
                                                                <th>Source</th>
                                                                <th>Recorded At</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>`;
                            contactData.all_individual_metrics.forEach(metric => {
                                displayHtml += `
                                            <tr>
                                                <td>${escapeHtml(metric.metric_name)}</td>
                                                <td>${escapeHtml(metric.metric_category)}</td>
                                                <td>${escapeHtml(metric.value)}</td>
                                                <td>${escapeHtml(metric.source)}</td>
                                                <td>${new Date(metric.recorded_at).toLocaleString()}</td>
                                            </tr>
                                        `;
                            });
                            displayHtml += '</tbody></table></div>';
                        } else {
                            displayHtml += '<p>No individual metrics data found for this contact.</p>';
                        }
                        
                        metricsContentDiv.innerHTML = displayHtml;

                        // Initialize sparklines AFTER HTML is in the DOM
                        initSparkline(`#modal-churn-sparkline-${contactId}`, contactData.churn_scores_trend, 'var(--danger)');
                        initSparkline(`#modal-competitor-sparkline-${contactId}`, contactData.competitor_visits_trend, 'var(--info)');
                        initSparkline(`#modal-feature-sparkline-${contactId}`, contactData.feature_usage_trend, 'var(--success)');

                    } else {
                        metricsContentDiv.innerHTML = `<p class="alert error">Error: ${escapeHtml(result.error || 'Failed to load metrics.')}</p>`;
                    }
                } else {
                    // Not JSON, or other unexpected response.
                    metricsContentDiv.innerHTML = `<p class="alert error">Error: Received unexpected response from server. Check console.</p>`;
                    console.error("Received non-JSON response:", errorText);
                }
            } catch (error) {
                console.error('Error fetching metrics:', error);
                metricsContentDiv.innerHTML = `<p class="alert error">An unexpected error occurred: ${escapeHtml(error.message)}. Please check console for details.</p>`;
            }
        });
    });


    // Event listener for "View JSON" spans
    customDataHovers.forEach(span => {
        span.addEventListener('click', function() {
            const jsonData = this.dataset.json;
            const jsonDisplayElement = document.getElementById('jsonDisplay');
            try {
                // Parse and re-stringify with pretty print for display
                const parsedJson = JSON.parse(jsonData);
                jsonDisplayElement.textContent = JSON.stringify(parsedJson, null, 2);
            } catch (e) {
                // If parsing fails, display raw data with an error
                jsonDisplayElement.textContent = `Error parsing JSON for display: ${e.message}\n\nRaw Data:\n${jsonData}`;
                console.error("Error parsing JSON for JSON viewer:", e, "Raw data:", jsonData);
            }
            jsonViewerModal.style.display = 'block';
            // Reset modal position when opened
            const modalContent = jsonViewerModal.querySelector('.modal-content');
            modalContent.style.transform = "translate3d(0px, 0px, 0)";
            modalContent.scrollTop = 0; // Reset scroll position
        });
    });


    // Cohort selector population function
    function populateCohorts(streamId, targetElement) {
        targetElement.innerHTML = ''; // Clear previous cohorts

        if (!streamId) {
            targetElement.innerHTML = '<p class="loading-cohorts">Select a stream first</p>';
            return;
        }

        const cohortsForStream = ALL_COHORTS_BY_STREAM[streamId];

        if (cohortsForStream && cohortsForStream.length > 0) {
            let html = '';
            cohortsForStream.forEach(cohort => {
                html += `
                    <div class="cohort-checkbox">
                        <label>
                            <input type="checkbox" name="cohorts[]" value="${cohort.id}">
                            ${escapeHtml(cohort.name)}
                        </label>
                    </div>
                `;
            });
            targetElement.innerHTML = html;
        } else {
            targetElement.innerHTML = '<p class="loading-cohorts">No cohorts available for this stream.</p>';
        }
    }

    // Set up cohort selectors to use PHP-provided data
    document.getElementById('importStreamSelect').addEventListener('change', function() {
        populateCohorts(this.value, document.getElementById('importCohortSelector'));
    });

    document.getElementById('addContactStreamSelect').addEventListener('change', function() {
        populateCohorts(this.value, document.getElementById('addContactCohortSelector'));
    });

    // Initial call to set cohort visibility on page load if a stream is preselected
    document.addEventListener('DOMContentLoaded', function() {
        const initialImportStreamId = document.getElementById('importStreamSelect').value;
        if (initialImportStreamId) {
            populateCohorts(initialImportStreamId, document.getElementById('importCohortSelector'));
        }
        const initialAddStreamId = document.getElementById('addContactStreamSelect').value;
        if (initialAddStreamId) {
            populateCohorts(initialAddStreamId, document.getElementById('addContactCohortSelector'));
        }
    });


    // Helper function to escape HTML for display
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Function to populate email templates from PHP-provided data
    function populateEmailTemplates() {
        const templateSelect = document.getElementById('emailTemplateSelect');
        templateSelect.innerHTML = '<option value="">-- Create New Email --</option>'; // Reset options

        if (ALL_EMAIL_TEMPLATES.length > 0) {
            ALL_EMAIL_TEMPLATES.forEach(template => {
                const option = document.createElement('option');
                option.value = template.id;
                option.textContent = escapeHtml(template.name);
                // Store template data as dataset attributes for easy access
                option.dataset.subject = template.subject;
                option.dataset.content = template.content; // HTML content
                option.dataset.senderName = template.sender_name;
                templateSelect.appendChild(option);
            });
        } else {
            templateSelect.innerHTML += '<option disabled>No templates found.</option>';
        }
    }

    // Event listener for template selection
    document.getElementById('emailTemplateSelect').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const subjectField = document.getElementById('emailSubject');
        const senderNameField = document.getElementById('senderName');
        const emailContentField = document.getElementById('emailContent'); // Now a textarea

        if (selectedOption.value === '') {
            // "Create New Email" selected, clear fields
            senderNameField.value = '';
            subjectField.value = '';
            emailContentField.value = ''; // Clear textarea content
        } else {
            // Template selected, populate fields and apply placeholders
            let templateSubject = selectedOption.dataset.subject || '';
            let templateContent = selectedOption.dataset.content || '';
            let templateSenderName = selectedOption.dataset.senderName || '';

            // Retrieve contact data from the modal's dataset attributes
            const contactUsername = sendEmailModal.dataset.contactUsername;
            const contactEmail = sendEmailModal.dataset.contactEmail;
            const contactStreamName = sendEmailModal.dataset.contactStreamName;
            const contactCohortNames = sendEmailModal.dataset.contactCohortNames;
            const contactCustomFields = JSON.parse(sendEmailModal.dataset.contactCustomFields || '[]');

            // Define standard placeholders and their values
            let placeholders = {
                '{username}': contactUsername,
                '{email}': contactEmail,
                '{stream_name}': contactStreamName,
                '{cohort_name}': contactCohortNames
            };

            // Add custom fields as placeholders, e.g., {phone_number}, {user_id_from_crm}
            contactCustomFields.forEach(field => {
                placeholders[`{${field.field_name}}`] = field.field_value;
            });

            // Replace placeholders in subject and content
            for (const placeholder in placeholders) {
                const value = placeholders[placeholder];
                if (value !== null && value !== undefined) {
                    const regex = new RegExp(escapeRegExp(placeholder), 'g');
                    templateSubject = templateSubject.replace(regex, value);
                    templateContent = templateContent.replace(regex, value);
                }
            }

            senderNameField.value = templateSenderName;
            subjectField.value = templateSubject;
            emailContentField.value = templateContent; // Set content directly to textarea
        }
    });

    // Helper function to escape special characters for regex
    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); // $& means the matched substring
    }
</script>

<?php
require_once 'includes/footer.php';
?>